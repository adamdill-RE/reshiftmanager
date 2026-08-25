<?php

declare(strict_types=1);

use Resm\Admin\Users;
use Resm\AdminMenu;
use Resm\Auth\Capability;
use Resm\Auth\Pin;
use Resm\Auth\Role;
use Resm\AuditLog;
use Resm\Database;

/**
 * Creating users by hand (spec 6.10.6 and 6.10.7).
 *
 * Cost 4 rather than the configured 11 throughout: these tests create a lot of
 * accounts and bcrypt at 11 is deliberately slow. Nothing here is about how
 * expensive the hash is — auth_test.php owns that.
 */
function usersFor(Database $db, string $defaultPin = '1234'): Users
{
    return new Users($db, new AuditLog($db), 4, $defaultPin);
}

/**
 * A season with two active teams and one deactivated one, so team scoping has
 * something to be wrong about.
 *
 * @return array{season: int, a: int, b: int, retired: int}
 */
function userFixture(Database $db, string $tag): array
{
    $db->execute(
        'INSERT INTO season (name, start_date, end_date, is_active) VALUES (:n, :s, :e, 0)',
        ['n' => "test-{$tag}", 's' => '2099-02-01', 'e' => '2099-03-01']
    );
    $season = $db->lastInsertId();

    $ids = [];
    foreach (['A' => 1, 'B' => 1, 'Retired' => 0] as $name => $active) {
        $db->execute(
            'INSERT INTO team (season_id, name, is_active) VALUES (:s, :n, :a)',
            ['s' => $season, 'n' => "Team {$name}", 'a' => $active]
        );
        $ids[$name] = $db->lastInsertId();
    }

    return ['season' => $season, 'a' => $ids['A'], 'b' => $ids['B'], 'retired' => $ids['Retired']];
}

// ---------------------------------------------------------------------------
// Creating
// ---------------------------------------------------------------------------

test('a committeeman is created active, on the chosen teams, with the default PIN', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'create');

        $result = usersFor($db)->create(
            adminActor(),
            $f['season'],
            [Role::Committeeman],
            'test-1001',
            'Smith',
            'Al',
            'committeeman',
            '(713) 555-0142',
            'al@example.com',
            [$f['a'], $f['b']],
        );

        assertTrue($result['ok'], (string) $result['error']);

        $row = $db->one("SELECT * FROM `user` WHERE member_id = 'test-1001'");
        assertTrue($row !== null, 'no row was written');
        assertSame('committeeman', (string) $row['role']);
        assertSame(1, (int) $row['is_active']);
        assertSame(0, (int) $row['is_walkon'], 'a hand-created account is not a walk-on');
        assertTrue(Pin::verify('1234', (string) $row['pin_hash']), 'PIN must default to 1234 (spec 6.10.7)');

        // The typed form is kept for display; the E.164 form is what tel:
        // links use.
        assertSame('(713) 555-0142', (string) $row['phone']);
        assertSame('+17135550142', (string) $row['phone_e164']);

        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM team_member WHERE user_id = :u AND season_id = :s',
            ['u' => $result['id'], 's' => $f['season']]
        ));
    });
});

test('a duplicate Member ID is refused by name, not by SQL error', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'dup');
        $users = usersFor($db);

        $args = [adminActor(), $f['season'], [Role::Committeeman], 'test-dup', 'Smith', 'Al', 'committeeman', '', '', []];
        assertTrue($users->create(...$args)['ok']);

        $again = $users->create(...$args);
        assertTrue(!$again['ok'], 'the second create must fail');
        assertTrue(str_contains((string) $again['error'], 'test-dup'), 'the message must name the ID');
        assertTrue(str_contains((string) $again['error'], 'already in use'));
    });
});

