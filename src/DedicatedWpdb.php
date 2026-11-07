<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

/** Losing a connection must fail the mutation, never retry outside its lost transaction/lock. */
final class DedicatedWpdb extends \wpdb
{
    // WordPress defines this inherited method with its native snake_case name.
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function check_connection($allow_bail = true): bool
    {
        return false;
    }

    /** Bind only values; identifiers and SQL structure are authored by this adapter. */
    private function bind(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }
        $values = [];
        $offset = 0;
        $template = preg_replace_callback('/\?/', static function () use (&$offset, &$values, $params): string {
            $value = $params[$offset++];
            if ($value === null) {
                return 'NULL';
            }
            $values[] = $value;
            return '%s';
        }, $sql);
        if ($offset !== count($params)) {
            throw new \LogicException('SQL placeholder count mismatch.');
        }
        return $values === [] ? $template : $this->prepare($template, $values);
    }

    public function execute(string $sql, array $params = []): int
    {
        try {
            $result = $this->query($this->bind($sql, $params));
            if ($result === false) {
                $code = $this->dbh instanceof \mysqli ? mysqli_errno($this->dbh) : 0;
                throw new \RuntimeException('Access storage query failed.', $code);
            }
            return (int)$result;
        } finally {
            // wpdb::prepare registers this connection on a global filter. Release that reference
            // after the query has removed its placeholder escapes, without removing other callbacks.
            remove_filter('query', [$this, 'remove_placeholder_escape'], 0);
        }
    }

    public function rows(string $sql, array $params = []): array
    {
        $this->execute($sql, $params);
        return array_map(static fn(object $row): array => (array)$row, $this->last_result);
    }

    public function row(string $sql, array $params = []): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $row = $this->row($sql, $params);
        return $row === null ? null : reset($row);
    }
}
