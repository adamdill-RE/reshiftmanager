<?php

declare(strict_types=1);

use Resm\Auth\Identity;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Poll\State;
use Resm\Shift\Window;

/**
 * The polling read side (spec 10.2).
 *
 * The write side has been covered since phase 3 — assign_test, officer_test,
 * attendance_test and copy_test each assert that their change bumps
 * state_version. What is tested here is who is allowed to read it, because
 * that check is folded into the same statement as the read and a fold is
 * exactly the sort of thing that quietly stops checking.
 */

function pollFor(Database $db): State
{
    return new State($db, new Window(new DateTimeZone('America/Chicago')));
}

function committeeman(int $id, array $teamIds, bool $active = true): Identity
{
    return new Identity(
        id: $id, memberId: 'test-hand', firstName: 'Al', lastName: 'Hand',
        role: Role::Committeeman, isActive: $active, tokenId: 1, teamIds: $teamIds,
    );
}

// ---------------------------------------------------------------------------
// Who may read a shift's version
// ---------------------------------------------------------------------------

test('a committeeman on the team reads his shift version', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-own');

        assertSame(1, pollFor($db)->version(committeeman($f['roster'][0], [$f['teamB']]), $f['day']));
    });
});

test('a committeeman may not read another team\'s shift', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-other');

        // The roster is on team B; the night shift belongs to team C. Naming
        // its id is the whole attack, and it has to answer nothing.
        assertSame(null, pollFor($db)->version(committeeman($f['roster'][0], [$f['teamB']]), $f['night']));
    });
});

test('a shift that does not exist answers exactly as one that is refused', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-ghost');
        $poll = pollFor($db);
        $user = committeeman($f['roster'][0], [$f['teamB']]);

        // Both null, and the route turns both into the same 404. Telling them
        // apart would make this endpoint a way to enumerate shifts by id.
        assertSame(null, $poll->version($user, 99999999));
        assertSame(null, $poll->version($user, $f['night']));
    });
});

test('an officer reads the version for a team he runs and no other', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-off');
        $officer = officerUser($db, 'poll-off');
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $officer, 't' => $f['teamB'], 's' => $f['season']]
        );

        $identity = officerIdentity(Role::Officer, [$f['teamB']], $officer);
        $poll = pollFor($db);

        assertSame(1, $poll->version($identity, $f['day']), 'his own team');
        assertSame(null, $poll->version($identity, $f['night']), 'not team C');
    });
});

test('an admin reads any shift without being on its team', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-admin');

        // No team_member row anywhere for this account: an Admin is not
        // limited to their assignments (spec 2.2), and the membership join
        // would otherwise refuse them every board in the season.
        $admin = officerIdentity(Role::Admin, [], 90002);
        $poll = pollFor($db);

        assertSame(1, $poll->version($admin, $f['day']));
        assertSame(1, $poll->version($admin, $f['night']));
    });
});

test('a membership carrying another season does not reach this season\'s shift', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-stale');

        // team_member.season_id is denormalised — the primary key is
        // (user_id, team_id) and nothing constrains the season against
        // team.season_id — so a row pairing this team with a different season
        // is something the schema will accept. It is the shape a membership
        // left over from last year takes, and Identity::teamIds is loaded
        // without a season filter, so the team list alone would let it through.
        $db->execute(
            'INSERT INTO season (name, start_date, end_date, is_active) VALUES (:n, :s, :e, 0)',
            ['n' => 'test-poll-stale-prior', 's' => '2026-02-25', 'e' => '2026-03-21']
        );
        $prior = $db->lastInsertId();

        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES ('test-poll-stale-x', 'Lapsed', 'Ray', '!x', 'committeeman')"
        );
        $lapsed = $db->lastInsertId();

        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $lapsed, 't' => $f['teamB'], 's' => $prior]
        );

        assertSame(null, pollFor($db)->version(committeeman($lapsed, [$f['teamB']]), $f['day']));
    });
});