test('the committeeman screen cannot be posted into an admin account', function (): void {
    // The role arrives in a form field. It is a request, not an instruction.
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'escalate');

        foreach (['admin', 'officer', 'nonsense', ''] as $attempt) {
            $result = usersFor($db)->create(
                adminActor(), $f['season'], [Role::Committeeman],
                'test-esc', 'Smith', 'Al', $attempt, '', '', []
            );
            assertTrue(!$result['ok'], "role '{$attempt}' was accepted");
        }

        assertSame(0, (int) $db->value("SELECT COUNT(*) FROM `user` WHERE member_id = 'test-esc'"));
    });
});

test('the officer screen creates officers and admins, and nothing else', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'officer');
        $users = usersFor($db);
        $allowed = [Role::Officer, Role::Admin];

        assertTrue($users->create(adminActor(), $f['season'], $allowed, 'test-off', 'Reed', 'Jo', 'officer', '', '', [])['ok']);
        assertTrue($users->create(adminActor(), $f['season'], $allowed, 'test-adm', 'Dill', 'Sam', 'admin', '', '', [])['ok']);
        assertTrue(
            !$users->create(adminActor(), $f['season'], $allowed, 'test-com', 'Lee', 'Pat', 'committeeman', '', '', [])['ok'],
            'committeeman is not on offer here'
        );
    });
});

test('a team outside the season, or retired, is dropped rather than written', function (): void {
    inRollback(function (Database $db): void {
        $mine = userFixture($db, 'scope-mine');
        $other = userFixture($db, 'scope-other');

        $result = usersFor($db)->create(
            adminActor(), $mine['season'], [Role::Committeeman],
            'test-scope', 'Smith', 'Al', 'committeeman', '', '',
            // A real team, another season's team, a retired one, and junk.
            [$mine['a'], $other['a'], $mine['retired'], 999999, 'nope'],
        );

        assertTrue($result['ok'], (string) $result['error']);

        $teams = $db->all(
            'SELECT team_id FROM team_member WHERE user_id = :u',
            ['u' => $result['id']]
        );
        assertSame([$mine['a']], array_map(static fn (array $r): int => (int) $r['team_id'], $teams));
    });
});

test('the required fields are required', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'required');
        $users = usersFor($db);

        $cases = [
            'blank member id' => ['   ', 'Smith', 'Al', ''],
            'blank last name' => ['test-r1', '  ', 'Al', ''],
            'blank first name' => ['test-r2', 'Smith', '', ''],
            'long member id' => [str_repeat('9', 33), 'Smith', 'Al', ''],
            'bad email' => ['test-r3', 'Smith', 'Al', 'not-an-address'],
        ];

        foreach ($cases as $why => [$memberId, $last, $first, $email]) {
            $result = $users->create(
                adminActor(), $f['season'], [Role::Committeeman],
                $memberId, $last, $first, 'committeeman', '', $email, []
            );
            assertTrue(!$result['ok'], "{$why} was accepted");
        }

        assertSame(0, (int) $db->value("SELECT COUNT(*) FROM `user` WHERE member_id LIKE 'test-r%'"));
    });
});

test('an unreadable phone number leaves the typed form alone and stores no E.164', function (): void {
    // PhoneNumber returns null rather than guessing, and the roster still shows
    // whatever the office wrote down.
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'phone');

        $result = usersFor($db)->create(
            adminActor(), $f['season'], [Role::Committeeman],
            'test-phone', 'Smith', 'Al', 'committeeman', 'call the depot', '', []
        );

        $row = $db->one('SELECT phone, phone_e164 FROM `user` WHERE id = :id', ['id' => $result['id']]);
        assertSame('call the depot', (string) $row['phone']);
        assertSame(null, $row['phone_e164']);
    });
});

test('creating an account is written to the audit log', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'audit');

        $result = usersFor($db)->create(
            adminActor(), $f['season'], [Role::Committeeman],
            'test-audit-u', 'Smith', 'Al', 'committeeman', '', '', [$f['a']]
        );

        $row = $db->one(
            "SELECT actor_id, after_json FROM audit_log
             WHERE action = 'user_create' AND entity_id = :id",
            ['id' => $result['id']]
        );
        assertTrue($row !== null, 'no audit row');
        assertSame(adminActor()->id, (int) $row['actor_id']);
        assertTrue(str_contains((string) $row['after_json'], 'test-audit-u'));
    });
});

