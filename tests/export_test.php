<?php

declare(strict_types=1);

use Resm\Admin\RosterExport;
use Resm\Admin\RosterImport;
use Resm\AuditLog;
use Resm\Csv;
use Resm\Database;

/**
 * Export Roster, spec 6.10.4.
 *
 * The export is read two ways — by a person in Excel and by Import Roster on
 * the way back in — and the suite covers both: cells that would execute are
 * neutralised for the person, and the header set stays recognisable to the
 * importer.
 */

function exportFor(Database $db): RosterExport
{
    return new RosterExport($db, chicago(), 5);
}

// ---------------------------------------------------------------------------
// Csv::guard — formula injection
// ---------------------------------------------------------------------------

test('a cell Excel would execute is neutralised', function (): void {
    assertSame("'=HYPERLINK(\"http://x\")", Csv::guard('=HYPERLINK("http://x")'));
    assertSame("'@SUM(A1)", Csv::guard('@SUM(A1)'));
    assertSame("'-2+cmd|/C calc|", Csv::guard('-2+cmd|/C calc|'));
    assertSame("'+cmd|/C calc|!A0", Csv::guard('+cmd|/C calc|!A0'));
});

test('ordinary text and phone numbers pass through the guard untouched', function (): void {
    assertSame('Smith, Jr.', Csv::guard('Smith, Jr.'));
    assertSame('', Csv::guard(''));
    assertSame('+1 (806) 555-0100', Csv::guard('+1 (806) 555-0100'));
    assertSame('+18065550100', Csv::guard('+18065550100'));
    assertSame('-42', Csv::guard('-42'));
});

// ---------------------------------------------------------------------------
// What a shift's export contains
// ---------------------------------------------------------------------------

test('the export carries the span, the last position per phase, and both skill lists', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'exp');
        $shift = exportFor($db)->shift($fix['day']);
        assertTrue($shift !== null);

        [$a, $b] = $fix['roster'];

        // A mis-tap corrected: in, out, in again. The span is first in to
        // last out.
        checkEvent($db, $fix['day'], $a, 'in', '2027-03-06 07:52');
        checkEvent($db, $fix['day'], $a, 'out', '2027-03-06 07:53');
        checkEvent($db, $fix['day'], $a, 'in', '2027-03-06 07:55');
        checkEvent($db, $fix['day'], $a, 'out', '2027-03-06 18:04');

        // Moved off Curve 2: last assigned is where he was moved TO.
        officerPlace($db, $fix['day'], $a, 'unload', 'Curve 2');
        $db->execute('UPDATE assignment SET is_current = 0, vacated_at = UTC_TIMESTAMP()
                      WHERE shift_id = :s AND user_id = :u', ['s' => $fix['day'], 'u' => $a]);
        officerPlace($db, $fix['day'], $a, 'unload', 'Curve 1');
        officerPlace($db, $fix['day'], $a, 'bump_run', 'Main Committee Gate 2');

        // Certified radio and forklift, prefers computer. The equipment
        // certification surfaces here (spec 7.1); preferred is its own
        // column (spec 7.3).
        $db->execute("INSERT INTO user_skill (user_id, skill_id, granted_at, is_preferred, preferred_at)
                      SELECT :u, id, UTC_TIMESTAMP(), 0, NULL FROM skill WHERE code IN ('radio', 'forklift')",
            ['u' => $a]);
        $db->execute("INSERT INTO user_skill (user_id, skill_id, granted_at, is_preferred, preferred_at)
                      SELECT :u, id, NULL, 1, UTC_TIMESTAMP() FROM skill WHERE code = 'computer'",
            ['u' => $a]);

        $rows = exportFor($db)->rows($shift);
        $mine = array_values(array_filter($rows, static fn (array $r): bool => $r['user_id'] === $a));
        assertCount(1, $mine, 'one row per person, however many events');
        $row = $mine[0];

        assertSame(utc('2027-03-06 07:52')->format('Y-m-d H:i:s'), $row['check_in']);
        assertSame(utc('2027-03-06 18:04')->format('Y-m-d H:i:s'), $row['check_out']);
        assertSame('Curve 1', $row['position_unload']);
        assertSame('Main Committee Gate 2', $row['position_bump_run']);
        assertSame('Radio; Forklift', $row['certified']);
        assertSame('Computer', $row['preferred']);

        // B never checked in and was never placed; he is still on the record.
        $his = array_values(array_filter($rows, static fn (array $r): bool => $r['user_id'] === $b));
        assertCount(1, $his);
        assertSame(null, $his[0]['check_in']);
        assertSame('', $his[0]['position_unload']);
    });
});

