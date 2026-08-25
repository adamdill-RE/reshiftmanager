<?php

declare(strict_types=1);

use Resm\Database;
use Resm\Shift\Attendance;
use Resm\Shift\Roster;

/**
 * My Shift Status (spec 6.5) and My Shifts (spec 6.6).
 */
function rosterFor(Database $db): Roster
{
    return new Roster($db);
}

/** @return int the new user's id */
function person(Database $db, string $memberId, string $role, ?string $phone = null): int
{
    $db->execute(
        "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role, phone, phone_e164)
         VALUES (:m, :last, 'Test', '!x', :r, :p, :e)",
        [
            'm' => $memberId,
            'last' => ucfirst($role) . substr($memberId, -2),
            'r' => $role,
            'p' => $phone,
            'e' => $phone === null ? null : Resm\PhoneNumber::normalise($phone),
        ]
    );

    return $db->lastInsertId();
}

test('group mates are the people in your group, not the whole shift', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'mates');
        $near = person($db, 'test-m-near', 'committeeman', '713-555-0111');
        $far = person($db, 'test-m-far', 'committeeman');
        foreach ([$near, $far] as $id) {
            $db->execute('INSERT INTO team_member (user_id, team_id, season_id)
                          SELECT :u, team_id, season_id FROM shift WHERE id = :s',
                ['u' => $id, 's' => $f['day']]);
        }

        placeOn($db, $f['day'], $f['user'], 'unload', 'Reed Starter 1');
        placeOn($db, $f['day'], $near, 'unload', 'Reed/Employee Runner 1');
        placeOn($db, $f['day'], $far, 'unload', 'Holly Hall 1');

        $reedRoad = (int) $db->value("SELECT id FROM position_group WHERE code = 'reed_road'");
        $mates = rosterFor($db)->groupMates($f['day'], 'unload', $f['user'], $reedRoad);

        assertCount(1, $mates, 'only Reed Road, and not himself');
        assertSame($near, (int) $mates[0]['id']);
        assertSame('Reed/Employee Runner 1', (string) $mates[0]['position']);
        assertSame('+17135550111', (string) $mates[0]['phone_e164'], 'the tap-to-call number');
    });
});

test('group mates are per phase, and vacated people drop out', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'matesphase');
        $other = person($db, 'test-m-ph', 'committeeman');
        $db->execute('INSERT INTO team_member (user_id, team_id, season_id)
                      SELECT :u, team_id, season_id FROM shift WHERE id = :s',
            ['u' => $other, 's' => $f['day']]);

        placeOn($db, $f['day'], $other, 'bump_run', 'Reed/Employee Runner 1');
        $reedRoad = (int) $db->value("SELECT id FROM position_group WHERE code = 'reed_road'");

        assertCount(0, rosterFor($db)->groupMates($f['day'], 'unload', $f['user'], $reedRoad), 'wrong phase');
        assertCount(1, rosterFor($db)->groupMates($f['day'], 'bump_run', $f['user'], $reedRoad));

        $db->execute('UPDATE assignment SET is_current = 0 WHERE user_id = :u', ['u' => $other]);
        assertCount(0, rosterFor($db)->groupMates($f['day'], 'bump_run', $f['user'], $reedRoad), 'vacated');
    });
});

test('the officer list is the officers and admins on that team', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'officers');
        $dayTeam = (int) $db->value('SELECT team_id FROM shift WHERE id = :s', ['s' => $f['day']]);
        $nightTeam = (int) $db->value('SELECT team_id FROM shift WHERE id = :s', ['s' => $f['night']]);

        $officer = person($db, 'test-o-1', 'officer', '281-555-0100');
        $admin = person($db, 'test-o-2', 'admin');
        $elsewhere = person($db, 'test-o-3', 'officer');
        $db->execute('INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $officer, 't' => $dayTeam, 's' => $f['season']]);
        $db->execute('INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $admin, 't' => $dayTeam, 's' => $f['season']]);
        $db->execute('INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $elsewhere, 't' => $nightTeam, 's' => $f['season']]);

        $found = rosterFor($db)->officers($f['day']);
        $ids = array_map(static fn (array $o): int => (int) $o['id'], $found);

        assertTrue(in_array($officer, $ids, true), 'the team officer');
        assertTrue(in_array($admin, $ids, true), 'an admin counts as reachable');
        assertTrue(!in_array($elsewhere, $ids, true), "another team's officer");
        assertTrue(!in_array($f['user'], $ids, true), 'committeemen are not officers');
    });
});

