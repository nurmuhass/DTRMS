<?php
declare(strict_types=1);

function concurrentReview(PDO $db, int $staff, int $reviewer, array $report, string $stage): array
{
    $processes = [];
    try {
        $db->beginTransaction();
        $lock = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$staff]);
        $lock->fetchColumn();
        for ($index = 0; $index < 2; $index++) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/review-worker.php', (string) $reviewer,
                (string) $report['id'], (string) $report['lock_version'], $stage], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect(is_resource($process), 'start concurrent review worker');
            $processes[] = [$process, $pipes];
            expect(trim((string) fgets($pipes[1])) === 'ready', 'review worker opened its database connection');
        }
        $db->commit();
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]));
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process) === 0 && $stderr === '', 'concurrent review worker completed');
        }
        sort($results);
        return $results;
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}
