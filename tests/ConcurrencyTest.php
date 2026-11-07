<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardWordPress\DedicatedWpdb;
use Hachitechs\HideguardWordPress\WpdbStorage;
use PHPUnit\Framework\TestCase;

final class ConcurrencyTest extends TestCase
{
    public function testParallelWorkersCannotExceedRateLimit(): void
    {
        $db = new DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $prefix = 'hg_test_' . bin2hex(random_bytes(6)) . '_';
        $storage = new WpdbStorage($db, $prefix);
        $storage->initializeSchema();
        $processes = [];
        try {
            for ($index = 0; $index < 6; $index++) {
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/worker.php', $prefix],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
            }
            self::assertSame(3, count(array_filter($results, static fn(string $result): bool => $result === 'accepted')));
            self::assertSame(3, count(array_filter($results, static fn(string $result): bool => $result === 'rate_limited')));
            self::assertSame(3, $storage->rate('concurrent', hash('sha256', 'scope'))->requests);
        } finally {
            foreach (['session', 'block', 'rate', 'control', 'circuit'] as $suffix) {
                $db->query('DROP TABLE `' . $prefix . $suffix . '`');
            }
            $db->close();
        }
    }

    /** @dataProvider admissionModes */
    public function testTwentyIndependentBrowsersCannotExceedQuotaOrCapacity(bool $capacity): void
    {
        $db = new DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $prefix = 'hg_test_' . bin2hex(random_bytes(6)) . '_';
        $storage = new WpdbStorage($db, $prefix);
        $storage->initializeSchema();
        try {
            $processes = [];
            for ($i = 0; $i < 20; $i++) {
                $args = [PHP_BINARY, __DIR__ . '/worker.php', $prefix, 'browser-' . $i];
                if ($capacity) {
                    $args[] = 'capacity';
                }
                $process = proc_open(
                    $args,
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
            }
            self::assertSame(3, count(array_filter($results, static fn($r) => $r === 'accepted')));
            self::assertSame(17, count(array_filter(
                $results,
                static fn($r) => $r === ($capacity ? 'capacity_reached' : 'rate_limited')
            )));
            self::assertSame(3, $storage->sessionCount('concurrent'));
        } finally {
            foreach (['session', 'block', 'rate', 'control', 'circuit'] as $suffix) {
                $db->query('DROP TABLE `' . $prefix . $suffix . '`');
            }
            $db->close();
        }
    }

    public static function admissionModes(): array
    {
        return [[false], [true]];
    }

    public function testLostConnectionCannotReconnectAndCommitOutsideTheScopeLock(): void
    {
        $db = new DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $admin = new DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $prefix = 'hg_test_' . bin2hex(random_bytes(6)) . '_';
        $storage = new WpdbStorage($db, $prefix);
        $storage->initializeSchema();
        $connectionId = (int)$db->get_var('SELECT CONNECTION_ID()');
        try {
            try {
                $storage->run('site', function () use ($storage, $admin, $connectionId): void {
                    $storage->saveSession('site', new BrowserState('must-rollback'));
                    $admin->query('KILL ' . $connectionId);
                    $storage->saveSession('site', new BrowserState('must-not-reconnect'));
                });
                self::fail('A lost transaction must fail.');
            } catch (RuntimeException) {
                self::assertSame(0, (int)$admin->get_var('SELECT COUNT(*) FROM `' . $prefix . 'session`'));
            }
        } finally {
            foreach (['session', 'block', 'rate', 'control', 'circuit'] as $suffix) {
                $admin->query('DROP TABLE `' . $prefix . $suffix . '`');
            }
            $admin->close();
        }
    }
}
