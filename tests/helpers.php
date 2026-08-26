<?php

declare(strict_types=1);

use Resm\App;
use Resm\Auth\Identity;
use Resm\Auth\Role;
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

/**
 * Delete a fixture season and everything hanging off it.
 *
 * Most suites use inRollback, which is faster and leaves nothing behind. This
 * exists for the handful of tests that must observe a real COMMIT or ROLLBACK:
 * Database::transaction deliberately joins an outer transaction rather than
 * nesting, so inside inRollback a rollback the code under test performs would
 * not actually happen and the test would be measuring the harness.
 *
 * Order matters — the schema uses RESTRICT wherever losing a row would lose
 * history, so children go first.
 */
function dropSeason(Database $db, int $seasonId, string $memberPrefix): void
{
    $byShift = [
        'DELETE a FROM assignment a JOIN shift s ON s.id = a.shift_id WHERE s.season_id = :s',
        'DELETE c FROM check_event c JOIN shift s ON s.id = c.shift_id WHERE s.season_id = :s',
        'DELETE l FROM lunch_event l JOIN shift s ON s.id = l.shift_id WHERE s.season_id = :s',
        'DELETE v FROM state_version v JOIN shift s ON s.id = v.shift_id WHERE s.season_id = :s',
        'DELETE g FROM shift_group g JOIN shift s ON s.id = g.shift_id WHERE s.season_id = :s',
        'DELETE b FROM broadcast b JOIN shift s ON s.id = b.shift_id WHERE s.season_id = :s',
        'DELETE l FROM audit_log l JOIN shift s ON s.id = l.shift_id WHERE s.season_id = :s',
    ];

    foreach ($byShift as $sql) {
        $db->execute($sql, ['s' => $seasonId]);
    }

    $db->execute('DELETE FROM shift WHERE season_id = :s', ['s' => $seasonId]);
    $db->execute('DELETE FROM team_member WHERE season_id = :s', ['s' => $seasonId]);
    // user_skill and auth_token cascade from user.
    $db->execute('DELETE FROM `user` WHERE member_id LIKE :p', ['p' => $memberPrefix . '%']);
    $db->execute('DELETE FROM team WHERE season_id = :s', ['s' => $seasonId]);
    $db->execute('DELETE FROM season WHERE id = :s', ['s' => $seasonId]);
}

/**
 * Two teams, two shifts, a roster on each, and the shift_group rows a real
 * shift is created with (Admin\Shifts). The counter joins shift_group, so a
 * fixture without it reports a board of nothing and every coverage assertion
 * passes for the wrong reason.
 *
 * @return array{season: int, teamB: int, teamC: int, day: int, night: int, roster: array<int, int>}
 */
function officerFixture(Database $db, string $tag): array
{
    $db->execute(
        'INSERT INTO season (name, start_date, end_date, is_active) VALUES (:n, :s, :e, 0)',
        ['n' => "test-{$tag}", 's' => '2027-02-25', 'e' => '2027-03-21']
    );
    $season = $db->lastInsertId();

    $team = static function (string $name) use ($db, $season): int {
        $db->execute(
            'INSERT INTO team (season_id, name, is_active) VALUES (:s, :n, 1)',
            ['s' => $season, 'n' => $name]
        );

        return $db->lastInsertId();
    };
    $teamB = $team('Team B');
    $teamC = $team('Team C');

    $shift = static function (int $teamId, string $type, string $from, string $to) use ($db, $season): int {
        $db->execute(
            'INSERT INTO shift (season_id, team_id, shift_type, starts_at, ends_at, current_phase)
             VALUES (:s, :t, :ty, :a, :b, :p)',
            [
                's' => $season, 't' => $teamId, 'ty' => $type,
                'a' => utc($from)->format('Y-m-d H:i:s'),
                'b' => utc($to)->format('Y-m-d H:i:s'),
                'p' => $type === 'weekend_night' ? 'bump_run' : 'unload',
            ]
        );
        $id = $db->lastInsertId();

        // What Admin\Shifts writes alongside a new shift: all ten groups
        // active (spec 5.4) and a state_version row for the pollers.
        $db->execute(
            'INSERT INTO shift_group (shift_id, group_id, is_active)
             SELECT :shift, id, 1 FROM position_group',
            ['shift' => $id]
        );
        $db->execute('INSERT INTO state_version (shift_id, version) VALUES (:s, 1)', ['s' => $id]);

        return $id;
    };

    $day = $shift($teamB, 'weekend_day', '2027-03-06 08:00', '2027-03-06 18:00');
    $night = $shift($teamC, 'weekend_night', '2027-03-06 16:45', '2027-03-07 02:00');

    $roster = [];
    for ($i = 1; $i <= 4; $i++) {
        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES (:m, :l, 'Test', '!x', 'committeeman')",
            ['m' => "test-{$tag}-{$i}", 'l' => 'Hand' . $i]
        );
        $id = $db->lastInsertId();
        $roster[] = $id;
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $id, 't' => $teamB, 's' => $season]
        );
    }

    return [
        'season' => $season, 'teamB' => $teamB, 'teamC' => $teamC,
        'day' => $day, 'night' => $night, 'roster' => $roster,
    ];
}

function officerIdentity(Role $role, array $teamIds, int $id = 90001): Identity
{
    return new Identity(
        id: $id, memberId: 'test-officer', firstName: 'Pat', lastName: 'Boone',
        role: $role, isActive: true, tokenId: 1, teamIds: $teamIds,
    );
}

/** Place someone on a named position, the way the board does. */
function officerPlace(
    Database $db,
    int $shift,
    int $user,
    string $phase,
    string $label,
    string $source = 'manual',
): void {
    $db->execute(
        'INSERT INTO assignment (shift_id, phase, position_id, user_id, is_multi, source, is_inherited)
         SELECT :s, :ph, p.id, :u, pp.multi_assign, :src, :inh
           FROM position p
           JOIN position_phase pp ON pp.position_id = p.id AND pp.phase = :ph2
          WHERE p.label = :label',
        [
            's' => $shift, 'ph' => $phase, 'u' => $user, 'label' => $label,
            'ph2' => $phase, 'src' => $source,
            'inh' => $source === 'carry_forward' ? 1 : 0,
        ]
    );
}

/** An officer row that really exists, so assigned_by's foreign key holds. */
function officerUser(Database $db, string $tag): int
{
    $db->execute(
        "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
         VALUES (:m, 'Boone', 'Pat', '!x', 'officer')",
        ['m' => "test-{$tag}-officer"]
    );

    return $db->lastInsertId();
}

/** The shift row assign() needs: id, team and season. */
function shiftRow(Database $db, int $shiftId): array
{
    return (array) $db->one(
        'SELECT id, team_id, season_id, starts_at, ends_at, current_phase FROM shift WHERE id = :id',
        ['id' => $shiftId]
    );
}

function holdsPosition(Database $db, int $shift, string $phase, int $user, string $label): bool
{
    return (int) $db->value(
        'SELECT COUNT(*) FROM assignment a JOIN position p ON p.id = a.position_id
          WHERE a.shift_id = :s AND a.phase = :ph AND a.user_id = :u
            AND p.label = :l AND a.is_current = 1',
        ['s' => $shift, 'ph' => $phase, 'u' => $user, 'l' => $label]
    ) > 0;
}
