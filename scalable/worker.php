<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$queue = new DatabaseQueue($pdo, $config['queue']['table']);
$queue->ensureSchema();

$worker = new LegacyBotWorker($config['legacy_bot_path']);
$maxAttempts = $config['queue']['max_attempts'];
$baseRetry = $config['queue']['retry_base_seconds'];
$maxRetry = $config['queue']['retry_max_seconds'];
$sleep = $config['queue']['worker_sleep_seconds'];

fwrite(STDOUT, "[worker] started. queue={$config['queue']['table']} legacy={$config['legacy_bot_path']}\n");

while (true) {
    $job = $queue->pop($maxAttempts);

    if ($job === null) {
        sleep($sleep);
        continue;
    }

    $jobId = (int)$job['id'];

    try {
        $worker->handle((string)$job['payload']);
        $queue->markDone($jobId);
        fwrite(STDOUT, "[worker] job #{$jobId} done\n");
    } catch (Throwable $e) {
        $attempts = (int)$job['attempts'];
        $nextDelay = $queue->releaseWithExponentialBackoff(
            $jobId,
            $e->getMessage(),
            $attempts,
            $maxAttempts,
            $baseRetry,
            $maxRetry
        );

        if ($attempts >= $maxAttempts) {
            fwrite(STDERR, "[worker] job #{$jobId} failed permanently (attempt {$attempts}/{$maxAttempts}): {$e->getMessage()}\n");
            continue;
        }

        fwrite(STDERR, "[worker] job #{$jobId} error (attempt {$attempts}/{$maxAttempts}), retry in {$nextDelay}s: {$e->getMessage()}\n");
    }
}
