<?php

declare(strict_types=1);

use Resm\AuditLog;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Officer\People;
use Resm\Officer\TeamRoster;
use Resm\Officer\Board;

/**
 * What an officer does to a person rather than to the board (spec 6.9.3,
 * 6.9.11) and the two halves of a skill (7.3).
 */

function peopleFor(Database $db): People
{
    // Cost 4 rather than the configured 11: these tests hash a PIN on every
    // walk-on and reset, and bcrypt is deliberately slow.
    return new People($db, new AuditLog($db), 4, '1234');
}

function teamRosterFor(Database $db): TeamRoster
{
    return new TeamRoster($db, new Board($db));
}

function certifiedCodes(Database $db, int $userId): array
{
    return array_map(
        static fn (array $r): string => (string) $r['code'],
        $db->all(
            'SELECT s.code FROM user_skill us JOIN skill s ON s.id = us.skill_id
              WHERE us.user_id = :u AND us.granted_at IS NOT NULL ORDER BY s.sort_order',
            ['u' => $userId]
        )
    );
}

function preferredCodes(Database $db, int $userId): array
{
    return array_map(
        static fn (array $r): string => (string) $r['code'],
        $db->all(
            'SELECT s.code FROM user_skill us JOIN skill s ON s.id = us.skill_id
              WHERE us.user_id = :u AND us.is_preferred = 1 ORDER BY s.sort_order',
            ['u' => $userId]
        )
    );
}

// ---------------------------------------------------------------------------
// Certified and preferred are independent facts (spec 7.3)
// ---------------------------------------------------------------------------

test('an officer certifying somebody leaves his own preferences alone', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-cert');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'pp-cert'));
        [$a] = $f['roster'];
        $subject = officerIdentity(Role::Committeeman, [$f['teamB']], $a);

        $people = peopleFor($db);
        $people->setPreferred($subject, $a, ['radio', 'gate']);
        $people->setCertified($actor, $f['teamB'], $f['season'], $a, ['starter', 'forklift']);

        assertSame(['starter', 'forklift'], certifiedCodes($db, $a));
        assertSame(['radio', 'gate'], preferredCodes($db, $a), 'his preferences survived');
    });
});

test('a man stating a preference does not touch what he is certified for', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-pref');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'pp-pref'));
        [$a] = $f['roster'];
        $subject = officerIdentity(Role::Committeeman, [$f['teamB']], $a);

        $people = peopleFor($db);
        $people->setCertified($actor, $f['teamB'], $f['season'], $a, ['starter']);
        $people->setPreferred($subject, $a, ['counter']);

        assertSame(['starter'], certifiedCodes($db, $a), 'still certified');
        assertSame(['counter'], preferredCodes($db, $a));
    });
});

test('a man may prefer something he is not certified for', function (): void {
    // Spec 7.3: the more useful of the two, because it is a training list
    // nobody had to compile.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-train');
        [$a] = $f['roster'];
        $subject = officerIdentity(Role::Committeeman, [$f['teamB']], $a);

        peopleFor($db)->setPreferred($subject, $a, ['computer']);

        assertSame([], certifiedCodes($db, $a));
        assertSame(['computer'], preferredCodes($db, $a));
    });
});

test('equipment certifications cannot be preferred', function (): void {
    // Spec 7.1: Forklift and Golf Cart correspond to no position, so a
    // preference for one would mean nothing.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-equip');
        [$a] = $f['roster'];
        $subject = officerIdentity(Role::Committeeman, [$f['teamB']], $a);

        peopleFor($db)->setPreferred($subject, $a, ['forklift', 'golfcart', 'radio']);

        assertSame(['radio'], preferredCodes($db, $a), 'only the position skill stuck');
    });
});

test('clearing every skill leaves no row behind', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-clear');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'pp-clear'));
        [$a] = $f['roster'];

        $people = peopleFor($db);
        $people->setCertified($actor, $f['teamB'], $f['season'], $a, ['starter']);
        $people->setCertified($actor, $f['teamB'], $f['season'], $a, []);

        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM user_skill WHERE user_id = :u',
            ['u' => $a]
        ), 'a row saying nothing about him is not a fact');
    });
});

test('an officer cannot certify somebody on another team', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-scope');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'pp-scope'));

        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES ('test-pp-scope-out', 'Stranger', 'Sam', '!x', 'committeeman')"
        );
        $outsider = $db->lastInsertId();
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $outsider, 't' => $f['teamC'], 's' => $f['season']]
        );

        $r = peopleFor($db)->setCertified($actor, $f['teamB'], $f['season'], $outsider, ['starter']);

        assertTrue($r['ok'] === false, 'the route guards the team; this guards the target');
        assertSame([], certifiedCodes($db, $outsider), 'and nothing was written');
    });
});

// ---------------------------------------------------------------------------
// Walk-ons and PIN resets (spec 6.9.3, 6.9.11)
// ---------------------------------------------------------------------------

