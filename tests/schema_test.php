<?php

declare(strict_types=1);

use Resm\Database;

/** Insert the minimum a shift needs and return its id. */
function fixtureShift(Database $db): array
{
    $db->execute("INSERT INTO `user` (member_id, last_name, first_name, pin_hash)
                  VALUES ('t-9001', 'Alpha', 'Ann', 'x')");
    $one = $db->lastInsertId();
    $db->execute("INSERT INTO `user` (member_id, last_name, first_name, pin_hash)
                  VALUES ('t-9002', 'Bravo', 'Bo', 'x')");
    $two = $db->lastInsertId();

    $db->execute("INSERT INTO season (name, start_date, end_date, is_active)
                  VALUES ('test-season', '2026-02-01', '2026-03-22', 0)");
    $season = $db->lastInsertId();

    $db->execute('INSERT INTO team (season_id, name) VALUES (:s, :n)', ['s' => $season, 'n' => 'test-team']);
    $team = $db->lastInsertId();

    $db->execute(
        "INSERT INTO shift (season_id, team_id, shift_type, starts_at, ends_at)
         VALUES (:s, :t, 'weeknight', '2026-03-02 22:45:00', '2026-03-03 08:00:00')",
        ['s' => $season, 't' => $team]
    );

    return ['shift' => $db->lastInsertId(), 'user_one' => $one, 'user_two' => $two];
}

function assign(Database $db, int $shift, string $phase, int $position, int $user, bool $multi = false): void
{
    $db->execute(
        'INSERT INTO assignment (shift_id, phase, position_id, user_id, is_multi)
         VALUES (:shift, :phase, :position, :user, :multi)',
        ['shift' => $shift, 'phase' => $phase, 'position' => $position, 'user' => $user, 'multi' => $multi ? 1 : 0]
    );
}

// ---------------------------------------------------------------------------
// Reference data — the counts the spec states for itself (section 8)
// ---------------------------------------------------------------------------

test('the position matrix seeds 98 positions in 10 groups', function (): void {
    $db = testDb();
    assertSame(10, (int) $db->value('SELECT COUNT(*) FROM position_group'), 'groups');
    assertSame(98, (int) $db->value('SELECT COUNT(*) FROM position'), 'positions');
});

test('157 position-phase records split 62 Unload and 95 Bump and Run', function (): void {
    $db = testDb();
    assertSame(157, (int) $db->value('SELECT COUNT(*) FROM position_phase'));
    assertSame(62, (int) $db->value("SELECT COUNT(*) FROM position_phase WHERE phase = 'unload'"));
    assertSame(95, (int) $db->value("SELECT COUNT(*) FROM position_phase WHERE phase = 'bump_run'"));
});

test('22 positions need a radio', function (): void {
    assertSame(22, (int) testDb()->value('SELECT COUNT(*) FROM position WHERE is_radio = 1'));
});

// Criticality moved out of this file when open item 4 came back and changed it
// from 23 positions to 39 (migration 004). It is asserted against the spec
// table itself in position_matrix_test.php, so there is one place to change
// when Rodeo Express revises the set again — not two that can disagree.

test('only the three Unload group positions accept multiple people', function (): void {
    $db = testDb();
    $rows = $db->all(
        "SELECT p.label FROM position_phase pp
         JOIN position p ON p.id = pp.position_id
         JOIN position_group g ON g.id = p.group_id
         WHERE pp.multi_assign = 1 AND g.code <> 'unload'"
    );
    assertCount(0, $rows, 'multi-assign outside the Unload group');
    assertSame(3, (int) $db->value('SELECT COUNT(*) FROM position_phase WHERE multi_assign = 1'));
});

test('group sizes match the section 8.1 summary', function (): void {
    $expected = [
        'general' => [16, 16, 16], 'naomi_crosswalk' => [13, 13, 13],
        'holly_hall_crosswalk' => [6, 6, 6], 'reed_road' => [15, 15, 15],
        'gold_badge_lt' => [9, 9, 9], 'unload' => [3, 3, 0],
        'ost' => [11, 0, 11], 'west_loop' => [9, 0, 9],
        'monroe' => [9, 0, 9], 'maxey' => [7, 0, 7],
    ];

    foreach (testDb()->all(
        "SELECT g.code,
                COUNT(DISTINCT p.id) AS positions,
                SUM(pp.phase = 'unload') AS unload,
                SUM(pp.phase = 'bump_run') AS bump_run
         FROM position_group g
         JOIN position p ON p.group_id = g.id
         JOIN position_phase pp ON pp.position_id = p.id
         GROUP BY g.id"
    ) as $row) {
        $code = (string) $row['code'];
        assertSame($expected[$code][0], (int) $row['positions'], "{$code} positions");
        assertSame($expected[$code][1], (int) $row['unload'], "{$code} unload");
        assertSame($expected[$code][2], (int) $row['bump_run'], "{$code} bump_run");
    }
});

test('the five shared groups carry forward and the others do not', function (): void {
    $carrying = testDb()->all(
        'SELECT DISTINCT g.code FROM position_phase pp
         JOIN position p ON p.id = pp.position_id
         JOIN position_group g ON g.id = p.group_id
         WHERE pp.carry_forward = 1 ORDER BY g.code'
    );
    assertSame(
        ['general', 'gold_badge_lt', 'holly_hall_crosswalk', 'naomi_crosswalk', 'reed_road'],
        array_column($carrying, 'code')
    );
});

test('ten skills are seeded, in two kinds', function (): void {
    // Eight from migration 002, plus Crosswalk Perimeter and Gate from 005.
    // The mapping and the chip order are asserted in position_matrix_test.php,
    // against the rule in spec 7.2.
    $db = testDb();
    assertSame(10, (int) $db->value('SELECT COUNT(*) FROM skill'));
    assertSame(8, (int) $db->value("SELECT COUNT(*) FROM skill WHERE kind = 'position'"));
    assertSame(2, (int) $db->value("SELECT COUNT(*) FROM skill WHERE kind = 'equipment'"));
});

// ---------------------------------------------------------------------------
// Assignment rules the database enforces (spec 6.9.4 and 10.4)
// ---------------------------------------------------------------------------

test('a person cannot hold two positions in the same phase', function (): void {
    inRollback(function (Database $db): void {
        $f = fixtureShift($db);
        assign($db, $f['shift'], 'unload', 1, $f['user_one']);
        assertThrows(PDOException::class, static function () use ($db, $f): void {
            assign($db, $f['shift'], 'unload', 2, $f['user_one']);
        }, 'second position in the same phase');
    });
});

test('two people cannot hold the same one-to-one position', function (): void {
    inRollback(function (Database $db): void {
        $f = fixtureShift($db);
        assign($db, $f['shift'], 'bump_run', 1, $f['user_one']);
        assertThrows(PDOException::class, static function () use ($db, $f): void {
            assign($db, $f['shift'], 'bump_run', 1, $f['user_two']);
        }, 'two people on one position');
    });
});

test('the same person may hold different positions in each phase', function (): void {
    inRollback(function (Database $db): void {
        $f = fixtureShift($db);
        assign($db, $f['shift'], 'unload', 1, $f['user_one']);
        assign($db, $f['shift'], 'bump_run', 2, $f['user_one']);
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE shift_id = :s AND is_current = 1',
            ['s' => $f['shift']]
        ));
    });
});

test('a multi-assign position holds several people, but each only once', function (): void {
    inRollback(function (Database $db): void {
        $f = fixtureShift($db);
        $multi = (int) $db->value(
            "SELECT position_id FROM position_phase WHERE multi_assign = 1 AND phase = 'unload' LIMIT 1"
        );

        assign($db, $f['shift'], 'unload', $multi, $f['user_one'], true);
        assign($db, $f['shift'], 'unload', $multi, $f['user_two'], true);
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE position_id = :p AND is_current = 1',
            ['p' => $multi]
        ));

        assertThrows(PDOException::class, static function () use ($db, $f, $multi): void {
            assign($db, $f['shift'], 'unload', $multi, $f['user_one'], true);
        }, 'same person twice on a multi position');
    });
});

