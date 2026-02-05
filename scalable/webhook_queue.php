<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$queue = new DatabaseQueue($pdo, $config['queue']['table']);
$queue->ensureSchema();

$rawUpdate = file_get_contents('php://input') ?: '';
if ($rawUpdate === '') {
    http_response_code(400);
    echo 'empty update';
    exit;
}

$decoded = json_decode($rawUpdate, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo 'invalid json';
    exit;
}

$jobId = $queue->push($rawUpdate);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'queued_job_id' => $jobId,
]);
