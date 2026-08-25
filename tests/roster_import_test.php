<?php

declare(strict_types=1);

use Resm\Admin\RosterImport;
use Resm\AdminMenu;
use Resm\Auth\Capability;
use Resm\Auth\Pin;
use Resm\Auth\Role;
use Resm\AuditLog;
use Resm\Database;

/**
 * Roster import (spec 6.10.3).
 *
 * Cost 4 rather than the configured 11: these create a lot of accounts and the
 * expense of the hash is auth_test.php's subject, not this one.
 */
function importerFor(Database $db): RosterImport
{
    return new RosterImport($db, new AuditLog($db), 4, '1234');
}

/**
 * A season with two active teams and one retired one.
 *
 * @return array{season: int, a: int, b: int}
 */
function importFixture(Database $db, string $tag): array
{
    $db->execute(
        'INSERT INTO season (name, start_date, end_date, is_active) VALUES (:n, :s, :e, 0)',
        ['n' => "test-{$tag}", 's' => '2027-02-25', 'e' => '2027-03-21']
    );
    $season = $db->lastInsertId();

    $ids = [];
    foreach (['Team A' => 1, 'Team B' => 1, 'Old Team' => 0] as $name => $active) {
        $db->execute(
            'INSERT INTO team (season_id, name, is_active) VALUES (:s, :n, :a)',
            ['s' => $season, 'n' => $name, 'a' => $active]
        );
        $ids[$name] = $db->lastInsertId();
    }

    return ['season' => $season, 'a' => $ids['Team A'], 'b' => $ids['Team B']];
}

const IMPORT_HEADER = "Lastname,Firstname,Member_ID,Phone,Email,Team\r\n";

// ---------------------------------------------------------------------------
// The dry run
// ---------------------------------------------------------------------------

test('a dry run writes absolutely nothing', function (): void {
    // The whole point of the screen.
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'dryrun');
        $before = (int) $db->value('SELECT COUNT(*) FROM `user`');

        $plan = importerFor($db)->plan(
            IMPORT_HEADER . "Smith,Al,test-9001,713-555-0100,al@example.com,Team A\r\n",
            $f['season']
        );

        assertTrue($plan['ok'], (string) $plan['error']);
        assertSame(1, $plan['counts']['new']);
        assertSame($before, (int) $db->value('SELECT COUNT(*) FROM `user`'), 'the dry run wrote a row');
    });
});

test('the plan counts new, update, skip and error separately', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'counts');
        $importer = importerFor($db);

        // Someone already here as a committeeman, and someone as an officer.
        $importer->commit(adminActor(), IMPORT_HEADER
            . "Existing,Ed,test-c1,,,Team A\r\n", $f['season']);
        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES ('test-o1', 'Officer', 'Olive', '!x', 'officer')"
        );

        $plan = $importer->plan(IMPORT_HEADER
            . "Newman,Nan,test-n1,,,Team A\r\n"
            . "Existing,Edward,test-c1,,,Team A\r\n"
            . "Officer,Olive,test-o1,,,Team A\r\n"
            . ",Nameless,test-e1,,,Team A\r\n", $f['season']);

        assertSame(1, $plan['counts']['new']);
        assertSame(1, $plan['counts']['update']);
        assertSame(1, $plan['counts']['skip']);
        assertSame(1, $plan['counts']['error']);
    });
});

// ---------------------------------------------------------------------------
// Committing
// ---------------------------------------------------------------------------

test('new people arrive as committeemen with the default PIN and their team', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'new');

        $result = importerFor($db)->commit(adminActor(), IMPORT_HEADER
            . "Smith,Al,test-n2,(713) 555-0142,al@example.com,Team A\r\n", $f['season']);

        assertTrue($result['ok'], (string) $result['error']);
        assertSame(1, $result['counts']['new']);

        $row = $db->one("SELECT * FROM `user` WHERE member_id = 'test-n2'");
        assertSame(Role::Committeeman->value, (string) $row['role']);
        assertSame(1, (int) $row['is_active']);
        assertTrue(Pin::verify('1234', (string) $row['pin_hash']), 'PIN must be the default (spec 3.1)');

        // Typed form kept, E.164 derived (spec 6.10.3).
        assertSame('(713) 555-0142', (string) $row['phone']);
        assertSame('+17135550142', (string) $row['phone_e164']);

        assertSame($f['a'], (int) $db->value(
            'SELECT team_id FROM team_member WHERE user_id = :u',
            ['u' => $row['id']]
        ));
    });
});