test('a deactivated officer is not offered as someone to call', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'offgone');
        $team = (int) $db->value('SELECT team_id FROM shift WHERE id = :s', ['s' => $f['day']]);
        $gone = person($db, 'test-o-x', 'officer');
        $db->execute('INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $gone, 't' => $team, 's' => $f['season']]);

        assertCount(1, rosterFor($db)->officers($f['day']));
        $db->execute('UPDATE `user` SET is_active = 0 WHERE id = :u', ['u' => $gone]);
        assertCount(0, rosterFor($db)->officers($f['day']));
    });
});

// ---------------------------------------------------------------------------
// Lunch (spec 6.9.9)
// ---------------------------------------------------------------------------

test('going to lunch frees the position, coming back does not restore it', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'lunch');
        placeOn($db, $f['day'], $f['user'], 'unload', 'Reed Starter 1');
        $att = attendanceFor($db);

        $gone = $att->setLunch(samActor($f['user']), $f['day'], $f['user'], 'at_lunch', utc('2027-03-06 12:00'));
        assertSame(1, $gone['vacated'], 'a spot held by a man who is eating is not covered');

        $back = $att->setLunch(samActor($f['user']), $f['day'], $f['user'], 'done', utc('2027-03-06 12:30'));
        assertSame(0, $back['vacated']);
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE user_id = :u AND is_current = 1',
            ['u' => $f['user']]
        ), 'the officer places him again deliberately');
    });
});

test('the resolver reports the latest lunch state', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'lunchstate');
        $att = attendanceFor($db);
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 08:00');

        $shifts = currentShiftFor($db);
        assertSame('not_yet', (string) $shifts->forUser($f['user'], $f['season'], utc('2027-03-06 09:00'))['current']['lunch']);

        $att->setLunch(samActor($f['user']), $f['day'], $f['user'], 'at_lunch', utc('2027-03-06 12:00'));
        assertSame('at_lunch', (string) $shifts->forUser($f['user'], $f['season'], utc('2027-03-06 12:05'))['current']['lunch']);

        $att->setLunch(samActor($f['user']), $f['day'], $f['user'], 'done', utc('2027-03-06 12:30'));
        assertSame('done', (string) $shifts->forUser($f['user'], $f['season'], utc('2027-03-06 12:35'))['current']['lunch']);
    });
});

test('an invented lunch state is refused', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'lunchbad');

        foreach (['', 'eating', 'AT_LUNCH'] as $bad) {
            assertTrue(
                !attendanceFor($db)->setLunch(samActor($f['user']), $f['day'], $f['user'], $bad)['ok'],
                "'{$bad}' was accepted"
            );
        }
        assertSame(0, (int) $db->value('SELECT COUNT(*) FROM lunch_event WHERE shift_id = :s', ['s' => $f['day']]));
    });
});

// ---------------------------------------------------------------------------
// My Shifts (spec 6.6)
// ---------------------------------------------------------------------------

test('the season list carries what was actually worked', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'season');
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 07:58');
        checkEvent($db, $f['day'], $f['user'], 'out', '2027-03-06 18:04');

        $all = rosterFor($db)->season($f['user'], $f['season']);
        assertCount(2, $all);

        $worked = array_values(array_filter($all, static fn (array $s): bool => (int) $s['id'] === $f['day']))[0];
        assertSame('2027-03-06 13:58:00', (string) $worked['first_in'], 'stored UTC');
        assertSame('2027-03-07 00:04:00', (string) $worked['last_out']);

        $notWorked = array_values(array_filter($all, static fn (array $s): bool => (int) $s['id'] === $f['night']))[0];
        assertSame(null, $notWorked['first_in']);
    });
});

test('a shift with no check-out is visible as such', function (): void {
    // The row My Shifts marks, and the one an officer chases up.
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'noout');
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 08:00');

        $worked = array_values(array_filter(
            rosterFor($db)->season($f['user'], $f['season']),
            static fn (array $s): bool => (int) $s['id'] === $f['day']
        ))[0];

        assertTrue($worked['first_in'] !== null);
        assertSame(null, $worked['last_out'], 'he never checked out');
    });
});

test('the season list is only the teams he is on', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'seasonscope');
        assertCount(2, rosterFor($db)->season($f['user'], $f['season']));

        $db->execute(
            'DELETE FROM team_member WHERE user_id = :u AND team_id = (SELECT team_id FROM shift WHERE id = :s)',
            ['u' => $f['user'], 's' => $f['night']]
        );
        assertCount(1, rosterFor($db)->season($f['user'], $f['season']));
    });
});

test('every committeeman screen is built', function (): void {
    foreach (['check-in', 'my-shift', 'my-shifts', 'tools'] as $key) {
        $section = Resm\Menu::section($key);
        assertTrue($section !== null, "no section '{$key}'");
        assertTrue($section['built'], "{$key} is still a placeholder");
    }
});
