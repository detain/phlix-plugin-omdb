<?php

/**
 * Dev-only stub of Workerman\MySQL\Connection.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 *
 * @internal Tests only — never autoloaded into production.
 */

declare(strict_types=1);

namespace Workerman\MySQL;

/**
 * Stub for Workerman\MySQL\Connection class.
 *
 * This is a simplified stub for static analysis purposes.
 * The real class is provided by the phlix-server host.
 *
 * @internal Tests only — never autoloaded into production.
 */
class Connection
{
    /**
     * @param string $host Database host
     * @param int $port Database port
     * @param string $user Database user
     * @param string $password Database password
     * @param string $db Database name
     * @param string $charset Character set
     */
    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        string $db,
        string $charset = 'utf8mb4',
    ) {
    }

    /**
     * Execute a query and return results.
     *
     * @param string $sql SQL query
     * @param array<int, mixed> $bindings Query bindings
     * @return array<int, array<string, mixed>>|null
     */
    public function query(string $sql, array $bindings = []): ?array
    {
        return null;
    }
}