test('a man from another team who worked the shift is in its export', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'expx');
        $shift = exportFor($db)->shift($fix['day']);

        // On team C's roster, but checked into team B's day shift (spec 5.5).
        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES ('test-expx-guest', 'Doubler', 'Test', '!x', 'committeeman')"
        );
        $guest = $db->lastInsertId();
        $db->execute('INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $guest, 't' => $fix['teamC'], 's' => $fix['season']]);
        checkEvent($db, $fix['day'], $guest, 'in', '2027-03-06 08:10');

        $ids = array_map(static fn (array $r): int => $r['user_id'], exportFor($db)->rows($shift));
        assertTrue(in_array($guest, $ids, true), 'he worked it, so he is in it');
    });
});

test('the CSV renders timestamps in the display timezone and round-trips through import', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'exprt');
        $export = exportFor($db);
        $shift = $export->shift($fix['day']);

        [$a] = $fix['roster'];
        $db->execute("UPDATE `user` SET phone = '(806) 555-0100', email = 'hand@example.com'
                      WHERE id = :u", ['u' => $a]);
        checkEvent($db, $fix['day'], $a, 'in', '2027-03-06 08:01');

        $csv = $export->csv($shift, $export->rows($shift));
        $lines = explode("\r\n", trim($csv));

        assertSame(implode(',', array_map(
            static fn (string $c): string => str_contains($c, ',') ? '"' . $c . '"' : $c,
            RosterExport::COLUMNS
        )), $lines[0]);

        // 08:01 in Chicago is what a person reads; the shift day is local too.
        assertTrue(str_contains($csv, '2027-03-06 08:01'), 'check-in is local time');
        assertTrue(str_contains($csv, '(806) 555-0100'), 'phone keeps its display form');

        // The whole file goes back through Import Roster: header recognised,
        // every roster row valid, nobody invented.
        $plan = (new RosterImport($db, new AuditLog($db), 4, '1234'))
            ->plan($csv, $fix['season']);
        assertTrue($plan['ok'], $plan['error'] ?? '');
        assertSame(0, $plan['counts']['error'] ?? 0, 'a fresh export re-imports without errors');
        $memberIds = array_map(static fn (array $r) => $r['member_id'], $plan['rows']);
        assertTrue(in_array('test-exprt-1', $memberIds, true), 'member ids survive the round trip');
    });
});

test('the export filename is the local day and the team, nothing else', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'expfn');
        $shift = exportFor($db)->shift($fix['day']);
        assertSame('roster-2027-03-06-team-b.csv', exportFor($db)->filename($shift));
    });
});

// ---------------------------------------------------------------------------
// Retention (spec 11.5 #7) — a query bound, never a delete
// ---------------------------------------------------------------------------

test('a shift past the retention window is not exportable, and nothing was deleted', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'expold');
        $db->execute(
            'UPDATE shift SET starts_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 YEAR),
                              ends_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 YEAR)
             WHERE id = :s', ['s' => $fix['day']]
        );

        assertSame(null, exportFor($db)->shift($fix['day']), 'six years old is past the window');
        assertSame(1, (int) $db->value('SELECT COUNT(*) FROM shift WHERE id = :s', ['s' => $fix['day']]),
            'the bound is on the query; the row is untouched');

        // Inside the window it is still there.
        $db->execute(
            'UPDATE shift SET starts_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 4 YEAR) WHERE id = :s',
            ['s' => $fix['day']]
        );
        assertTrue(exportFor($db)->shift($fix['day']) !== null, 'four years old is queryable');
    });
});
