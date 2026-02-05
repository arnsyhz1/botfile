<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

$poolSize = max(1, (int)$config['worker_pool']['size']);
$children = [];
$workerScript = __DIR__ . '/worker.php';

$descriptorSpec = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', 'php://stdout', 'a'],
    2 => ['file', 'php://stderr', 'a'],
];

fwrite(STDOUT, "[pool] starting {$poolSize} workers\n");

$startWorker = static function (string $workerScript, array $descriptorSpec): mixed {
    $cmd = sprintf('php %s', escapeshellarg($workerScript));
    return proc_open($cmd, $descriptorSpec, $pipes);
};

for ($i = 1; $i <= $poolSize; $i++) {
    $proc = $startWorker($workerScript, $descriptorSpec);

    if (!is_resource($proc)) {
        fwrite(STDERR, "[pool] gagal start worker-{$i}\n");
        continue;
    }

    $children[] = [
        'index' => $i,
        'process' => $proc,
    ];

    fwrite(STDOUT, "[pool] worker-{$i} started\n");
}

if (empty($children)) {
    fwrite(STDERR, "[pool] tidak ada worker yang berhasil dijalankan\n");
    exit(1);
}

while (true) {
    foreach ($children as $k => $child) {
        $status = proc_get_status($child['process']);
        if (!$status['running']) {
            fwrite(STDERR, "[pool] worker-{$child['index']} exited ({$status['exitcode']}), restarting...\n");
            proc_close($child['process']);

            $proc = $startWorker($workerScript, $descriptorSpec);
            if (!is_resource($proc)) {
                fwrite(STDERR, "[pool] worker-{$child['index']} restart gagal\n");
                unset($children[$k]);
                continue;
            }

            $children[$k]['process'] = $proc;
            fwrite(STDOUT, "[pool] worker-{$child['index']} restarted\n");
        }
    }

    if (empty($children)) {
        fwrite(STDERR, "[pool] semua worker mati\n");
        exit(1);
    }

    sleep(1);
}
