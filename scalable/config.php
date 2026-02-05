<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('BOT_DB_HOST') ?: 'localhost',
        'name' => getenv('BOT_DB_NAME') ?: 'u386246510_akayfile',
        'user' => getenv('BOT_DB_USER') ?: 'u386246510_akayfile',
        'pass' => getenv('BOT_DB_PASS') ?: 'Jndpusat2023',
    ],
    'queue' => [
        'table' => getenv('BOT_QUEUE_TABLE') ?: 'bot_jobs',
        'worker_sleep_seconds' => (int)(getenv('BOT_WORKER_SLEEP') ?: 1),
        'max_attempts' => (int)(getenv('BOT_JOB_MAX_ATTEMPTS') ?: 6),
        'retry_base_seconds' => (int)(getenv('BOT_JOB_RETRY_BASE') ?: 5),
        'retry_max_seconds' => (int)(getenv('BOT_JOB_RETRY_MAX') ?: 300),
        'monitor_limit' => (int)(getenv('BOT_MONITOR_LIMIT') ?: 100),
    ],
    'monitor' => [
        'token' => getenv('BOT_MONITOR_TOKEN') ?: '',
    ],
    'worker_pool' => [
        'size' => (int)(getenv('BOT_WORKER_POOL_SIZE') ?: 4),
    ],
    // Biarkan default ke bot lama agar kompatibel.
    'legacy_bot_path' => getenv('BOT_LEGACY_PATH') ?: dirname(__DIR__) . '/bot.php',
];
