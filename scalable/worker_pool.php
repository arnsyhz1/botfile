<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$poolSize = max(1, (int)$config['worker_pool']['size']);
$children = [];
$workerScript = __DIR__ . '/worker.php';

fwrite(STDOUT, "[pool] starting {$poolSize} workers\n");

for ($i = 1; $i <= $poolSize; $i++) {
    $cmd = sprintf('php %s', escapeshellarg($workerScript));
    $proc = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);

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
            fwrite(STDERR, "[pool] worker-{$child['index']} exited, restarting...\n");
            proc_close($child['process']);

            $cmd = sprintf('php %s', escapeshellarg($workerScript));
            $proc = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);

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