test('a walk-on joins the team and can be placed straight away', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-walk');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'pp-walk'));

        $r = peopleFor($db)->addWalkon(
            $actor, $f['season'], $f['teamB'], 'Tarmac', 'Walt', '713-555-0199'
        );

        assertTrue($r['ok'], (string) $r['error']);
        $row = $db->one('SELECT is_walkon, member_id, phone_e164, role FROM `user` WHERE id = :id', ['id' => $r['id']]);
        assertSame(1, (int) $row['is_walkon']);
        assertSame(null, $row['member_id'], 'no Member ID to hand on the tarmac');
        assertSame('+17135550199', (string) $row['phone_e164'], 'tap-to-call works immediately');
        assertSame('committeeman', (string) $row['role']);
        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM team_member WHERE user_id = :u AND team_id = :t',
            ['u' => $r['id'], 't' => $f['teamB']]
        ));
    });
});

test('a walk-on needs a name', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-noname');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'pp-noname'));

        $before = (int) $db->value('SELECT COUNT(*) FROM `user`');
        $r = peopleFor($db)->addWalkon($actor, $f['season'], $f['teamB'], '  ', 'Walt');

        assertTrue($r['ok'] === false);
        assertSame($before, (int) $db->value('SELECT COUNT(*) FROM `user`'), 'nothing written');
    });
});

test('resetting a PIN changes the hash and signs the account out everywhere', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-pin');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'pp-pin'));
        [$a] = $f['roster'];

        $db->execute(
            "INSERT INTO auth_token
                 (user_id, selector, verifier_hash, issued_at, last_used_at, expires_at)
             VALUES (:u, :sel, :hash, :now, :used, :expires)",
            [
                'u' => $a,
                'sel' => str_repeat('a', 32),
                'hash' => str_repeat('b', 64),
                'now' => utc('2027-03-06 08:00')->format('Y-m-d H:i:s'),
                'used' => utc('2027-03-06 08:00')->format('Y-m-d H:i:s'),
                'expires' => utc('2027-06-01 00:00')->format('Y-m-d H:i:s'),
            ]
        );

        $before = (string) $db->value('SELECT pin_hash FROM `user` WHERE id = :id', ['id' => $a]);
        $r = peopleFor($db)->resetPin($actor, $f['teamB'], $f['season'], $a);

        assertTrue($r['ok'], (string) $r['error']);
        $after = (string) $db->value('SELECT pin_hash FROM `user` WHERE id = :id', ['id' => $a]);
        assertTrue($before !== $after, 'the hash changed');
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = :u AND revoked_at IS NULL',
            ['u' => $a]
        ), 'every session for that account stopped working');
    });
});

test('an officer cannot reset a PIN on another team', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-pinscope');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'pp-pinscope'));

        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES ('test-pp-pinscope-out', 'Stranger', 'Sam', '!keep', 'committeeman')"
        );
        $outsider = $db->lastInsertId();
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $outsider, 't' => $f['teamC'], 's' => $f['season']]
        );

        $r = peopleFor($db)->resetPin($actor, $f['teamB'], $f['season'], $outsider);

        assertTrue($r['ok'] === false);
        assertSame('!keep', (string) $db->value(
            'SELECT pin_hash FROM `user` WHERE id = :id',
            ['id' => $outsider]
        ), 'his PIN is untouched');
    });
});

// ---------------------------------------------------------------------------
// The shared roster read (spec 6.9.3, 6.9.8, 6.9.9)
// ---------------------------------------------------------------------------

test('the roster tells apart on the tarmac, gone home, and never came', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-states');
        [$a, $b] = $f['roster'];

        checkEvent($db, $f['day'], $a, 'in', '2027-03-06 08:05');
        checkEvent($db, $f['day'], $b, 'in', '2027-03-06 08:05');
        checkEvent($db, $f['day'], $b, 'out', '2027-03-06 14:00');

        $people = teamRosterFor($db)->forShift($f['day'], $f['teamB'], $f['season']);
        $by = [];
        foreach ($people as $person) {
            $by[(int) $person['id']] = $person;
        }

        assertTrue($by[$a]['checked_in'], 'A is on the tarmac');
        assertTrue($by[$a]['absent'] === false);
        assertTrue($by[$b]['has_left'], 'B went home');
        assertTrue($by[$b]['absent'] === false, 'and is not somebody to ring');
        assertTrue($by[$f['roster'][2]]['absent'], 'C never came');
    });
});

test('the roster search matches on last name', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-search');

        $found = teamRosterFor($db)->forShift($f['day'], $f['teamB'], $f['season'], 'hand2');

        assertCount(1, $found);
        assertSame('Hand2', (string) $found[0]['last_name']);
    });
});

test('lunch counts cover all three states', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'pp-lunch');
        [$a, $b] = $f['roster'];

        foreach ([[$a, 'at_lunch'], [$b, 'done']] as [$user, $state]) {
            $db->execute(
                'INSERT INTO lunch_event (shift_id, user_id, state, occurred_at) VALUES (:s, :u, :st, :o)',
                ['s' => $f['day'], 'u' => $user, 'st' => $state,
                 'o' => utc('2027-03-06 12:00')->format('Y-m-d H:i:s')]
            );
        }

        $counts = TeamRoster::lunchCounts(
            teamRosterFor($db)->forShift($f['day'], $f['teamB'], $f['season'])
        );

        assertSame(1, $counts['at_lunch']);
        assertSame(1, $counts['done']);
        assertSame(2, $counts['not_yet'], 'the other two have not gone yet');
    });
});
