<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Access\CircuitPermit;
use Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy;
use Hachitechs\HideguardPhp\Contracts\CircuitBreakerStoreContract;
use Hachitechs\HideguardPhp\Exceptions\ApiException;

final class WpdbCircuitBreakerStore implements CircuitBreakerStoreContract
{
    private readonly string $table;
    private bool $transaction = false;

    public function __construct(private readonly DedicatedWpdb $db, string $prefix)
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/D', $prefix)) {
            throw new \InvalidArgumentException('Use a safe table prefix.');
        }
        $this->table = '`' . $prefix . 'circuit`';
        $db->suppress_errors(true);
    }

    public static function forSite(): self
    {
        global $wpdb;
        return new self(new DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST), $wpdb->prefix . 'hachitech_');
    }

    public function acquire(string $key, int $now, CircuitBreakerPolicy $policy): CircuitPermit
    {
        return $this->atomic($key, function (array $state) use ($now, $policy): array {
            if ((int)$state['open_until'] > $now) {
                throw new ApiException('HideGuard circuit is open.', 503);
            }
            if ((int)$state['open_until'] > 0) {
                if ($state['probe_token'] !== null && (int)$state['probe_until'] > $now) {
                    throw new ApiException('HideGuard recovery probe is already running.', 503);
                }
                $state['generation']++;
                $state['probe_token'] = bin2hex(random_bytes(16));
                $state['probe_until'] = $now + $policy->probeLeaseSeconds;
                return [$state, new CircuitPermit((int)$state['generation'], $state['probe_token'])];
            }
            if ((int)$state['window_start'] + $policy->windowSeconds <= $now) {
                $state['failures'] = 0;
                $state['window_start'] = $now;
                $state['generation']++;
            }
            return [$state, new CircuitPermit((int)$state['generation'])];
        });
    }
    public function record(
        string $key,
        CircuitPermit $permit,
        bool $failed,
        int $now,
        CircuitBreakerPolicy $policy
    ): void {
        $this->atomic($key, function (array $state) use ($permit, $failed, $now, $policy): array {
            if ((int)$state['generation'] !== $permit->generation) {
                return [$state, null];
            }
            if ($permit->probeToken !== null) {
                if ($state['probe_token'] !== $permit->probeToken || (int)$state['probe_until'] <= $now) {
                    return [$state, null];
                }
                $state['generation']++;
                $state['probe_token'] = null;
                $state['probe_until'] = 0;
                $state['open_until'] = $failed ? $now + $policy->openSeconds : 0;
                $state['failures'] = 0;
                $state['window_start'] = $now;
                return [$state, null];
            }
            if ((int)$state['open_until'] > 0) {
                return [$state, null];
            }
            if ((int)$state['window_start'] + $policy->windowSeconds <= $now) {
                $state['failures'] = 0;
                $state['window_start'] = $now;
            }
            $state['failures'] = $failed ? (int)$state['failures'] + 1 : 0;
            if (!$failed) {
                $state['window_start'] = $now;
            }
            if ((int)$state['failures'] >= $policy->threshold) {
                $state['open_until'] = $now + $policy->openSeconds;
                $state['generation']++;
            }
            return [$state, null];
        });
    }
    private function rollbackIfActive(): void
    {
        if ($this->transaction) {
            $this->db->execute('ROLLBACK');
            $this->transaction = false;
        }
    }

    private function atomic(string $key, callable $work): mixed
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \InvalidArgumentException('Use a SHA-256 circuit key.');
        }
        if ($this->transaction) {
            throw new \LogicException('Circuit state requires a dedicated connection.');
        }
        $previousWait = (int)$this->db->scalar(
            'SELECT @@SESSION.innodb_lock_wait_timeout'
        );
        $this->db->execute('SET SESSION innodb_lock_wait_timeout=1');
        try {
            $this->db->execute('START TRANSACTION');
            $this->transaction = true;
            $this->db->execute('INSERT INTO ' . $this->table . ' (circuit_key) VALUES (?)'
                . ' ON DUPLICATE KEY UPDATE circuit_key=VALUES(circuit_key)', [$key]);
            $state = $this->db->row('SELECT * FROM ' . $this->table . ' WHERE circuit_key=? FOR UPDATE', [$key]);
            [$state, $result] = $work($state);
            $this->db->execute(
                'UPDATE ' . $this->table . ' SET failures=?, window_start=?, open_until=?, generation=?, '
                . 'probe_token=?, probe_until=? WHERE circuit_key=?',
                [$state['failures'], $state['window_start'],
                    $state['open_until'], $state['generation'], $state['probe_token'], $state['probe_until'], $key]
            );
            $this->db->execute('COMMIT');
            $this->transaction = false;
            return $result;
        } catch (\Throwable $error) {
            $this->rollbackIfActive();
            throw $error;
        } finally {
            $this->db->execute('SET SESSION innodb_lock_wait_timeout=' . $previousWait);
        }
    }
}
