<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Access\RateWindow;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Contracts\PartitionedAtomicMutationContract;
use Hachitechs\HideguardPhp\Contracts\BatchCleanupContract;
use Hachitechs\HideguardPhp\Contracts\SessionCounterContract;
use Hachitechs\HideguardPhp\Access\CleanupResult;
use Hachitechs\HideguardPhp\Contracts\StorageContract;

/** Native WordPress persistence on a dedicated wpdb connection, MySQL 8/InnoDB. */
final class WpdbStorage implements
    StorageContract,
    PartitionedAtomicMutationContract,
    BatchCleanupContract,
    SessionCounterContract
{
    public const SCHEMA_VERSION = 3;

    private readonly WpdbCoordinator $coordinator;

    /** Do not pass the global business connection; this object owns its transactions. */
    public function __construct(private readonly DedicatedWpdb $db, private readonly string $prefix)
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/D', $prefix)) {
            throw new \InvalidArgumentException('Use a safe access table prefix.');
        }
        $db->suppress_errors(true);
        // The coordinator must not keep this storage/connection alive through a reference cycle.
        $owner = \WeakReference::create($this);
        $this->coordinator = new WpdbCoordinator(
            $db,
            $prefix,
            static fn(string $scope, string $browser) => $owner->get()?->session($scope, $browser)
        );
    }

    public static function forSite(): self
    {
        global $wpdb;
        return new self(new DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST), $wpdb->prefix . 'hachitech_');
    }

    /** Activation/versioned upgrades only; never called inside an access mutation. */
    public function initializeSchema(): void
    {
        if ($this->coordinator->active()) {
            throw new \LogicException('Schema initialization cannot run inside an access mutation.');
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($this->schemaStatements() as $statement) {
            dbDelta($statement);
        }
        $engines = $this->rows(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME IN (?, ?, ?, ?, ?)',
            [$this->prefix . 'session', $this->prefix . 'block', $this->prefix . 'rate',
                $this->prefix . 'control', $this->prefix . 'circuit']
        );
        $columns = (int)$this->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)',
            [$this->prefix . 'session', 'paypal_flow', 'revocation_version']
        );
        if (
            $columns !== 2 || count($engines) !== 5
            || array_diff(array_column($engines, 'ENGINE'), ['InnoDB']) !== []
        ) {
            throw new \LogicException('All access storage tables must use InnoDB transactions.');
        }
        foreach ($this->rows('SELECT DISTINCT scope_id FROM ' . $this->table('session')) as $row) {
            $scope = (string)$row['scope_id'];
            $this->run($scope, fn() => $this->reconcileSessionCount($scope));
        }
    }

    public function run(string $scopeId, callable $work): mixed
    {
        return $this->coordinator->run($scopeId, $work);
    }

    public function runBrowser(string $scopeId, string $browserId, ?ResourceRef $resource, callable $work): mixed
    {
        return $this->coordinator->runBrowser($scopeId, $browserId, $resource, $work);
    }

    public function runResource(string $scopeId, ResourceRef $resource, callable $work): mixed
    {
        return $this->coordinator->runResource($scopeId, $resource, $work);
    }

    public function reconcileSessionCount(string $scopeId): int
    {
        $this->requireMutation();
        $count = (int)$this->scalar(
            'SELECT COUNT(*) FROM ' . $this->table('session') . ' WHERE scope_id=?',
            [$scopeId]
        );
        $this->query('INSERT INTO ' . $this->table('control') . ' (scope_id,name,value,initialized) VALUES (?,?,?,1) '
            . 'ON DUPLICATE KEY UPDATE value=VALUES(value),initialized=1', [$scopeId, 'sessions', $count]);
        return $count;
    }

    private function requireMutation(): void
    {
        if (!$this->coordinator->active()) {
            throw new \LogicException('Storage mutation requires an atomic transaction.');
        }
    }

    public function session(string $scopeId, string $browserId): ?BrowserState
    {
        return $this->fetchState(
            'SELECT * FROM ' . $this->table('session') . ' WHERE scope_id = ? AND browser_id = ?',
            [$scopeId, $browserId]
        );
    }

    public function saveSession(string $scopeId, BrowserState $state): void
    {
        $this->requireMutation();
        $new = $this->session($scopeId, $state->browserId) === null;
        if ($new) {
            $this->sessionCount($scopeId);
        }
        $this->query(
            'INSERT INTO ' . $this->table('session') . ' (scope_id, browser_id, actor_id, visitor_id, blocked_until, '
            . 'granted_until, policy_hash, resource_type, resource_id, last_seen, verification, '
            . 'verification_id, verification_until) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
            . 'actor_id = VALUES(actor_id), visitor_id = VALUES(visitor_id), blocked_until = VALUES(blocked_until), '
            . 'granted_until = VALUES(granted_until), policy_hash = VALUES(policy_hash), '
            . 'resource_type = VALUES(resource_type), resource_id = VALUES(resource_id), '
            . 'last_seen = VALUES(last_seen), verification = VALUES(verification), '
            . 'verification_id = VALUES(verification_id), verification_until = VALUES(verification_until)',
            [
                $scopeId, $state->browserId, $state->actorId, $state->visitorId, $state->blockedUntil,
                $state->grantedUntil, $state->policyHash, $state->resource?->type, $state->resource?->id,
                $state->lastSeen, $state->verification, $state->verificationId, $state->verificationUntil,
            ]
        );
        if ($new) {
            $this->query(
                'UPDATE ' . $this->table('control') . ' SET value=value+1 WHERE scope_id=? AND name=?',
                [$scopeId, 'sessions']
            );
        }
    }

    /** Server-only PayPal approval metadata, serialized under the same scope lock as Guard state. */
    public function paypalFlow(string $scopeId, string $browserId): ?array
    {
        $value = $this->scalar(
            'SELECT paypal_flow FROM ' . $this->table('session') . ' WHERE scope_id = ? AND browser_id = ?',
            [$scopeId, $browserId]
        );
        $flow = is_string($value) ? json_decode($value, true) : null;
        return is_array($flow) ? $flow : null;
    }

    public function savePaypalFlow(string $scopeId, string $browserId, ?array $flow): void
    {
        $this->query(
            'UPDATE ' . $this->table('session') . ' SET paypal_flow = ? WHERE scope_id = ? AND browser_id = ?',
            [$flow === null ? null : json_encode($flow, JSON_THROW_ON_ERROR), $scopeId, $browserId]
        );
    }

    public function revocationVersion(string $scopeId, string $browserId): int
    {
        return (int)$this->scalar(
            'SELECT revocation_version FROM ' . $this->table('session') . ' WHERE scope_id = ? AND browser_id = ?',
            [$scopeId, $browserId]
        );
    }

    public function sessionCount(string $scopeId): int
    {
        if (!$this->coordinator->active()) {
            return $this->run($scopeId, fn() => $this->sessionCount($scopeId));
        }
        $this->query('INSERT INTO ' . $this->table('control') . ' (scope_id,name,value,initialized) VALUES (?,?,0,0) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name)', [$scopeId, 'sessions']);
        $row = $this->row('SELECT value,initialized FROM ' . $this->table('control')
            . ' WHERE scope_id=? AND name=? FOR UPDATE', [$scopeId, 'sessions']);
        return (int)$row['initialized'] ? (int)$row['value'] : $this->reconcileSessionCount($scopeId);
    }

    public function blockExpiry(string $scopeId, ?string $visitorId, ?string $actorId): int
    {
        $hashes = $this->identityHashes($visitorId, $actorId);
        if ($hashes === []) {
            return 0;
        }
        return (int)$this->scalar(
            'SELECT MAX(blocked_until) FROM ' . $this->table('block') . ' WHERE scope_id = ? AND identity_hash IN ('
            . implode(', ', array_fill(0, count($hashes), '?')) . ')',
            array_merge([$scopeId], $hashes)
        );
    }

    public function block(string $scopeId, string $visitorId, ?string $actorId, int $until): void
    {
        foreach ($this->identityHashes($visitorId, $actorId) as $hash) {
            $this->query(
                'INSERT INTO ' . $this->table('block') . ' (scope_id, identity_hash, blocked_until) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until)',
                [$scopeId, $hash, $until]
            );
        }
    }

    public function revokeIdentity(string $scopeId, ?string $visitorId, ?string $actorId): void
    {
        $conditions = [];
        $params = [$scopeId];
        if ($visitorId !== null) {
            $conditions[] = 'visitor_id = ?';
            $params[] = $visitorId;
        }
        if ($actorId !== null) {
            $conditions[] = 'actor_id = ?';
            $params[] = $actorId;
        }
        if ($conditions !== []) {
            $this->revoke('scope_id = ? AND (' . implode(' OR ', $conditions) . ')', $params);
        }
    }

    public function bindResource(string $scopeId, string $browserId, ResourceRef $resource): void
    {
        $this->query(
            'UPDATE ' . $this->table('session') . ' SET resource_type = NULL, resource_id = NULL '
            . 'WHERE scope_id = ? AND resource_type = ? AND resource_id = ? AND browser_id <> ?',
            [$scopeId, $resource->type, $resource->id, $browserId]
        );
        $this->query(
            'UPDATE ' . $this->table('session') . ' SET resource_type = ?, resource_id = ? '
            . 'WHERE scope_id = ? AND browser_id = ?',
            [$resource->type, $resource->id, $scopeId, $browserId]
        );
    }

    public function resource(string $scopeId, ResourceRef $resource): ?BrowserState
    {
        return $this->fetchState(
            'SELECT * FROM ' . $this->table('session')
            . ' WHERE scope_id = ? AND resource_type = ? AND resource_id = ?',
            [$scopeId, $resource->type, $resource->id]
        );
    }

    public function revokeResource(string $scopeId, ResourceRef $resource): void
    {
        $this->revoke(
            'scope_id = ? AND resource_type = ? AND resource_id = ?',
            [$scopeId, $resource->type, $resource->id]
        );
    }

    public function revokeScope(string $scopeId): void
    {
        $this->revoke('scope_id = ?', [$scopeId]);
    }

    public function resetBlocks(string $scopeId): int
    {
        $count = $this->query('DELETE FROM ' . $this->table('block') . ' WHERE scope_id = ?', [$scopeId]);
        $this->query('UPDATE ' . $this->table('session') . ' SET blocked_until = 0 WHERE scope_id = ?', [$scopeId]);
        return $count;
    }

    public function rate(string $scopeId, string $bucketHash): ?RateWindow
    {
        $scopeRate = $bucketHash === hash('sha256', 'scope');
        if ($scopeRate && $this->coordinator->active()) {
            $this->query('INSERT INTO ' . $this->table('rate') . ' (scope_id,bucket_hash,requests,expires_at) '
                . 'VALUES (?,?,0,0) ON DUPLICATE KEY UPDATE bucket_hash=VALUES(bucket_hash)', [$scopeId, $bucketHash]);
        }
        $row = $this->row(
            'SELECT requests,expires_at FROM ' . $this->table('rate')
            . ' WHERE scope_id=? AND bucket_hash=?' . ($scopeRate && $this->coordinator->active() ? ' FOR UPDATE' : ''),
            [$scopeId, $bucketHash]
        );
        return $row !== null && (int)$row['expires_at'] !== 0
            ? new RateWindow((int)$row['requests'], (int)$row['expires_at']) : null;
    }

    public function saveRate(string $scopeId, string $bucketHash, RateWindow $window): void
    {
        $this->query(
            'INSERT INTO ' . $this->table('rate')
            . ' (scope_id, bucket_hash, requests, expires_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE requests = VALUES(requests), expires_at = VALUES(expires_at)',
            [$scopeId, $bucketHash, $window->requests, $window->expiresAt]
        );
    }

    public function cleanup(string $scopeId, int $now, int $inactiveBefore): void
    {
        $this->cleanupBatch($scopeId, $now, $inactiveBefore, 500);
    }

    public function cleanupBatch(string $scopeId, int $now, int $inactiveBefore, int $limit): CleanupResult
    {
        $this->requireMutation();
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Cleanup batch must contain 1 to 10000 rows.');
        }
        $remaining = $limit;
        $counts = [];
        $conditions = [
            'block' => ['blocked_until<=?', [$now]],
            'rate' => ['expires_at<=?', [$now]],
            'session' => ['last_seen<? AND blocked_until<=? AND granted_until<=? AND verification_until<=?',
                [$inactiveBefore, $now, $now, $now]],
        ];
        foreach ($conditions as $suffix => [$where, $params]) {
            $counts[$suffix] = 0;
            if ($remaining === 0) {
                continue;
            }
            if ($suffix === 'session') {
                $this->sessionCount($scopeId);
            }
            $counts[$suffix] = $this->query('DELETE FROM ' . $this->table($suffix)
                . ' WHERE scope_id=? AND ' . $where . ' LIMIT ' . $remaining, array_merge([$scopeId], $params));
            if ($suffix === 'session' && $counts[$suffix] > 0) {
                $count = $counts[$suffix];
                $updated = $this->query(
                    'UPDATE ' . $this->table('control')
                    . ' SET value=value-? WHERE scope_id=? AND name=? AND value>=?',
                    [$count, $scopeId, 'sessions', $count]
                );
                if ($updated !== 1) {
                    throw new \LogicException('Session counter drift; reconcile explicitly.');
                }
            }
            $remaining -= $counts[$suffix];
        }
        return new CleanupResult($counts['block'], $counts['rate'], $counts['session'], $remaining === 0);
    }

    private function query(string $sql, array $params = []): int
    {
        return $this->db->execute($sql, $params);
    }

    private function rows(string $sql, array $params = []): array
    {
        return $this->db->rows($sql, $params);
    }

    private function row(string $sql, array $params = []): ?array
    {
        return $this->db->row($sql, $params);
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        return $this->db->scalar($sql, $params);
    }

    /** @param list<int|string|null> $params */
    private function fetchState(string $sql, array $params): ?BrowserState
    {
        $row = $this->row($sql, $params);
        if (!is_array($row)) {
            return null;
        }
        $actor = $row['actor_id'] !== null ? (string)$row['actor_id'] : null;
        return new BrowserState(
            (string)$row['browser_id'],
            $actor,
            $row['visitor_id'] !== null ? (string)$row['visitor_id'] : null,
            (int)$row['blocked_until'],
            (int)$row['granted_until'],
            $row['policy_hash'] !== null ? (string)$row['policy_hash'] : null,
            $row['resource_type'] !== null && $row['resource_id'] !== null
                ? new ResourceRef((string)$row['resource_type'], (string)$row['resource_id'], $actor) : null,
            (int)$row['last_seen'],
            (string)$row['verification'],
            $row['verification_id'] !== null ? (string)$row['verification_id'] : null,
            (int)$row['verification_until']
        );
    }

    /** @param list<int|string|null> $params */
    private function revoke(string $condition, array $params): void
    {
        $this->query(
            'UPDATE ' . $this->table('session')
            . ' SET granted_until = 0, resource_type = NULL, resource_id = NULL, '
            . 'verification_id = NULL, verification_until = 0, paypal_flow = NULL, '
            . 'revocation_version = revocation_version + 1 WHERE '
            . $condition,
            $params
        );
    }

    /** @return list<string> */
    private function identityHashes(?string $visitorId, ?string $actorId): array
    {
        $hashes = [];
        if ($visitorId !== null) {
            $hashes[] = hash('sha256', 'visitor:' . $visitorId);
        }
        if ($actorId !== null) {
            $hashes[] = hash('sha256', 'actor:' . $actorId);
        }
        return $hashes;
    }

    private function table(string $suffix): string
    {
        return '`' . $this->prefix . $suffix . '`';
    }

    /** dbDelta requires each column/index on its own line. */
    private function schemaStatements(): array
    {
        $session = $this->table('session');
        $block = $this->table('block');
        $rate = $this->table('rate');
        $control = $this->table('control');
        $circuit = $this->table('circuit');
        return [
            "CREATE TABLE $control (
scope_id varbinary(128) NOT NULL,
name varbinary(32) NOT NULL,
value bigint unsigned NOT NULL DEFAULT 0,
initialized tinyint unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (scope_id,name)
) ENGINE=InnoDB;",
            "CREATE TABLE $circuit (
circuit_key char(64) NOT NULL,
failures int unsigned NOT NULL DEFAULT 0,
window_start bigint unsigned NOT NULL DEFAULT 0,
open_until bigint unsigned NOT NULL DEFAULT 0,
generation bigint unsigned NOT NULL DEFAULT 0,
probe_token char(32) NULL,
probe_until bigint unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (circuit_key)
) ENGINE=InnoDB;",
            "CREATE TABLE $session (