test('every account an import creates can actually sign in', function (): void {
    // One bcrypt hash is computed and shared across the whole file, because
    // hashing per row is 20 seconds against a 30-second limit. This is the
    // test that the shortcut still produces a working credential for each one.
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'sharedhash');

        $csv = IMPORT_HEADER;
        for ($i = 1; $i <= 5; $i++) {
            $csv .= "Person,Number{$i},test-h{$i},,,Team A\r\n";
        }
        importerFor($db)->commit(adminActor(), $csv, $f['season']);

        $hashes = [];
        foreach ($db->all("SELECT member_id, pin_hash FROM `user` WHERE member_id LIKE 'test-h%'") as $row) {
            assertTrue(Pin::verify('1234', (string) $row['pin_hash']), "{$row['member_id']} cannot sign in");
            $hashes[] = (string) $row['pin_hash'];
        }

        assertCount(5, $hashes);
        // And nothing else verifies against it.
        assertTrue(!Pin::verify('0000', $hashes[0]));
    });
});

test('an existing committeeman is updated and keeps the PIN they chose', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'update');
        $importer = importerFor($db);

        $importer->commit(adminActor(), IMPORT_HEADER
            . "Olds,Ollie,test-u1,,old@example.com,Team A\r\n", $f['season']);

        // They then change their own PIN, as anyone may.
        $db->execute(
            "UPDATE `user` SET pin_hash = :h WHERE member_id = 'test-u1'",
            ['h' => Pin::hash('8888', 4)]
        );

        $result = $importer->commit(adminActor(), IMPORT_HEADER
            . "Newname,Ollie,test-u1,281-555-0100,new@example.com,Team B\r\n", $f['season']);

        assertSame(1, $result['counts']['update']);
        $row = $db->one("SELECT * FROM `user` WHERE member_id = 'test-u1'");
        assertSame('Newname', (string) $row['last_name']);
        assertSame('new@example.com', (string) $row['email']);
        assertSame('+12815550100', (string) $row['phone_e164']);
        assertTrue(Pin::verify('8888', (string) $row['pin_hash']), 'an import must not reset a chosen PIN');

        // Team assignment is additive: the new team is added, the old stays.
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM team_member WHERE user_id = :u',
            ['u' => $row['id']]
        ));
    });
});

test('officers and admins are reported and never touched', function (): void {
    // Spec 6.10.3. A roster spreadsheet is not where someone's role changes.
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'skip');

        foreach (['officer', 'admin'] as $i => $role) {
            $db->execute(
                "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role, email)
                 VALUES (:m, 'Original', 'Name', '!x', :r, 'keep@example.com')",
                ['m' => "test-s{$i}", 'r' => $role]
            );
        }

        $result = importerFor($db)->commit(adminActor(), IMPORT_HEADER
            . "Changed,Completely,test-s0,999-999-9999,new@example.com,Team A\r\n"
            . "Changed,Completely,test-s1,999-999-9999,new@example.com,Team A\r\n", $f['season']);

        assertSame(2, $result['counts']['skip']);
        assertSame(0, $result['counts']['update']);

        foreach (['test-s0', 'test-s1'] as $memberId) {
            $row = $db->one('SELECT * FROM `user` WHERE member_id = :m', ['m' => $memberId]);
            assertSame('Original', (string) $row['last_name'], "{$memberId} was modified");
            assertSame('keep@example.com', (string) $row['email']);
            assertSame(null, $row['phone']);
        }
    });
});

