<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Contracts\PartitionedAtomicMutationContract;
use Hachitechs\HideguardPhp\Exceptions\{GuardException, FailureReason};

/** Browser/resource mutexes plus a shared/exclusive scope fence. Reports remain exclusive. */
final class WpdbCoordinator implements PartitionedAtomicMutationContract
{
    private readonly \Closure $snapshot;
    private readonly string $namespace;
    private bool $mutating = false;
    public function __construct(
        private readonly DedicatedWpdb $db,
        private readonly string $prefix,
        callable $snapshot,
        private readonly int $waitSeconds = 1
    ) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/D', $prefix) || $waitSeconds < 0 || $waitSeconds > 10) {
            throw new \InvalidArgumentException('Lock wait must be between 0 and 10 seconds.');
        }
        $this->snapshot = \Closure::fromCallable($snapshot);
        $this->namespace = (string)$db->scalar('SELECT DATABASE()') . ':' . $prefix . 'control';
    }
    public function active(): bool
    {
        return $this->mutating;
    }

    public function run(string $scopeId, callable $work): mixed
    {
        return $this->execute($scopeId, null, null, true, $work);
    }
    public function runBrowser(string $scopeId, string $browserId, ?ResourceRef $resource, callable $work): mixed
    {
        return $this->execute($scopeId, $browserId, $resource, false, $work);
    }
    public function runResource(string $scopeId, ResourceRef $resource, callable $work): mixed
    {
        return $this->execute($scopeId, null, $resource, false, $work);
    }
    private function execute(
        string $scope,
        ?string $browser,
        ?ResourceRef $resource,
        bool $exclusive,
        callable $work
    ): mixed {
        if ($this->mutating) {
            throw new \LogicException('Nested/external transactions are unsupported.');
        }
        $locks = [];
        $previousWait = (int)$this->db->scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
        $this->db->execute('SET SESSION innodb_lock_wait_timeout=' . max(1, $this->waitSeconds));
        try {
            if ($browser !== null) {
                $this->lock($scope, 'browser:' . $browser, $locks);
            }
            $this->db->execute('INSERT IGNORE INTO `' . $this->prefix . 'control` '
                . '(scope_id,name,value,initialized) VALUES (?,?,0,1)', [$scope, 'fence']);
            $this->db->execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->db->execute('START TRANSACTION');
            $this->mutating = true;
            $this->db->scalar(
                'SELECT value FROM `' . $this->prefix . 'control` WHERE scope_id=? AND name=? '
                . ($exclusive ? 'FOR UPDATE' : 'FOR SHARE') . ($this->waitSeconds === 0 ? ' NOWAIT' : ''),
                [$scope, 'fence']
            );
            $resources = [];
            if ($resource !== null) {
                $resources[] = $resource;
            }
            if ($browser !== null) {
                $state = ($this->snapshot)($scope, $browser);
                if ($state?->resource !== null) {
                    $resources[] = $state->resource;
                }
            }
            $keys = [];
            foreach ($resources as $item) {
                $keys[] = 'resource:' . strlen($item->type) . ':' . $item->type . $item->id;
            }
            sort($keys, SORT_STRING);
            foreach (array_unique($keys) as $key) {
                $this->lock($scope, $key, $locks);
            }
            try {
                try {
                    $result = $work();
                } catch (GuardException $error) {
                    $this->db->execute('COMMIT');
                    $this->mutating = false;
                    throw $error;
                }
                $this->db->execute('COMMIT');
                $this->mutating = false;
                return $result;
            } catch (\Throwable $error) {
                if ($this->mutating) {
                    $this->db->execute('ROLLBACK');
                    $this->mutating = false;
                }
                throw $error;
            }
        } catch (\RuntimeException $error) {
            if (in_array((int)$error->getCode(), [1205, 1213, 3572], true)) {
                throw new GuardException(FailureReason::Busy, 'Access storage is busy. Retry shortly.', 1);
            }
            throw $error;
        } finally {
            if ($this->mutating) {
                $this->db->execute('ROLLBACK');
                $this->mutating = false;
            }
            foreach (array_reverse($locks) as $name) {
                $this->db->scalar('SELECT RELEASE_LOCK(?)', [$name]);
            }
            $this->db->execute('SET SESSION innodb_lock_wait_timeout=' . $previousWait);
        }
    }
    /** @param list<string> $locks */
    private function lock(string $scope, string $key, array &$locks): void
    {
        $name = hash('sha256', $this->namespace . ':' . strlen($scope) . ':' . $scope . ':' . $key);
        if ((int)$this->db->scalar('SELECT GET_LOCK(?,?)', [$name, $this->waitSeconds]) !== 1) {
            throw new GuardException(FailureReason::Busy, 'Access storage is busy. Retry shortly.', 1);
        }
        $locks[] = $name;
    }
}
