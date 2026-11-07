<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Access\RateWindow;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardWordPress\WpdbStorage;

final class HighTrafficStorageTest extends AdapterTestCase
{
    public function testServicesRejectOldSchemaEvenWhenStorageWasAlreadyResolved(): void
    {
        $services = new \Hachitechs\HideguardWordPress\Services(
            new \Hachitechs\HideguardWordPress\Settings(),
            new \Hachitechs\HideguardWordPress\Identity()
        );
        $services->storage();
        update_option('hachitechs_hideguard_schema', 2);
        try {
            $services->storage();
            self::fail('Business/REST storage must fail closed before explicit migration.');
        } catch (\Hachitechs\HideguardPhp\Exceptions\GuardException $error) {
            self::assertSame(\Hachitechs\HideguardPhp\Exceptions\FailureReason::VerificationUnavailable, $error->reason);
        } finally {
            update_option('hachitechs_hideguard_schema', 3);
        }
    }

    public function testUnusedStorageReleasesItsConnectionWithoutWaitingForCycleCollection(): void
    {
        $db = new \Hachitechs\HideguardWordPress\DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $databaseReference = WeakReference::create($db);
        $storage = new WpdbStorage($db, 'wp_hachitech_');
        $reference = WeakReference::create($storage);
        self::assertSame('value%with%percent', $db->scalar('SELECT ?', ['value%with%percent']));
        self::assertFalse(has_filter('query', [$db, 'remove_placeholder_escape']));
        unset($storage, $db);
        self::assertNull($reference->get());
        self::assertNull($databaseReference->get());
    }

    public function testCounterInsertUpdateRollbackAndExplicitRepair(): void
    {
        global $wpdb;
        $storage = WpdbStorage::forSite();
        $storage->run('counter', function () use ($storage): void {
            self::assertSame(0, $storage->sessionCount('counter'));
            $storage->saveSession('counter', new BrowserState('a'));
            $storage->saveSession('counter', new BrowserState('a', lastSeen: 123));
            self::assertSame(1, $storage->sessionCount('counter'));
        });
        try {
            $storage->run('counter', function () use ($storage): void {
                $storage->saveSession('counter', new BrowserState('rollback'));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            self::assertSame(1, $storage->sessionCount('counter'));
        }
        $wpdb->query("UPDATE {$wpdb->prefix}hachitech_control SET value=77 WHERE scope_id='counter' AND name='sessions'");
        self::assertSame(1, $storage->run('counter', fn() => $storage->reconcileSessionCount('counter')));
        self::assertSame(1, $storage->sessionCount('counter'));
    }

    public function testCleanupSharesTotalBudgetAndRetainsActiveSecurityState(): void
    {
        $storage = WpdbStorage::forSite();
        $storage->run('batch', function () use ($storage): void {
            $storage->block('batch', 'expired', null, 10);
            $storage->saveRate('batch', 'expired-rate', new RateWindow(1, 10));
            foreach (['a', 'b', 'c', 'd', 'e'] as $browser) {
                $storage->saveSession('batch', new BrowserState($browser, lastSeen: 1));
            }
            $storage->saveSession('batch', new BrowserState('grant', lastSeen: 1, grantedUntil: 300));
            $storage->saveSession('batch', new BrowserState('block', lastSeen: 1, blockedUntil: 300));
            $storage->saveSession('batch', new BrowserState('attempt', lastSeen: 1, verificationUntil: 300));
        });
        $totals = [];
        do {
            $result = $storage->run('batch', fn() => $storage->cleanupBatch('batch', 200, 100, 3));
            $totals[] = $result->total();
        } while ($result->hasMore);
        self::assertSame([3, 3, 1], $totals);
        self::assertSame(3, $storage->sessionCount('batch'));
        foreach (['grant', 'block', 'attempt'] as $browser) {
            self::assertNotNull($storage->session('batch', $browser));
        }
    }

    public function testCounterUnderflowRollsBackCleanup(): void
    {
        global $wpdb;
        $storage = WpdbStorage::forSite();
        $storage->run('underflow', fn() => $storage->saveSession('underflow', new BrowserState('a', lastSeen: 1)));
        $wpdb->query("UPDATE {$wpdb->prefix}hachitech_control SET value=0 WHERE scope_id='underflow' AND name='sessions'");
        try {
            $storage->run('underflow', fn() => $storage->cleanupBatch('underflow', 200, 100, 3));
            self::fail('Counter drift must fail closed.');
        } catch (LogicException) {
            self::assertNotNull($storage->session('underflow', 'a'));
        }
    }

    public function testIndependentBrowserCanBindWhileAnotherBrowserHoldsItsMutex(): void
    {
        $storage = WpdbStorage::forSite();
        $storage->run('partition', function () use ($storage): void {
            $storage->saveSession('partition', new BrowserState('a'));
            $storage->saveSession('partition', new BrowserState('b'));
        });
        $storage->runBrowser('partition', 'a', new ResourceRef('cart', 'a'), function (): void {
            self::assertSame('done', $this->worker('bind', 'b', 'b'));
            self::assertSame('busy', $this->worker('exclusive', 'b', 'b'));
            self::assertSame('busy', $this->worker('bind', 'b', 'a'));
        });
        self::assertSame('b', $storage->resource('partition', new ResourceRef('cart', 'b'))->browserId);
    }

    private function worker(string $mode, string $browser, string $resource): string
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/traffic-worker.php', $mode, $browser, $resource],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        return $output;
    }
}
