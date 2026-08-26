<?php

declare(strict_types=1);

use Resm\Database;
use Resm\Shift\CurrentShift;
use Resm\Shift\Window;

/**
 * Which shift a user is on (spec 5.3 and 5.5).
 *
 * The dual-team Saturday is what these are really about: Team B running
 * 08:00–18:00 against Team C running 16:45–02:00 leaves 75 minutes where both
 * are live, and the rule has to pick one without hiding the other.
 */
function currentShiftFor(Database $db): CurrentShift
{
    return new CurrentShift($db, chicago());
}

/**
 * Sam, on Team B and Team C, with an overlapping Saturday.
 *
 * @return array{user: int, season: int, day: int, night: int}
 */
function dualFixture(Database $db, string $tag): array
{
    $db->execute(
        'INSERT INTO season (name, start_date, end_date, is_active) VALUES (:n, :s, :e, 0)',
        ['n' => "test-{$tag}", 's' => '2027-02-25', 'e' => '2027-03-21']
    );
    $season = $db->lastInsertId();

    $teams = [];
    foreach (['Team B', 'Team C'] as $name) {
        $db->execute(
            'INSERT INTO team (season_id, name, is_active) VALUES (:s, :n, 1)',
            ['s' => $season, 'n' => $name]
        );
        $teams[$name] = $db->lastInsertId();
    }

    $db->execute(
        "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
         VALUES (:m, 'Hardy', 'Sam', '!x', 'committeeman')",
        ['m' => "test-{$tag}-sam"]
    );
    $user = $db->lastInsertId();

    foreach ($teams as $teamId) {
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $user, 't' => $teamId, 's' => $season]
        );
    }

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

        return $db->lastInsertId();
    };

    return [
        'user' => $user,
        'season' => $season,
        'day' => $shift($teams['Team B'], 'weekend_day', '2027-03-06 08:00', '2027-03-06 18:00'),
        'night' => $shift($teams['Team C'], 'weekend_night', '2027-03-06 16:45', '2027-03-07 02:00'),
    ];
}


// ---------------------------------------------------------------------------
// The window (spec 5.3)
// ---------------------------------------------------------------------------

test('the window runs midnight on the start date to 04:00 the next day', function (): void {
    // The spec's own example: a shift starting 16:45 on 1 March.
    $window = new Window(chicago());
    $start = utc('2027-03-01 16:45');

    assertSame('2027-03-01 00:00', $window->opensFor($start)->setTimezone(chicago())->format('Y-m-d H:i'));
    assertSame('2027-03-02 04:00', $window->closesFor($start)->setTimezone(chicago())->format('Y-m-d H:i'));

    assertTrue($window->contains($start, utc('2027-03-01 00:00')), 'open at midnight');
    assertTrue($window->contains($start, utc('2027-03-02 03:59')), 'still open at 03:59');
    assertTrue(!$window->contains($start, utc('2027-02-28 23:59')), 'not the night before');
    assertTrue(!$window->contains($start, utc('2027-03-02 04:01')), 'shut after 04:00');
});

test('the window is a local date, so the clock change does not move it', function (): void {
    // 14 March 2027 is 23 hours long. Computing midnight by arithmetic rather
    // than from the date parts lands an hour out.
    $window = new Window(chicago());
    $start = utc('2027-03-14 16:45');

    assertSame('2027-03-14 00:00', $window->opensFor($start)->setTimezone(chicago())->format('Y-m-d H:i'));
    assertSame('2027-03-15 04:00', $window->closesFor($start)->setTimezone(chicago())->format('Y-m-d H:i'));
});

// ---------------------------------------------------------------------------
// Resolution (spec 5.5)
// ---------------------------------------------------------------------------

test('with one shift, it is simply the current one', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'single');
        $db->execute('DELETE FROM shift WHERE id = :id', ['id' => $f['night']]);

        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-06 12:00'));
        assertSame($f['day'], (int) $r['current']['id']);
        assertTrue(!$r['doubled']);
    });
});

test('before either starts, the one starting soonest is offered', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'early');

        // 06:00, in the window since midnight, nothing checked into yet.
        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-06 06:00'));
        assertCount(2, $r['candidates']);
        assertSame($f['day'], (int) $r['current']['id'], 'the day shift starts first');
        assertTrue(!$r['doubled']);
    });
});

test('where he is checked in beats where he is scheduled', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'checkedin');
        // He skipped the day shift and went straight to the night one.
        checkEvent($db, $f['night'], $f['user'], 'in', '2027-03-06 16:45');

        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-06 17:00'));
        assertSame($f['night'], (int) $r['current']['id'], 'the earlier start must not win here');
        assertTrue(!$r['doubled']);
    });
});