test('a deactivated account reads nothing, whatever its role', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-dead');

        $gone = new Identity(
            id: 90003, memberId: 'test-gone', firstName: 'Gone', lastName: 'Away',
            role: Role::Admin, isActive: false, tokenId: 1, teamIds: [$f['teamB']],
        );

        assertSame(null, pollFor($db)->version($gone, $f['day']));
    });
});

// ---------------------------------------------------------------------------
// What the version is for
// ---------------------------------------------------------------------------

test('the read side sees a bump the write side made', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-bump');
        $poll = pollFor($db);
        $user = committeeman($f['roster'][0], [$f['teamB']]);

        $before = $poll->version($user, $f['day']);
        $db->execute('UPDATE state_version SET version = version + 1 WHERE shift_id = :s', ['s' => $f['day']]);

        assertSame($before + 1, $poll->version($user, $f['day']));
    });
});

test('a bump on one shift does not move another', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-isolate');
        $poll = pollFor($db);
        $admin = officerIdentity(Role::Admin, [], 90004);

        $db->execute('UPDATE state_version SET version = version + 1 WHERE shift_id = :s', ['s' => $f['day']]);

        assertSame(2, $poll->version($admin, $f['day']));
        assertSame(1, $poll->version($admin, $f['night']), 'team C has not moved');
    });
});

// ---------------------------------------------------------------------------
// When a client should stop asking (spec 5.3)
// ---------------------------------------------------------------------------

test('a shift closes at 04:00 the day after it starts', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-close');

        // The day shift starts 2027-03-06 08:00 local, so it stops being
        // reachable at 04:00 on the 7th — the same window a check-in uses,
        // because a client polling past it is asking about a shift it can no
        // longer act on.
        // Compared as an instant, not as text: closesFor returns the moment
        // in the display zone, so the same second reads -06:00 there and Z
        // here. The client parses it either way; a test that compared the
        // strings would be asserting a formatting choice.
        assertSame(
            utc('2027-03-07 04:00')->getTimestamp(),
            pollFor($db)->closesAt($f['day'])?->getTimestamp()
        );
    });
});

test('an overnight shift closes on the window, not on its own end time', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'poll-night');

        // The night shift runs 16:45 on the 6th to 02:00 on the 7th. The
        // window is defined from the START date's midnight (spec 5.3), so it
        // closes at 04:00 on the 7th — two hours after the shift ends, which
        // is the margin a man checking out late relies on.
        assertSame(
            utc('2027-03-07 04:00')->getTimestamp(),
            pollFor($db)->closesAt($f['night'])?->getTimestamp()
        );
    });
});

test('closesAt on a shift that does not exist is null, not an exception', function (): void {
    inRollback(function (Database $db): void {
        assertSame(null, pollFor($db)->closesAt(99999999));
    });
});

// ---------------------------------------------------------------------------
// Which shift a screen polls
// ---------------------------------------------------------------------------

test('officer screens name their own shift to poll', function (): void {
    $routes = (string) file_get_contents(dirname(__DIR__) . '/app/routes-officer.php');

    // Read as source because every officer screen funnels through
    // officerContext, which the suite cannot load without registering the
    // whole route table — and because the failure is silent. An officer is
    // usually not checked into the shift whose board he is running, so without
    // this the layout falls back to his status strip, finds nothing, and the
    // assign board becomes the one screen in the application that never
    // notices anything change. Nothing errors; it just stops updating.
    assertTrue(str_contains($routes, "'pollShift' =>"), 'officerContext must set pollShift');
    assertTrue(str_contains($routes, "js/board.js"), 'and load the subscriber that acts on it');
});

test('the layout prefers a named shift over the status strip\'s', function (): void {
    $layout = (string) file_get_contents(dirname(__DIR__) . '/app/views/layout.php');

    // $pollShift ?? widget — that order, not the reverse. An officer running
    // team B's board while checked into team C's own shift would otherwise
    // poll the wrong one and be told nothing had changed, truthfully, about a
    // shift he was not looking at.
    assertTrue(
        (bool) preg_match('/\$pollShift\s*\?\?\s*\(\$widget/', $layout),
        'the named shift must win over the widget'
    );
});