scope_id varbinary(128) NOT NULL,
browser_id varbinary(128) NOT NULL,
actor_id varbinary(128) NULL,
visitor_id varbinary(128) NULL,
blocked_until bigint unsigned NOT NULL DEFAULT 0,
granted_until bigint unsigned NOT NULL DEFAULT 0,
policy_hash char(64) NULL,
resource_type varbinary(128) NULL,
resource_id varbinary(128) NULL,
last_seen bigint unsigned NOT NULL,
verification varchar(32) NOT NULL DEFAULT '',
verification_id char(32) NULL,
verification_until bigint unsigned NOT NULL DEFAULT 0,
paypal_flow longtext NULL,
revocation_version bigint unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (scope_id,browser_id),
UNIQUE KEY resource_binding (scope_id,resource_type,resource_id),
KEY visitor_lookup (scope_id,visitor_id),
KEY actor_lookup (scope_id,actor_id),
KEY activity (last_seen)
) ENGINE=InnoDB;",
            "CREATE TABLE $block (
scope_id varbinary(128) NOT NULL,
identity_hash char(64) NOT NULL,
blocked_until bigint unsigned NOT NULL,
PRIMARY KEY  (scope_id,identity_hash),
KEY expiry (blocked_until)
) ENGINE=InnoDB;",
            "CREATE TABLE $rate (
scope_id varbinary(128) NOT NULL,
bucket_hash char(64) NOT NULL,
requests bigint unsigned NOT NULL,
expires_at bigint unsigned NOT NULL,
PRIMARY KEY  (scope_id,bucket_hash),
KEY expiry (expires_at)
) ENGINE=InnoDB;",
        ];
    }
}
