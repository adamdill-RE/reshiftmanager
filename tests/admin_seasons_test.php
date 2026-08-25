<?php

declare(strict_types=1);

use Resm\Admin\Seasons;
use Resm\Admin\Teams;
use Resm\AdminMenu;
use Resm\Auth\Capability;
use Resm\Auth\Role;
use Resm\AuditLog;
use Resm\Database;

function adminActor(): Resm\Auth\Identity
{
    return identity(Role::Admin, []);
}

function seasonsFor(Database $db): Seasons
{
    return new Seasons($db, new AuditLog($db));
}

function teamsFor(Database $db): Teams
{
    return new Teams($db, new AuditLog($db));
}

// ---------------------------------------------------------------------------
// Seasons (spec 6.10.1)
// ---------------------------------------------------------------------------

test('a season is created and starts inactive', function (): void {
    inRollback(function (Database $db): void {
        $result = seasonsFor($db)->create(adminActor(), 'test-2099', '2099-02-01', '2099-03-22');
        assertTrue($result['ok'], $result['error'] ?? '');

        $row = $db->one("SELECT is_active FROM season WHERE name = 'test-2099'");
        assertSame(0, (int) $row['is_active'], 'creating a season must not activate it');
    });
});

test('a season cannot end before it starts', function (): void {
    inRollback(function (Database $db): void {
        $result = seasonsFor($db)->create(adminActor(), 'test-backwards', '2099-03-01', '2099-02-01');
        assertTrue(!$result['ok']);
        assertTrue(str_contains((string) $result['error'], 'before it starts'));
    });
});

test('impossible dates are rejected rather than rolled forward', function (): void {
    inRollback(function (Database $db): void {
        // createFromFormat happily turns 2099-02-31 into 2099-03-03.
        $result = seasonsFor($db)->create(adminActor(), 'test-feb31', '2099-02-31', '2099-03-01');
        assertTrue(!$result['ok'], '2099-02-31 must not be accepted');
    });
});

test('a season name is rejected when blank or already taken', function (): void {
    inRollback(function (Database $db): void {
        $seasons = seasonsFor($db);
        assertTrue(!$seasons->create(adminActor(), '   ', '2099-02-01', '2099-03-22')['ok']);

        $seasons->create(adminActor(), 'test-dup', '2099-02-01', '2099-03-22');
        $again = $seasons->create(adminActor(), 'test-dup', '2099-02-01', '2099-03-22');
        assertTrue(!$again['ok']);
        assertTrue(str_contains((string) $again['error'], 'already exists'));
    });
});

test('activating a season deactivates every other one', function (): void {
    // The invariant the whole app leans on: "the current season" has to be a
    // question with one answer.
    inRollback(function (Database $db): void {
        $seasons = seasonsFor($db);
        $seasons->create(adminActor(), 'test-a', '2099-02-01', '2099-03-01');
        $a = (int) $db->value("SELECT id FROM season WHERE name = 'test-a'");
        $seasons->create(adminActor(), 'test-b', '2100-02-01', '2100-03-01');
        $b = (int) $db->value("SELECT id FROM season WHERE name = 'test-b'");

        $seasons->activate(adminActor(), $a);
        assertSame(1, (int) $db->value('SELECT COUNT(*) FROM season WHERE is_active = 1'));

        $seasons->activate(adminActor(), $b);
        assertSame(1, (int) $db->value('SELECT COUNT(*) FROM season WHERE is_active = 1'));
        assertSame($b, (int) $db->value('SELECT id FROM season WHERE is_active = 1'));
    });
});

