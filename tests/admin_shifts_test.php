<?php

declare(strict_types=1);

use Resm\Admin\Shifts;
use Resm\AdminMenu;
use Resm\Auth\Capability;
use Resm\AuditLog;
use Resm\Database;
use Resm\ShiftClock;
use Resm\ShiftType;

/**
 * Creating shifts (spec 6.10.5).
 */
function shiftsFor(Database $db): Shifts
{
    return new Shifts($db, new AuditLog($db), new ShiftClock(new DateTimeZone('America/Chicago')));
}

/**
 * A season with two active teams.
 *
 * @return array{season: int, a: int, b: int}
 */
function shiftFixture(Database $db, string $tag): array
{
    $db->execute(
        'INSERT INTO season (name, start_date, end_date, is_active) VALUES (:n, :s, :e, 0)',
        ['n' => "test-{$tag}", 's' => '2027-02-25', 'e' => '2027-03-21']
    );
    $season = $db->lastInsertId();

    $ids = [];
    foreach (['A', 'B'] as $name) {
        $db->execute(
            'INSERT INTO team (season_id, name, is_active) VALUES (:s, :n, 1)',
            ['s' => $season, 'n' => "Team {$name}"]
        );
        $ids[$name] = $db->lastInsertId();
    }

    return ['season' => $season, 'a' => $ids['A'], 'b' => $ids['B']];
}

/** @return array<int, int> */
function allGroupIds(Database $db): array
{
    return array_map(
        static fn (array $r): int => (int) $r['id'],
        $db->all('SELECT id FROM position_group')
    );
}

// ---------------------------------------------------------------------------
// Creating one shift
// ---------------------------------------------------------------------------

test('a weeknight shift stores UTC and opens in Unload', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'one');
        $groups = allGroupIds($db);

        $result = shiftsFor($db)->create(
            adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-05', '16:45', '02:00', $groups
        );
        assertTrue($result['ok'], (string) $result['error']);

        $row = $db->one('SELECT * FROM shift WHERE id = :id', ['id' => $result['id']]);
        assertSame('2027-03-05 22:45:00', (string) $row['starts_at'], 'CST is UTC-6');
        assertSame('2027-03-06 08:00:00', (string) $row['ends_at'], 'ends the next morning');
        assertSame('unload', (string) $row['current_phase']);
        assertSame(null, $result['notice'], 'an ordinary night has nothing to report');

        assertSame(10, (int) $db->value(
            'SELECT COUNT(*) FROM shift_group WHERE shift_id = :id AND is_active = 1',
            ['id' => $result['id']]
        ), 'all ten groups by default (spec 5.4)');
    });
});

test('a weekend night opens straight into Bump and Run', function (): void {
    // Spec 5.1: the crowd departs early, so the team holds departure positions
    // for the whole shift.
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'wn');
        $result = shiftsFor($db)->create(
            adminActor(), $f['season'], $f['a'], 'weekend_night', '2027-03-06', '16:45', '02:00', allGroupIds($db)
        );

        assertSame('bump_run', (string) $db->value(
            'SELECT current_phase FROM shift WHERE id = :id',
            ['id' => $result['id']]
        ));
    });
});

test('the shift on the night the clocks change is reported, not refused', function (): void {
    // 02:00 does not exist on 14 March 2027. The shift ends at the right
    // instant and the board reads 03:00, which is not what was typed.
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'dst');
        $result = shiftsFor($db)->create(
            adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-13', '16:45', '02:00', allGroupIds($db)
        );

        assertTrue($result['ok'], (string) $result['error']);
        assertTrue($result['notice'] !== null, 'the clock change must be surfaced');
        assertTrue(str_contains((string) $result['notice'], '03:00'), (string) $result['notice']);
        assertSame('2027-03-14 08:00:00', (string) $db->value(
            'SELECT ends_at FROM shift WHERE id = :id',
            ['id' => $result['id']]
        ));
    });
});