test('vacating frees the slot and keeps the history', function (): void {
    inRollback(function (Database $db): void {
        $f = fixtureShift($db);
        assign($db, $f['shift'], 'unload', 1, $f['user_one']);

        $db->execute(
            'UPDATE assignment SET is_current = 0, vacated_at = UTC_TIMESTAMP()
             WHERE shift_id = :s AND user_id = :u',
            ['s' => $f['shift'], 'u' => $f['user_one']]
        );

        // Someone else may now take the position, and the vacated row survives.
        assign($db, $f['shift'], 'unload', 1, $f['user_two']);
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE shift_id = :s',
            ['s' => $f['shift']]
        ));
    });
});

// ---------------------------------------------------------------------------
// Connection settings the rest of the app assumes
// ---------------------------------------------------------------------------

test('the connection stores timestamps in UTC', function (): void {
    // If this drifts, every CURRENT_TIMESTAMP default silently records local
    // time and shifts spanning the March DST change stop making sense.
    $offset = (string) testDb()->value('SELECT @@session.time_zone');
    assertSame('+00:00', $offset);
});

test('tables are utf8mb4_unicode_ci', function (): void {
    $wrong = testDb()->all(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_collation <> 'utf8mb4_unicode_ci'"
    );
    assertCount(0, $wrong, 'tables with an unexpected collation');
});

test('the committed seed still matches the specification', function (): void {
    $script = dirname(__DIR__) . '/bin/gen-position-seed.php';
    exec(sprintf('%s %s --check 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $code);
    assertSame(0, $code, implode("\n", $output));
});