test('checked into both at once is surfaced, not resolved quietly', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'both');
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 08:00');
        checkEvent($db, $f['night'], $f['user'], 'in', '2027-03-06 16:45');

        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-06 17:00'));
        assertTrue($r['doubled'], 'being in two places at once must be reported');
        assertSame($f['day'], (int) $r['current']['id'], 'the earlier start breaks the tie');
    });
});

test('checking out moves him on to the second shift', function (): void {
    // The handover the whole rule exists for.
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'handover');
        $shifts = currentShiftFor($db);
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 08:00');
        checkEvent($db, $f['night'], $f['user'], 'in', '2027-03-06 16:45');

        // 17:00 — still on Team B.
        assertSame($f['day'], (int) $shifts->forUser($f['user'], $f['season'], utc('2027-03-06 17:00'))['current']['id']);

        // 17:50 — he checks out of Team B and walks to OST.
        checkEvent($db, $f['day'], $f['user'], 'out', '2027-03-06 17:50');

        $after = $shifts->forUser($f['user'], $f['season'], utc('2027-03-06 17:55'));
        assertSame($f['night'], (int) $after['current']['id'], 'check-out is what moves him on');
        assertTrue(!$after['doubled']);
    });
});

test('a forgotten check-out does not pin him to a dead shift', function (): void {
    // The backstop. He never checked out of the day shift, which ended at 18:00.
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'forgot');
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 08:00');

        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-06 20:00'));
        assertSame($f['night'], (int) $r['current']['id'], 'the ended shift must not stay current');
    });
});

test('an ended shift stays reachable so he can still check out of it', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'reachable');
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 08:00');

        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-06 20:00'));

        $ids = array_map(static fn (array $s): int => (int) $s['id'], $r['candidates']);
        assertTrue(in_array($f['day'], $ids, true), 'the finished shift is still in the window');

        $ended = array_values(array_filter($r['candidates'], static fn (array $s): bool => (int) $s['id'] === $f['day']));
        assertTrue($ended[0]['has_ended'], 'and is marked as finished');
        assertTrue($ended[0]['checked_in'], 'and still shows him checked in');
    });
});

test('when everything has ended there is no current shift', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'over');

        // 03:00 Sunday: both have finished, both still inside the window.
        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-07 03:00'));
        assertSame(null, $r['current']);
        assertCount(2, $r['candidates'], 'still reachable until 04:00');
    });
});

test('outside the window there is nothing at all', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'outside');
        $shifts = currentShiftFor($db);

        assertCount(0, $shifts->forUser($f['user'], $f['season'], utc('2027-03-05 12:00'))['candidates'], 'the day before');
        assertCount(0, $shifts->forUser($f['user'], $f['season'], utc('2027-03-07 05:00'))['candidates'], 'after 04:00');
    });
});

test('a shift belonging to a team he is not on is never his', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'notmine');
        $db->execute(
            'DELETE FROM team_member WHERE user_id = :u AND team_id = (SELECT team_id FROM shift WHERE id = :s)',
            ['u' => $f['user'], 's' => $f['night']]
        );

        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-06 17:00'));
        assertCount(1, $r['candidates']);
        assertSame($f['day'], (int) $r['current']['id']);
    });
});

test('the switcher cannot reach a shift that is not his', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'pick');
        $shifts = currentShiftFor($db);
        $at = utc('2027-03-06 17:00');

        assertTrue($shifts->pick($f['user'], $f['season'], $f['night'], $at) !== null, 'his own shift');
        assertSame(null, $shifts->pick($f['user'], $f['season'], 999999, $at), 'one that does not exist');

        // Someone else's team, by editing the URL.
        $db->execute('DELETE FROM team_member WHERE user_id = :u', ['u' => $f['user']]);
        assertSame(null, $shifts->pick($f['user'], $f['season'], $f['day'], $at), 'a team he left');
    });
});

test('the latest check event wins, however they were entered', function (): void {
    // check_event is append-only: a mis-tap corrected a second later leaves
    // both rows, and the newer one is the truth.
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'latest');
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 08:00');
        checkEvent($db, $f['day'], $f['user'], 'out', '2027-03-06 08:01');
        checkEvent($db, $f['day'], $f['user'], 'in', '2027-03-06 08:02');

        $r = currentShiftFor($db)->forUser($f['user'], $f['season'], utc('2027-03-06 12:00'));
        assertTrue($r['current']['checked_in'], 'the last event was a check-in');
    });
});