// ---------------------------------------------------------------------------
// Team assignment after the fact
// ---------------------------------------------------------------------------

test('setting teams replaces this season only', function (): void {
    // A committeeman who covered Team B in 2026 keeps that record when he moves
    // to Team A in 2027 — the 2026 shifts still point at it.
    inRollback(function (Database $db): void {
        $y1 = userFixture($db, 'st-y1');
        $y2 = userFixture($db, 'st-y2');
        $users = usersFor($db);

        $id = $users->create(
            adminActor(), $y1['season'], [Role::Committeeman],
            'test-move', 'Smith', 'Al', 'committeeman', '', '', [$y1['a']]
        )['id'];

        $users->setTeams(adminActor(), $y2['season'], $id, [$y2['b']]);

        $rows = $db->all(
            'SELECT season_id, team_id FROM team_member WHERE user_id = :u ORDER BY season_id',
            ['u' => $id]
        );
        assertCount(2, $rows, 'last season must survive');

        $users->setTeams(adminActor(), $y2['season'], $id, []);
        assertSame(1, (int) $db->value('SELECT COUNT(*) FROM team_member WHERE user_id = :u', ['u' => $id]));
        assertSame($y1['season'], (int) $db->value('SELECT season_id FROM team_member WHERE user_id = :u', ['u' => $id]));
    });
});

test('setting teams to what they already are writes no audit row', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'noop');
        $users = usersFor($db);
        $id = $users->create(
            adminActor(), $f['season'], [Role::Committeeman],
            'test-noop', 'Smith', 'Al', 'committeeman', '', '', [$f['b'], $f['a']]
        )['id'];

        // Same set, opposite order.
        assertTrue($users->setTeams(adminActor(), $f['season'], $id, [$f['a'], $f['b']])['ok']);

        assertSame(0, (int) $db->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'user_set_teams' AND entity_id = :id",
            ['id' => $id]
        ));
    });
});

// ---------------------------------------------------------------------------
// Deactivating
// ---------------------------------------------------------------------------

test('deactivating an account revokes its sessions at once', function (): void {
    // Otherwise a removed volunteer keeps working for up to 90 days on the
    // cookie they already hold.
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'revoke');
        $users = usersFor($db);
        $id = $users->create(
            adminActor(), $f['season'], [Role::Committeeman],
            'test-revoke', 'Smith', 'Al', 'committeeman', '', '', []
        )['id'];

        $db->execute(
            'INSERT INTO auth_token (user_id, selector, verifier_hash, is_persistent, issued_at, last_used_at, expires_at)
             VALUES (:u, :sel, :ver, 1, :issued, :used, :expires)',
            [
                'u' => $id,
                'sel' => str_repeat('a', 32),
                'ver' => str_repeat('b', 64),
                'issued' => gmdate('Y-m-d H:i:s'),
                'used' => gmdate('Y-m-d H:i:s'),
                'expires' => gmdate('Y-m-d H:i:s', time() + 86400),
            ]
        );

        assertTrue($users->setActive(adminActor(), $id, false)['ok']);

        assertSame(0, (int) $db->value('SELECT is_active FROM `user` WHERE id = :id', ['id' => $id]));
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = :u AND revoked_at IS NULL',
            ['u' => $id]
        ));

        // The row survives: assignments and check-in history point at it.
        $users->setActive(adminActor(), $id, true);
        assertSame(1, (int) $db->value('SELECT is_active FROM `user` WHERE id = :id', ['id' => $id]));
    });
});

test('an admin cannot deactivate their own account', function (): void {
    // The screen that would undo it is behind the account being deactivated.
    inRollback(function (Database $db): void {
        $result = usersFor($db)->setActive(adminActor(), adminActor()->id, false);

        assertTrue(!$result['ok']);
        assertTrue(str_contains((string) $result['error'], 'your own account'));
        assertSame(1, (int) $db->value('SELECT is_active FROM `user` WHERE id = :id', ['id' => adminActor()->id]));
    });
});

