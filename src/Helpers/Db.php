<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Helpers;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Thin facade over a Doctrine DBAL connection.
 *
 * Modules talk to this class rather than to DBAL directly, so the driver
 * underneath can be replaced without touching them. Never interpolate values
 * into a query — pass them as $params, with or without the leading colon.
 */
class Db
{
    protected Connection $connection;

    public function connect(string $user, string $password, string $host, int $port, string $dbName): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'user' => $user,
            'password' => $password,
            'host' => $host,
            'port' => $port,
            'dbname' => $dbName,
            'charset' => 'utf8mb4',
        ]);
    }

    /**
     * Run a SELECT and return every row.
     */
    public function query(string $query, array $params = []): array
    {
        return $this->connection
            ->executeQuery($query, $this->normalizeParams($params))
            ->fetchAllAssociative();
    }

    /**
     * Run a SELECT and return the first row, or null when nothing matched.
     */
    public function queryOne(string $query, array $params = []): ?array
    {
        $row = $this->connection
            ->executeQuery($query, $this->normalizeParams($params))
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Run an INSERT, UPDATE or DELETE and return the number of affected rows.
     */
    public function exec(string $query, array $params = []): int
    {
        return (int)$this->connection->executeStatement($query, $this->normalizeParams($params));
    }

    /**
     * Build a query instead of writing SQL by hand. Values still go through
     * setParameter(), never into the string.
     */
    public function builder(): QueryBuilder
    {
        return $this->connection->createQueryBuilder();
    }

    /**
     * Run a closure inside a transaction, rolling back if it throws.
     */
    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional($operation);
    }

    public function lastInsertId(): int
    {
        return (int)$this->connection->lastInsertId();
    }

    /**
     * Escape hatch for the parts of DBAL this facade does not wrap.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Accept both ':name' and 'name' as parameter keys; DBAL expects the latter.
     */
    protected function normalizeParams(array $params): array
    {
        $normalized = [];

        foreach ($params as $key => $value) {
            $normalized[\is_string($key) ? \ltrim($key, ':') : $key] = $value;
        }

        return $normalized;
    }
}
