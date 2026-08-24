<?php

declare(strict_types=1);

namespace Resm;

use RuntimeException;
use Throwable;

/**
 * Applies db/migrations/NNN_name.sql in numeric order, once each.
 *
 * A note on atomicity: MySQL and MariaDB commit implicitly on DDL, so wrapping
 * a CREATE TABLE in a transaction buys nothing and a rollback would silently
 * do nothing. Rather than pretend otherwise, a migration that is pure DML opts
 * in by putting "-- resm:atomic" on a line of its own; those really do roll
 * back. Schema migrations are fixed forward, which is why they are kept small
 * and ordered.
 */
final class Migrator
{
    public function __construct(
        private Database $db,
        private string $migrationsDir,
    ) {
    }

    public function ensureRegistry(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migration (
                version     VARCHAR(20)  NOT NULL,
                filename    VARCHAR(190) NOT NULL,
                checksum    CHAR(64)     NOT NULL,
                applied_at  DATETIME     NOT NULL,
                duration_ms INT UNSIGNED NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Migration files on disk, keyed by version, in the order they must run.
     *
     * @return array<string, string> version => absolute path
     */
    public function available(): array
    {
        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        $found = [];

        foreach ($files as $file) {
            if (preg_match('/^(\d+)_[A-Za-z0-9_]+\.sql$/', basename($file), $m) !== 1) {
                throw new RuntimeException(
                    'Migration filenames must look like 001_description.sql; found: ' . basename($file)
                );
            }
            $version = $m[1];
            if (isset($found[$version])) {
                throw new RuntimeException("Two migrations share version {$version}.");
            }
            $found[$version] = $file;
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    /** @return array<string, array<string, mixed>> version => registry row */
    public function applied(): array
    {
        $rows = $this->db->all('SELECT version, filename, checksum, applied_at FROM schema_migration ORDER BY version');
        $byVersion = [];
        foreach ($rows as $row) {
            $byVersion[(string) $row['version']] = $row;
        }

        return $byVersion;
    }

    /** @return array<string, string> version => path */
    public function pending(): array
    {
        return array_diff_key($this->available(), $this->applied());
    }

    /**
     * Migrations are immutable once applied. Editing one that has already run
     * leaves environments silently different from each other, which is a bad
     * afternoon on a shared host with no staging copy.
     *
     * @return array<int, string> human-readable descriptions of any drift
     */
    public function drift(): array
    {
        $problems = [];
        $available = $this->available();

        foreach ($this->applied() as $version => $row) {
            if (!isset($available[$version])) {
                $problems[] = "{$version} is recorded as applied but its file is missing ({$row['filename']}).";
                continue;
            }
            $checksum = hash_file('sha256', $available[$version]);
            if ($checksum !== $row['checksum']) {
                $problems[] = "{$version} has changed since it was applied ({$row['filename']}).";
            }
        }

        return $problems;
    }

    /**
     * @param callable(string): void $log
     * @return array<int, string> versions applied
     */
    public function migrate(callable $log, bool $dryRun = false): array
    {
        $this->ensureRegistry();

        $drift = $this->drift();
        if ($drift !== []) {
            throw new RuntimeException(
                "Refusing to migrate; already-applied migrations have changed:\n  - "
                . implode("\n  - ", $drift)
                . "\nAdd a new migration instead of editing an applied one."
            );
        }

        $applied = [];
        foreach ($this->pending() as $version => $file) {
            $name = basename($file);

            if ($dryRun) {
                $count = count(SqlScript::split((string) file_get_contents($file)));
                $log("would apply {$name} ({$count} statements)");
                $applied[] = $version;
                continue;
            }

            $log("applying {$name}");
            $this->apply($version, $file);
            $applied[] = $version;
        }

        return $applied;
    }

    private function apply(string $version, string $file): void
    {
        $sql = (string) file_get_contents($file);
        $statements = SqlScript::split($sql);
        $atomic = preg_match('/^\s*--\s*resm:atomic\s*$/mi', $sql) === 1;

        $started = microtime(true);
        $pdo = $this->db->pdo();

        if ($atomic) {
            $pdo->beginTransaction();
        }

        try {
            foreach ($statements as $index => $statement) {
                try {
                    $pdo->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException(sprintf(
                        "%s failed at statement %d:\n%s\n\n%s",
                        basename($file),
                        $index + 1,
                        self::excerpt($statement),
                        $e->getMessage()
                    ), 0, $e);
                }
            }

            if ($atomic && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($atomic && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->db->execute(
            'INSERT INTO schema_migration (version, filename, checksum, applied_at, duration_ms)
             VALUES (:version, :filename, :checksum, :applied_at, :duration_ms)',
            [
                'version'     => $version,
                'filename'    => basename($file),
                'checksum'    => hash_file('sha256', $file),
                'applied_at'  => gmdate('Y-m-d H:i:s'),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]
        );
    }

    private static function excerpt(string $statement): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($statement)) ?? $statement;

        return strlen($flat) > 300 ? substr($flat, 0, 300) . ' …' : $flat;
    }
}