// ---------------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------------

test('the listing filters by role and carries this season teams', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'list');
        $users = usersFor($db);

        $com = $users->create(adminActor(), $f['season'], [Role::Committeeman], 'test-l1', 'Zeta', 'Al', 'committeeman', '', '', [$f['a']])['id'];
        $users->create(adminActor(), $f['season'], [Role::Officer, Role::Admin], 'test-l2', 'Yankee', 'Jo', 'officer', '', '', [$f['b']]);

        $committeemen = $users->withRoles($f['season'], [Role::Committeeman], 'test-l');
        assertCount(1, $committeemen);
        assertSame($com, (int) $committeemen[0]['id']);
        assertSame('Team A', (string) $committeemen[0]['team_names']);
        assertSame([$f['a']], Users::rowTeamIds($committeemen[0]));

        $officers = $users->withRoles($f['season'], [Role::Officer, Role::Admin], 'test-l');
        assertCount(1, $officers);
        assertSame('test-l2', (string) $officers[0]['member_id']);

        assertSame([], $users->withRoles($f['season'], [], 'test-l'), 'no roles means no rows, not every row');
    });
});

test('a person with no team in this season still lists, with no teams', function (): void {
    // They must be visible, or the admin re-creates them and hits the unique
    // Member ID instead.
    inRollback(function (Database $db): void {
        $last = userFixture($db, 'lastyear');
        $now = userFixture($db, 'thisyear');
        $users = usersFor($db);

        $id = $users->create(
            adminActor(), $last['season'], [Role::Committeeman],
            'test-returning', 'Smith', 'Al', 'committeeman', '', '', [$last['a']]
        )['id'];

        $rows = $users->withRoles($now['season'], [Role::Committeeman], 'test-returning');
        assertCount(1, $rows);
        assertSame($id, (int) $rows[0]['id']);
        assertSame([], Users::rowTeamIds($rows[0]), 'last season must not leak into this one');
    });
});

test('LIKE wildcards in a search are matched literally', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'search');
        $users = usersFor($db);

        $users->create(adminActor(), $f['season'], [Role::Committeeman], 'test-a_1', 'Smith', 'Al', 'committeeman', '', '', []);
        $users->create(adminActor(), $f['season'], [Role::Committeeman], 'test-ab1', 'Jones', 'Bo', 'committeeman', '', '', []);

        $found = $users->withRoles($f['season'], [Role::Committeeman], 'test-a_1');
        assertCount(1, $found, '_ must not act as a wildcard');
        assertSame('test-a_1', (string) $found[0]['member_id']);

        // And a percent sign matches nothing rather than everything.
        assertCount(0, $users->withRoles($f['season'], [Role::Committeeman], 'test-a%1'));
    });
});

test('the search finds a person by name in either order', function (): void {
    inRollback(function (Database $db): void {
        $f = userFixture($db, 'byname');
        $users = usersFor($db);
        $users->create(adminActor(), $f['season'], [Role::Committeeman], 'test-name', 'Zzzsmith', 'Alfred', 'committeeman', '', '', []);

        foreach (['Zzzsmith', 'Alfred', 'Alfred Zzzsmith', 'Zzzsmith, Alfred'] as $term) {
            assertCount(1, $users->withRoles($f['season'], [Role::Committeeman], $term), "searching for '{$term}'");
        }
    });
});

// ---------------------------------------------------------------------------
// The menu
// ---------------------------------------------------------------------------

test('both create-user sections are built and admin-only', function (): void {
    foreach (['committeemen', 'officers'] as $key) {
        $section = AdminMenu::section($key);
        assertTrue($section !== null, "no admin section '{$key}'");
        assertTrue($section['built'], "{$key} is still a placeholder");
        assertSame(Capability::CreateOfficerAdminUsers, $section['capability']);
    }
});