test('being on this year roster reactivates a lapsed committeeman, and it is counted', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'reactivate');
        $importer = importerFor($db);

        $importer->commit(adminActor(), IMPORT_HEADER . "Back,Barry,test-r1,,,Team A\r\n", $f['season']);
        $db->execute("UPDATE `user` SET is_active = 0 WHERE member_id = 'test-r1'");

        $plan = $importer->plan(IMPORT_HEADER . "Back,Barry,test-r1,,,Team A\r\n", $f['season']);
        assertSame(1, $plan['counts']['reactivate'], 'shown in the dry run, not done quietly');
        assertSame(0, $plan['counts']['update']);

        $importer->commit(adminActor(), IMPORT_HEADER . "Back,Barry,test-r1,,,Team A\r\n", $f['season']);
        assertSame(1, (int) $db->value("SELECT is_active FROM `user` WHERE member_id = 'test-r1'"));
    });
});

// ---------------------------------------------------------------------------
// The file itself
// ---------------------------------------------------------------------------

test('a repeated Member ID resolves to the last row, with a warning', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'dupes');

        $plan = importerFor($db)->plan(IMPORT_HEADER
            . "First,Attempt,test-d1,,,Team A\r\n"
            . "Other,Person,test-d2,,,Team A\r\n"
            . "Last,Attempt,test-d1,,,Team B\r\n", $f['season']);

        assertSame(2, $plan['counts']['new'], 'the repeat is one person, not two');
        assertCount(1, $plan['warnings']);
        assertTrue(str_contains($plan['warnings'][0], 'test-d1'), $plan['warnings'][0]);

        $surviving = array_values(array_filter(
            $plan['rows'],
            static fn (array $r): bool => $r['member_id'] === 'test-d1'
        ));
        assertCount(1, $surviving);
        assertSame('Last', $surviving[0]['last_name'], 'the last row wins');
    });
});

test('columns are matched by header name in any order', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'headers');

        $plan = importerFor($db)->plan(
            "Member ID,Team,Email,First Name,Last Name,Mobile\r\n"
            . "test-hd1,Team A,al@example.com,Al,Smith,713-555-0100\r\n",
            $f['season']
        );

        assertSame(1, $plan['counts']['new'], (string) ($plan['rows'][0]['reason'] ?? ''));
        assertSame('Smith', $plan['rows'][0]['last_name']);
        assertSame('Al', $plan['rows'][0]['first_name']);
        assertSame('+17135550100', $plan['rows'][0]['phone_e164']);
    });
});

test('a file with no header row is read in the order the spec gives', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'noheader');

        // Lastname, Firstname, Member_ID, Phone, Email, Team
        $plan = importerFor($db)->plan(
            "Smith,Al,test-nh1,713-555-0100,al@example.com,Team A\r\n",
            $f['season']
        );

        assertSame(1, $plan['counts']['new']);
        assertSame('Smith', $plan['rows'][0]['last_name']);
        assertSame('test-nh1', $plan['rows'][0]['member_id']);
        assertSame('Team A', $plan['rows'][0]['team']);
    });
});

test('an Excel export with a byte order mark still finds its header', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'bom');

        $plan = importerFor($db)->plan(
            "\xEF\xBB\xBF" . IMPORT_HEADER . "Smith,Al,test-b1,,,Team A\r\n",
            $f['season']
        );

        assertSame(1, $plan['counts']['new']);
        assertSame('Smith', $plan['rows'][0]['last_name'], 'the BOM broke the header match');
    });
});

test('a name containing a comma survives, quoted', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'comma');

        $plan = importerFor($db)->plan(IMPORT_HEADER
            . "\"Smith, Jr.\",Al,test-cm1,,,Team A\r\n", $f['season']);

        assertSame('Smith, Jr.', $plan['rows'][0]['last_name']);
    });
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('every kind of bad row is an error naming its line', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'errors');

        $plan = importerFor($db)->plan(IMPORT_HEADER
            . "NoId,Person,,,,\r\n"
            . ",OnlyFirst,test-v1,,,\r\n"
            . "NoFirst,,test-v2,,,\r\n"
            . "Bad,Email,test-v3,,not-an-address,\r\n"
            . "Unknown,Team,test-v4,,,Team Zed\r\n"
            . "Retired,Team,test-v5,,,Old Team\r\n", $f['season']);

        assertSame(6, $plan['counts']['error']);
        assertSame(0, $plan['counts']['new']);

        $lines = array_map(static fn (array $r): int => $r['line'], $plan['rows']);
        assertSame([2, 3, 4, 5, 6, 7], $lines, 'the report has to name the real line');

        $reasons = implode(' | ', array_map(static fn (array $r): string => (string) $r['reason'], $plan['rows']));
        assertTrue(str_contains($reasons, 'Member ID'), $reasons);
        assertTrue(str_contains($reasons, 'Team Zed'), 'an unknown team must be named');
        assertTrue(str_contains($reasons, 'Team A'), 'and the real ones listed');
    });
});

