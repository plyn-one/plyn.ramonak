<?php
declare(strict_types=1);

namespace Ramonak;

use PDO;
use PDOStatement;

/**
 * The only way to the database: prepared statements, one transaction helper, and the few places where MariaDB and SQLite
 * speak differently. No module writes SQL outside its own repository class, and no template sees a query.
 */
final class Db
{
    private PDO $pdo;
    public readonly string $driver;
    private int $depth = 0;

    public function __construct(string $dsn, ?string $user = null, ?string $password = null)
    {
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } elseif ($this->driver === 'mysql') {
            $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");
        }
    }

    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':'), $value,
                match (true) { is_int($value) => PDO::PARAM_INT, is_bool($value) => PDO::PARAM_BOOL, $value === null => PDO::PARAM_NULL, default => PDO::PARAM_STR });
        }
        $statement->execute();
        return $statement;
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function tx(callable $work): mixed
    {
        if ($this->depth++ === 0) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $work();
            if (--$this->depth === 0) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $error) {
            if (--$this->depth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** A migration's statements in the dialect of this database: {{ID}}, {{TEXT}}, {{JSON}}, {{NOW}} and engine options. */
    public function dialect(string $sql): string
    {
        $mysql = $this->driver === 'mysql';
        return strtr($sql, [
            '{{ID}}' => $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{{REF}}' => $mysql ? 'BIGINT UNSIGNED' : 'INTEGER',
            '{{KEY}}' => $mysql ? 'VARCHAR(191)' : 'TEXT',
            '{{TEXT}}' => $mysql ? 'MEDIUMTEXT' : 'TEXT',
            '{{JSON}}' => $mysql ? 'LONGTEXT' : 'TEXT',
            '{{TABLE}}' => $mysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
        ]);
    }

    public function now(): int
    {
        return time();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
