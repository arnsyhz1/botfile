<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $config['db']['host'],
    $config['db']['name']
);

$pdo = new PDO(
    $dsn,
    $config['db']['user'],
    $config['db']['pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

require_once __DIR__ . '/src/Queue/DatabaseQueue.php';
require_once __DIR__ . '/src/Worker/LegacyBotWorker.php';
