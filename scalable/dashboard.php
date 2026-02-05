<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$queue = new DatabaseQueue($pdo, $config['queue']['table']);
$queue->ensureSchema();

$token = $config['monitor']['token'];
$tokenFromRequest = (string)($_GET['token'] ?? '');

if ($token !== '' && !hash_equals($token, $tokenFromRequest)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry_failed') {
    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($jobId > 0) {
        $queue->retryFailedJob($jobId);
    }

    $redirect = '/scalable/dashboard.php';
    if ($token !== '') {
        $redirect .= '?token=' . urlencode($token);
    }

    header('Location: ' . $redirect);
    exit;
}

$stats = $queue->getStats();
$jobs = $queue->getRecentJobs((int)$config['queue']['monitor_limit']);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Dashboard</title>
  <style>
    body{font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:16px}
    h1{margin-top:0}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin:12px 0}
    .card{background:#1e293b;border-radius:10px;padding:12px}
    .label{font-size:12px;color:#94a3b8}
    .value{font-size:24px;font-weight:700}
    table{width:100%;border-collapse:collapse;background:#111827}
    th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:12px;vertical-align:top}
    th{background:#1f2937;text-align:left}
    .ok{color:#22c55e}.warn{color:#f59e0b}.bad{color:#ef4444}
    button{background:#2563eb;color:#fff;border:0;padding:6px 10px;border-radius:6px;cursor:pointer}
    .small{font-size:11px;color:#94a3b8}
  </style>
</head>
<body>
  <h1>Queue Monitoring Dashboard</h1>
  <p class="small">Auto refresh 5 detik. Total menampilkan <?= count($jobs) ?> job terbaru.</p>

  <div class="cards">
    <div class="card"><div class="label">Pending</div><div class="value warn"><?= $stats['pending'] ?></div></div>
    <div class="card"><div class="label">Processing</div><div class="value"><?= $stats['processing'] ?></div></div>
    <div class="card"><div class="label">Done</div><div class="value ok"><?= $stats['done'] ?></div></div>
    <div class="card"><div class="label">Failed</div><div class="value bad"><?= $stats['failed'] ?></div></div>
    <div class="card"><div class="label">Total</div><div class="value"><?= $stats['total'] ?></div></div>
  </div>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Status</th>
        <th>Attempts</th>
        <th>Available</th>
        <th>Reserved</th>
        <th>Processed</th>
        <th>Error</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($jobs as $job): ?>
      <tr>
        <td><?= (int)$job['id'] ?></td>
        <td><?= htmlspecialchars((string)$job['status']) ?></td>
        <td><?= (int)$job['attempts'] ?></td>
        <td><?= htmlspecialchars((string)$job['available_at']) ?></td>
        <td><?= htmlspecialchars((string)$job['reserved_at']) ?></td>
        <td><?= htmlspecialchars((string)$job['processed_at']) ?></td>
        <td><?= htmlspecialchars(mb_strimwidth((string)$job['error_message'], 0, 140, '…')) ?></td>
        <td>
          <?php if ($job['status'] === 'failed'): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="retry_failed">
            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
            <button type="submit">Retry</button>
          </form>
          <?php else: ?>
            -
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <script>
    setTimeout(() => window.location.reload(), 5000);
  </script>
</body>
</html>