test('a shift is refused for a team outside the season, or an inactive one', function (): void {
    inRollback(function (Database $db): void {
        $mine = shiftFixture($db, 'scope-mine');
        $other = shiftFixture($db, 'scope-other');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);

        assertTrue(!$shifts->create(
            adminActor(), $mine['season'], $other['a'], 'weeknight', '2027-03-05', '16:45', '02:00', $groups
        )['ok'], "another season's team");

        $db->execute('UPDATE team SET is_active = 0 WHERE id = :id', ['id' => $mine['b']]);
        assertTrue(!$shifts->create(
            adminActor(), $mine['season'], $mine['b'], 'weeknight', '2027-03-05', '16:45', '02:00', $groups
        )['ok'], 'a retired team');

        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM shift WHERE season_id = :s',
            ['s' => $mine['season']]
        ));
    });
});

test('a bad type, date or time creates nothing', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'bad');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);

        $cases = [
            'bad type' => ['midnight_shift', '2027-03-05', '16:45', '02:00'],
            'bad date' => ['weeknight', '2027-02-31', '16:45', '02:00'],
            'bad start' => ['weeknight', '2027-03-05', '25:00', '02:00'],
            'bad end' => ['weeknight', '2027-03-05', '16:45', 'later'],
        ];

        foreach ($cases as $why => [$type, $date, $start, $end]) {
            assertTrue(
                !$shifts->create(adminActor(), $f['season'], $f['a'], $type, $date, $start, $end, $groups)['ok'],
                "{$why} was accepted"
            );
        }

        assertSame(0, (int) $db->value('SELECT COUNT(*) FROM shift WHERE season_id = :s', ['s' => $f['season']]));
    });
});

test('a shift needs at least one position group', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'nogroups');

        foreach ([[], [999999], ['nonsense']] as $groups) {
            $result = shiftsFor($db)->create(
                adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-05', '16:45', '02:00', $groups
            );
            assertTrue(!$result['ok'], 'an empty group set was accepted');
        }
    });
});

test('creating a shift opens its polling row', function (): void {
    // Otherwise the first client to poll creates it, and two arriving together
    // race for the same primary key.
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'version');
        $result = shiftsFor($db)->create(
            adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-05', '16:45', '02:00', allGroupIds($db)
        );

        assertSame(1, (int) $db->value(
            'SELECT version FROM state_version WHERE shift_id = :id',
            ['id' => $result['id']]
        ));
    });
});

// ---------------------------------------------------------------------------
// Overlaps
// ---------------------------------------------------------------------------

test('an overlapping shift is warned about, not blocked', function (): void {
    // Spec 6.10.5 says warning. Two teams on one night is unusual, not illegal.
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'overlap');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);

        $shifts->create(adminActor(), $f['season'], $f['a'], 'weekend_day', '2027-03-06', '08:00', '18:00', $groups);
        $second = $shifts->create(
            adminActor(), $f['season'], $f['a'], 'weekend_night', '2027-03-06', '16:45', '02:00', $groups
        );

        assertTrue($second['ok'], 'an overlap must not block creation');
        assertTrue($second['notice'] !== null);
        assertTrue(str_contains((string) $second['notice'], 'same hours'), (string) $second['notice']);
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM shift WHERE team_id = :t',
            ['t' => $f['a']]
        ));
    });
});

test('a handover is not an overlap, and another team never is', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'handover');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);

        // Team A works 08:00-16:45, Team A then works 16:45-02:00: they touch.
        $shifts->create(adminActor(), $f['season'], $f['a'], 'weekend_day', '2027-03-06', '08:00', '16:45', $groups);
        $next = $shifts->create(
            adminActor(), $f['season'], $f['a'], 'weekend_night', '2027-03-06', '16:45', '02:00', $groups
        );
        assertSame(null, $next['notice'], 'touching at the edge is a handover');

        // Team B on the very same hours is a different team's problem.
        $other = $shifts->create(
            adminActor(), $f['season'], $f['b'], 'weekend_day', '2027-03-06', '08:00', '16:45', $groups
        );
        assertSame(null, $other['notice']);
    });
});

// ---------------------------------------------------------------------------
// Bulk creation
// ---------------------------------------------------------------------------

