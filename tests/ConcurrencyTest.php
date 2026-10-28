<?php

declare(strict_types=1);

namespace WebhookGuardian\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use WebhookGuardian\Store\PdoSqliteEventStore;

final class ConcurrencyTest extends TestCase
{
    public function testIndependentProcessesHaveOneWinner(): void
    {
        $results = $this->race(false);
        self::assertSame(1, count(array_filter($results, fn ($r) => $r === 'accepted')));
        self::assertSame(7, count(array_filter($results, fn ($r) => $r === 'duplicate')));
    }

    public function testConcurrentConflictingPayloadsCannotBothWin(): void
    {
        $results = $this->race(true);
        self::assertSame(1, count(array_filter($results, fn ($r) => $r === 'accepted')));
        self::assertSame(3, count(array_filter($results, fn ($r) => $r === 'duplicate')));
        self::assertSame(4, count(array_filter($results, fn ($r) => $r === 'conflict')));
    }

    private function race(bool $conflicting): array
    {
        if (!function_exists('proc_open')) { self::markTestSkipped('proc_open is required'); }
        $dir = sys_get_temp_dir() . '/guardian-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $path = $dir . '/events.sqlite';
        $barrier = $dir . '/go';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA journal_mode=WAL');
        (new PdoSqliteEventStore($pdo))->install();
        $pdo->exec('CREATE TABLE effects (event_id TEXT PRIMARY KEY)');
        $workers = [];
        try {
            for ($i = 0; $i < 8; ++$i) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php', $path, $barrier,
                    (string) ($conflicting ? $i % 2 : 0)],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier . '.*.ready')) !== 8 && microtime(true) < $deadline) { usleep(1000); }
            self::assertCount(8, glob($barrier . '.*.ready'), 'All workers must reach the barrier');
            touch($barrier);
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
            }
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM webhook_guardian_events')->fetchColumn());
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM effects')->fetchColumn());
            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            }
            unset($pdo);
            foreach (glob($dir . '/*') as $file) { unlink($file); }
            rmdir($dir);
        }
    }
}