test('season changes are written to the audit log', function (): void {
    inRollback(function (Database $db): void {
        $before = (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'season_%'");
        $seasons = seasonsFor($db);
        $seasons->create(adminActor(), 'test-audit', '2099-02-01', '2099-03-01');
        $seasons->activate(adminActor(), (int) $db->value("SELECT id FROM season WHERE name = 'test-audit'"));

        assertSame($before + 2, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'season_%'"));
    });
});

// ---------------------------------------------------------------------------
// Teams (spec 6.10.2)
// ---------------------------------------------------------------------------

test('team names are unique within a season but repeat across seasons', function (): void {
    // "Team A" recurs every year, and each year's is a different team.
    inRollback(function (Database $db): void {
        $seasons = seasonsFor($db);
        $teams = teamsFor($db);

        $seasons->create(adminActor(), 'test-y1', '2099-02-01', '2099-03-01');
        $y1 = (int) $db->value("SELECT id FROM season WHERE name = 'test-y1'");
        $seasons->create(adminActor(), 'test-y2', '2100-02-01', '2100-03-01');
        $y2 = (int) $db->value("SELECT id FROM season WHERE name = 'test-y2'");

        assertTrue($teams->create(adminActor(), $y1, 'Team A')['ok']);
        assertTrue(!$teams->create(adminActor(), $y1, 'Team A')['ok'], 'duplicate within a season');
        assertTrue($teams->create(adminActor(), $y2, 'Team A')['ok'], 'same name in another season');
    });
});

test('a team is deactivated, never deleted', function (): void {
    inRollback(function (Database $db): void {
        $seasons = seasonsFor($db);
        $teams = teamsFor($db);
        $seasons->create(adminActor(), 'test-deact', '2099-02-01', '2099-03-01');
        $season = (int) $db->value("SELECT id FROM season WHERE name = 'test-deact'");
        $teams->create(adminActor(), $season, 'Team Z');
        $id = (int) $db->value('SELECT id FROM team WHERE season_id = :s', ['s' => $season]);

        $teams->setActive(adminActor(), $id, false);
        $row = $db->one('SELECT is_active FROM team WHERE id = :id', ['id' => $id]);
        assertTrue($row !== null, 'the row must survive');
        assertSame(0, (int) $row['is_active']);

        $teams->setActive(adminActor(), $id, true);
        assertSame(1, (int) $db->value('SELECT is_active FROM team WHERE id = :id', ['id' => $id]));
    });
});

test('renaming a team records what it was', function (): void {
    inRollback(function (Database $db): void {
        $seasons = seasonsFor($db);
        $teams = teamsFor($db);
        $seasons->create(adminActor(), 'test-ren', '2099-02-01', '2099-03-01');
        $season = (int) $db->value("SELECT id FROM season WHERE name = 'test-ren'");
        $teams->create(adminActor(), $season, 'Old Name');
        $id = (int) $db->value('SELECT id FROM team WHERE season_id = :s', ['s' => $season]);

        $teams->rename(adminActor(), $id, 'New Name');

        $audit = $db->one(
            "SELECT before_json, after_json FROM audit_log
             WHERE action = 'team_rename' AND entity_id = :id ORDER BY id DESC LIMIT 1",
            ['id' => $id]
        );
        assertTrue(str_contains((string) $audit['before_json'], 'Old Name'), 'the before state is what makes it an audit');
        assertTrue(str_contains((string) $audit['after_json'], 'New Name'));
    });
});

// ---------------------------------------------------------------------------
// The menu and its guards
// ---------------------------------------------------------------------------

test('every admin section is admin-only', function (): void {
    $committeeman = identity(Role::Committeeman, []);
    $officer = identity(Role::Officer, [7]);
    $admin = identity(Role::Admin, []);

    foreach (AdminMenu::SECTIONS as $key => $section) {
        assertSame(false, $committeeman->can($section['capability']), "committeeman / {$key}");
        assertSame(false, $officer->can($section['capability']), "officer / {$key}");
        assertSame(true, $admin->can($section['capability']), "admin / {$key}");
    }
});

test('the admin tile list is empty for anyone but an admin', function (): void {
    $app = Resm\App::boot(dirname(__DIR__));

    assertCount(0, AdminMenu::tilesFor($app, identity(Role::Officer, [7])));
    assertCount(count(AdminMenu::SECTIONS), AdminMenu::tilesFor($app, identity(Role::Admin, [])));
});

test('admin tile urls sit under the mount point', function (): void {
    $app = Resm\App::boot(dirname(__DIR__));

    foreach (AdminMenu::tilesFor($app, identity(Role::Admin, [])) as $tile) {
        assertTrue(str_starts_with($tile['url'], '/resm/admin/'), "bad url: {$tile['url']}");
    }
});