test('a range creates one shift per chosen weekday', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'range');

        // 1-14 March 2027 holds two Saturdays and two Sundays.
        $result = shiftsFor($db)->createRange(
            adminActor(), $f['season'], $f['a'], 'weekend_day',
            '2027-03-01', '2027-03-14', [6, 7], '08:00', '18:00', allGroupIds($db)
        );

        assertTrue($result['ok'], (string) $result['error']);
        assertSame(4, $result['created']);
        assertSame(4, (int) $db->value('SELECT COUNT(*) FROM shift WHERE team_id = :t', ['t' => $f['a']]));
        assertSame(40, (int) $db->value(
            'SELECT COUNT(*) FROM shift_group sg JOIN shift s ON s.id = sg.shift_id WHERE s.team_id = :t',
            ['t' => $f['a']]
        ), 'ten groups on each');
    });
});

test('re-running a range leaves the shifts it already made alone', function (): void {
    // The pattern gets extended mid-season; the first three weeks must not
    // double.
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'rerun');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);

        $first = $shifts->createRange(
            adminActor(), $f['season'], $f['a'], 'weekend_day',
            '2027-03-01', '2027-03-07', [6, 7], '08:00', '18:00', $groups
        );
        assertSame(2, $first['created']);

        $again = $shifts->createRange(
            adminActor(), $f['season'], $f['a'], 'weekend_day',
            '2027-03-01', '2027-03-14', [6, 7], '08:00', '18:00', $groups
        );
        assertSame(2, $again['created'], 'only the new fortnight');
        assertSame(2, $again['skipped']);
        assertTrue(str_contains((string) $again['notice'], 'left alone'), (string) $again['notice']);
        assertSame(4, (int) $db->value('SELECT COUNT(*) FROM shift WHERE team_id = :t', ['t' => $f['a']]));
    });
});

test('a range spanning the clock change reports it once and creates every night', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'rangedst');

        // Saturdays 6 and 13 March; the 13th runs into the short night.
        $result = shiftsFor($db)->createRange(
            adminActor(), $f['season'], $f['a'], 'weeknight',
            '2027-03-01', '2027-03-14', [6], '16:45', '02:00', allGroupIds($db)
        );

        assertSame(2, $result['created']);
        assertTrue(str_contains((string) $result['notice'], '03:00'), (string) $result['notice']);

        $ends = $db->all(
            'SELECT ends_at FROM shift WHERE team_id = :t ORDER BY starts_at',
            ['t' => $f['a']]
        );
        assertSame('2027-03-07 08:00:00', (string) $ends[0]['ends_at']);
        assertSame('2027-03-14 08:00:00', (string) $ends[1]['ends_at'], 'same UTC hour, different local one');
    });
});

test('a bad range creates nothing at all', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'badrange');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);

        $cases = [
            'backwards' => ['2027-03-14', '2027-03-01', [6], '16:45', '02:00'],
            'too long' => ['2027-01-01', '2027-12-31', [6], '16:45', '02:00'],
            'no matching day' => ['2027-03-01', '2027-03-04', [6, 7], '16:45', '02:00'],
            'impossible date' => ['2027-02-31', '2027-03-04', [], '16:45', '02:00'],
            'bad time' => ['2027-03-01', '2027-03-07', [], '16:45', '99:99'],
        ];

        foreach ($cases as $why => [$from, $to, $days, $start, $end]) {
            $result = $shifts->createRange(
                adminActor(), $f['season'], $f['a'], 'weeknight', $from, $to, $days, $start, $end, $groups
            );
            assertTrue(!$result['ok'], "{$why} was accepted");
        }

        assertSame(0, (int) $db->value('SELECT COUNT(*) FROM shift WHERE team_id = :t', ['t' => $f['a']]));
    });
});

test('an empty weekday set means every day in the range', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'everyday');

        $result = shiftsFor($db)->createRange(
            adminActor(), $f['season'], $f['a'], 'weeknight',
            '2027-03-01', '2027-03-07', [], '16:45', '02:00', allGroupIds($db)
        );

        assertSame(7, $result['created']);
    });
});

// ---------------------------------------------------------------------------
// Editing and removing
// ---------------------------------------------------------------------------

