<?php

declare(strict_types=1);

final class LegacyBotWorker
{
    public function __construct(private string $legacyBotPath)
    {
    }

    public function handle(string $telegramUpdateJson): void
    {
        if (!is_file($this->legacyBotPath)) {
            throw new RuntimeException('Legacy bot file tidak ditemukan: ' . $this->legacyBotPath);
        }

        $command = ['php', $this->legacyBotPath];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Gagal menjalankan proses bot lama.');
        }

        fwrite($pipes[0], $telegramUpdateJson);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                sprintf('Bot lama exit code %d. stderr: %s stdout: %s', $exitCode, trim((string)$stderr), trim((string)$stdout))
            );
        }
    }
}
