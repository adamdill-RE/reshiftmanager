<?php

declare(strict_types=1);

use Resm\App;
use Resm\Database;

/**
 * Helpers shared by every test file.
 *
 * Loaded by tests/run.php before the suites, so running a single file with a
 * filter still has them.
 */

/**
 * These run against a real MariaDB with the migrations applied. Without a
 * database they skip rather than fail, so the suite still means something on a
 * machine that has not run docker compose yet.
 */
function testDb(): Database
{
    static $db = null;
    static $unavailable = null;

    // Once we know there is no usable database, every later test skips for the
    // same stated reason rather than each rediscovering it as a failure.
    if ($unavailable !== null) {
        skip($unavailable);
    }

    if ($db === null) {
        $candidate = App::boot(dirname(__DIR__))->db();

        try {
            $candidate->value('SELECT 1');
        } catch (Throwable $e) {
            $unavailable = 'no database — run docker compose up -d, then php bin/migrate.php';
            skip($unavailable);
        }

        if ((int) $candidate->value("SELECT COUNT(*) FROM information_schema.tables
                                     WHERE table_schema = DATABASE() AND table_name = 'position'") === 0) {
            $unavailable = 'migrations have not been applied — run php bin/migrate.php';
            skip($unavailable);
        }

        $db = $candidate;
    }

    return $db;
}

/** Run $work against fixtures and undo it, so the suite leaves no residue. */
function inRollback(callable $work): void
{
    $db = testDb();
    $pdo = $db->pdo();
    $pdo->beginTransaction();
    try {
        $work($db);
    } finally {
        $pdo->rollBack();
    }
}


/**
 * The display timezone, and a local time expressed as the UTC instant the
 * database stores.
 *
 * Here rather than in one suite because three files need them, and a helper
 * that lives in a test file makes `php tests/run.php <filter>` fail on every
 * other file that uses it.
 */
function chicago(): DateTimeZone
{
    return new DateTimeZone('America/Chicago');
}

function utc(string $localTime): DateTimeImmutable
{
    return (new DateTimeImmutable($localTime, chicago()))->setTimezone(new DateTimeZone('UTC'));
}

/** Record a check in or out at a local time, the way the tarmac does. */
function checkEvent(Database $db, int $shift, int $user, string $type, string $localTime): void
{
    $db->execute(
        'INSERT INTO check_event (shift_id, user_id, type, occurred_at) VALUES (:s, :u, :t, :o)',
        ['s' => $shift, 'u' => $user, 't' => $type, 'o' => utc($localTime)->format('Y-m-d H:i:s')]
    );
}