test('groups can be trimmed after the fact', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'trim');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);
        $id = $shifts->create(
            adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-05', '16:45', '02:00', $groups
        )['id'];

        $keep = array_slice($groups, 0, 6);
        assertTrue($shifts->setGroups(adminActor(), $id, $keep)['ok']);

        $after = $shifts->activeGroupIds($id);
        sort($after);
        sort($keep);
        assertSame($keep, $after);

        // And never to nothing.
        assertTrue(!$shifts->setGroups(adminActor(), $id, [])['ok']);
        assertCount(6, $shifts->activeGroupIds($id));
    });
});

test('an unused shift can be deleted and a worked one cannot', function (): void {
    // Bulk creation makes mistakes thirty at a time, so an untouched shift has
    // to be removable. One with history never is: the season is a record.
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'delete');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);

        $spare = $shifts->create(
            adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-05', '16:45', '02:00', $groups
        )['id'];
        assertTrue($shifts->delete(adminActor(), $spare)['ok']);
        assertSame(0, (int) $db->value('SELECT COUNT(*) FROM shift WHERE id = :id', ['id' => $spare]));

        $worked = $shifts->create(
            adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-06', '16:45', '02:00', $groups
        )['id'];
        $db->execute(
            "INSERT INTO check_event (shift_id, user_id, type, occurred_at)
             VALUES (:s, :u, 'in', :now)",
            ['s' => $worked, 'u' => adminActor()->id, 'now' => gmdate('Y-m-d H:i:s')]
        );

        $refused = $shifts->delete(adminActor(), $worked);
        assertTrue(!$refused['ok']);
        assertTrue(str_contains((string) $refused['error'], 'checked in'));
        assertSame(1, (int) $db->value('SELECT COUNT(*) FROM shift WHERE id = :id', ['id' => $worked]));
    });
});

test('shift changes are written to the audit log', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'audit');
        $shifts = shiftsFor($db);
        $id = $shifts->create(
            adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-05', '16:45', '02:00', allGroupIds($db)
        )['id'];

        $row = $db->one(
            "SELECT actor_id, shift_id, after_json FROM audit_log
             WHERE action = 'shift_create' AND entity_id = :id",
            ['id' => $id]
        );
        assertTrue($row !== null, 'no audit row');
        assertSame($id, (int) $row['shift_id'], 'the shift is named so the log can be read per shift');
        assertTrue(str_contains((string) $row['after_json'], 'weeknight'));

        $shifts->delete(adminActor(), $id);
        $gone = $db->one(
            "SELECT before_json FROM audit_log WHERE action = 'shift_delete' AND entity_id = :id",
            ['id' => $id]
        );
        assertTrue($gone !== null, 'a deletion with no record is not an audit');
        assertTrue(str_contains((string) $gone['before_json'], 'weeknight'));
    });
});

test('the listing carries the counts the screen decides on', function (): void {
    inRollback(function (Database $db): void {
        $f = shiftFixture($db, 'listing');
        $shifts = shiftsFor($db);
        $groups = allGroupIds($db);

        $shifts->create(adminActor(), $f['season'], $f['a'], 'weeknight', '2027-03-05', '16:45', '02:00', $groups);
        $shifts->create(adminActor(), $f['season'], $f['b'], 'weeknight', '2027-03-06', '16:45', '02:00', $groups);

        assertCount(2, $shifts->forSeason($f['season']));

        $justA = $shifts->forSeason($f['season'], $f['a']);
        assertCount(1, $justA);
        assertSame('Team A', (string) $justA[0]['team_name']);
        assertSame(10, (int) $justA[0]['group_count']);
        assertSame(0, (int) $justA[0]['check_count']);
        assertCount(10, Shifts::rowGroupIds($justA[0]));
    });
});

test('the Create Shifts section is built and admin-only', function (): void {
    $section = AdminMenu::section('shifts');
    assertTrue($section !== null);
    assertTrue($section['built'], 'shifts is still a placeholder');
    assertSame(Capability::CreateShifts, $section['capability']);
});

test('every shift type round-trips through the enum', function (): void {
    // The database column is an ENUM of exactly these three.
    foreach (ShiftType::all() as $type) {
        assertSame($type, ShiftType::from($type->value));
    }
    assertSame(null, ShiftType::tryFrom('graveyard'));
});