test('a bad row never reaches the database', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'noerrors');

        $result = importerFor($db)->commit(adminActor(), IMPORT_HEADER
            . "Good,Person,test-g1,,,Team A\r\n"
            . "Bad,Email,test-g2,,nope,Team A\r\n", $f['season']);

        assertSame(1, $result['counts']['new']);
        assertSame(1, $result['counts']['error']);
        assertSame(1, (int) $db->value("SELECT COUNT(*) FROM `user` WHERE member_id LIKE 'test-g%'"));
        assertSame(0, (int) $db->value("SELECT COUNT(*) FROM `user` WHERE member_id = 'test-g2'"));
    });
});

test('an empty file and an oversized one are refused', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'size');
        $importer = importerFor($db);

        assertTrue(!$importer->plan('', $f['season'])['ok']);
        assertTrue(!$importer->plan(IMPORT_HEADER, $f['season'])['ok'], 'header and nothing else');

        $huge = IMPORT_HEADER . str_repeat("Smith,Al,test-x,,,Team A\r\n", RosterImport::MAX_ROWS + 1);
        $result = $importer->plan($huge, $f['season']);
        assertTrue(!$result['ok']);
        assertTrue(str_contains((string) $result['error'], (string) RosterImport::MAX_ROWS));
    });
});

// ---------------------------------------------------------------------------
// The error report
// ---------------------------------------------------------------------------

test('the error report lists errors and skips, and reads back as CSV', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'report');
        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES ('test-rp1', 'Officer', 'Olive', '!x', 'officer')"
        );

        $plan = importerFor($db)->plan(IMPORT_HEADER
            . "Fine,Person,test-rp0,,,Team A\r\n"
            . "Officer,Olive,test-rp1,,,Team A\r\n"
            . "\"Bad, Row\",Person,test-rp2,,nope,Team A\r\n", $f['season']);

        $report = RosterImport::errorReport($plan['rows']);
        $rows = Resm\Csv::rows($report);

        assertCount(3, $rows, 'a header and the two problem rows only');
        assertSame('Line', $rows[0]['cells'][0]);
        assertSame('test-rp1', $rows[1]['cells'][1]);
        assertSame('Bad, Row', $rows[2]['cells'][2], 'a quoted comma survives the round trip');
        assertTrue(str_contains($rows[2]['cells'][5], 'email'), $rows[2]['cells'][5]);
    });
});

// ---------------------------------------------------------------------------
// Audit and menu
// ---------------------------------------------------------------------------

test('an import is written to the audit log with its counts', function (): void {
    inRollback(function (Database $db): void {
        $f = importFixture($db, 'audit');

        importerFor($db)->commit(adminActor(), IMPORT_HEADER
            . "Smith,Al,test-au1,,,Team A\r\n", $f['season']);

        $row = $db->one(
            "SELECT actor_id, after_json FROM audit_log
             WHERE action = 'roster_import' AND entity_id = :s",
            ['s' => $f['season']]
        );
        assertTrue($row !== null, 'no audit row');
        assertSame(adminActor()->id, (int) $row['actor_id']);

        // Decoded rather than matched as text: the JSON column reformats what
        // it stores, so a substring assertion tests MySQL's whitespace.
        $after = json_decode((string) $row['after_json'], true);
        assertSame(1, $after['new']);
        assertSame(0, $after['error']);
    });
});

test('the Import Roster section is built and admin-only', function (): void {
    $section = AdminMenu::section('import');
    assertTrue($section !== null);
    assertTrue($section['built'], 'import is still a placeholder');
    assertSame(Capability::ImportExportRoster, $section['capability']);
});
