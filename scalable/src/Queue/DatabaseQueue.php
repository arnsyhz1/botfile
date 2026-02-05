<?php

declare(strict_types=1);

final class DatabaseQueue
{
    public function __construct(
        private PDO $pdo,
        private string $tableName
    ) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->tableName)) {
            throw new InvalidArgumentException('Nama table queue tidak valid.');
        }
    }

    public function ensureSchema(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                payload LONGTEXT NOT NULL,
                status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reserved_at DATETIME NULL,
                processed_at DATETIME NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status_available (status, available_at),
                INDEX idx_status_created (status, created_at),
                INDEX idx_reserved (reserved_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";

        $this->pdo->exec($sql);
    }

    public function push(string $payload): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->tableName} (payload, status, available_at) VALUES (:payload, 'pending', NOW())"
        );
        $stmt->execute([':payload' => $payload]);

        return (int)$this->pdo->lastInsertId();
    }

    public function pop(int $maxAttempts): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, payload, attempts
                 FROM {$this->tableName}
                 WHERE status = 'pending'
                   AND attempts < :maxAttempts
                   AND available_at <= NOW()
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([':maxAttempts' => $maxAttempts]);

            $job = $stmt->fetch();
            if (!$job) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE {$this->tableName}
                 SET status = 'processing', attempts = attempts + 1, reserved_at = NOW()
                 WHERE id = :id"
            );
            $update->execute([':id' => $job['id']]);

            $this->pdo->commit();

            $job['id'] = (int)$job['id'];
            $job['attempts'] = (int)$job['attempts'] + 1;
            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function markDone(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->tableName}
             SET status = 'done', processed_at = NOW(), error_message = NULL
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    public function releaseWithExponentialBackoff(
        int $id,
        string $errorMessage,
        int $attempts,
        int $maxAttempts,
        int $baseRetrySeconds,
        int $maxRetrySeconds
    ): int {
        $status = $attempts >= $maxAttempts ? 'failed' : 'pending';
        $nextDelaySeconds = 0;

        if ($status === 'pending') {
            $expFactor = max(0, $attempts - 1);
            $computed = $baseRetrySeconds * (2 ** $expFactor);
            $nextDelaySeconds = (int)min($computed, $maxRetrySeconds);
        }

        $sql = "UPDATE {$this->tableName}
                SET status = :status,
                    error_message = :error,
                    available_at = " . ($status === 'pending' ? 'DATE_ADD(NOW(), INTERVAL :retryDelay SECOND)' : 'NOW()') . ",
                    reserved_at = NULL,
                    processed_at = " . ($status === 'failed' ? 'NOW()' : 'NULL') . "
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $params = [
            ':status' => $status,
            ':error' => mb_substr($errorMessage, 0, 65000),
            ':id' => $id,
        ];

        if ($status === 'pending') {
            $params[':retryDelay'] = $nextDelaySeconds;
        }

        $stmt->execute($params);

        return $nextDelaySeconds;
    }


    public function retryFailedJob(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->tableName}
             SET status = 'pending',
                 available_at = NOW(),
                 reserved_at = NULL,
                 processed_at = NULL,
                 error_message = NULL
             WHERE id = :id AND status = 'failed'"
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function getStats(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                SUM(status = 'pending') AS pending,
                SUM(status = 'processing') AS processing,
                SUM(status = 'done') AS done,
                SUM(status = 'failed') AS failed,
                COUNT(*) AS total
            FROM {$this->tableName}"
        );

        $row = $stmt->fetch() ?: [];

        return [
            'pending' => (int)($row['pending'] ?? 0),
            'processing' => (int)($row['processing'] ?? 0),
            'done' => (int)($row['done'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'total' => (int)($row['total'] ?? 0),
        ];
    }

    public function getRecentJobs(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->pdo->query(
            "SELECT id, status, attempts, available_at, reserved_at, processed_at, created_at, updated_at, error_message
             FROM {$this->tableName}
             ORDER BY id DESC
             LIMIT {$limit}"
        );

        return $stmt->fetchAll() ?: [];
    }
}
