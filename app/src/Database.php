<?php

declare(strict_types=1);

namespace Resm;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * The single PDO connection for a request.
 *
 * Every method here takes SQL plus bound parameters. Nothing in the
 * application builds SQL by string concatenation — that rule is absolute, and
 * the helpers exist so there is never a reason to reach past them.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array<string, mixed> $settings */
    public function __construct(private array $settings)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $socket = $this->settings['socket'] ?? null;
        $name = (string) ($this->settings['name'] ?? '');

        // utf8mb4 is named explicitly rather than left to the server default:
        // MariaDB's default collation differs from MySQL's (docs/hosting.md).
        if (is_string($socket) && $socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name);
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($this->settings['host'] ?? '127.0.0.1'),
                (int) ($this->settings['port'] ?? 3306),
                $name
            );
        }

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) ($this->settings['user'] ?? ''),
                (string) ($this->settings['pass'] ?? ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                    // Real server-side prepares, not emulated string
                    // interpolation, so the driver never assembles SQL text
                    // from user input.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,

                    // The connection time zone decides what NOW() and
                    // CURRENT_TIMESTAMP defaults record. Everything is stored
                    // in UTC and converted to America/Chicago only for
                    // display, so pin it rather than inherit the server's.
                    PDO::MYSQL_ATTR_INIT_COMMAND =>
                        "SET SESSION time_zone = '+00:00', "
                        . "sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
                ]
            );
        } catch (Throwable $e) {
            // The message carries the DSN, which names the database and user.
            // Re-throw without it so a stack trace in a log cannot leak more
            // than the fact that the connection failed.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return $this->pdo;
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string|int, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Run $work inside a transaction, rolling back on any throwable.
     *
     * Assignment writes must be atomic: vacating someone's prior position and
     * writing the new one is one transaction or it is a double-booking
     * (spec 10.4).
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();

        // Nested calls join the outer transaction rather than starting a
        // second one, which MySQL would silently commit.
        if ($pdo->inTransaction()) {
            return $work($this);
        }

        $pdo->beginTransaction();
        try {
            $result = $work($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo instanceof PDO;
    }
}
