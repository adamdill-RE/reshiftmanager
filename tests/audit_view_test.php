<?php

declare(strict_types=1);

use Resm\Admin\AuditTrail;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Shift\Attendance;

/**
 * The read side of the audit log, spec 6.10.9.
 *
 * Named audit_view rather than audit because the writes are already covered
 * where they happen — every suite that changes something asserts its audit
 * row. This one is about what an Admin sees, and about the two properties the
 * screen must never lose: it filters, and it cannot write.
 */

function trailFor(Database $db, int $years = 5): AuditTrail
{
    return new AuditTrail($db, $years);
}

test('entries filter by shift, actor and action, and page by id', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'aud');
        $officer = officerUser($db, 'aud');
        $audit = new AuditLog($db);

        $audit->record($officer, 'assign', 'assignment', 12,
            null, ['user_id' => $fix['roster'][0]], $fix['day']);
        $audit->record($officer, 'vacate', 'assignment', 12,
            ['user_id' => $fix['roster'][0]], null, $fix['day']);
        $audit->record(null, 'assign', 'assignment', 30,
            null, ['user_id' => $fix['roster'][1]], $fix['night']);

        $none = ['shift' => null, 'actor' => null, 'action' => null, 'before' => null];

        $byShift = trailFor($db)->entries(['shift' => $fix['day']] + $none);
        assertCount(2, $byShift['entries'], 'two rows touch the day shift');

        $byAction = trailFor($db)->entries(['action' => 'vacate', 'shift' => $fix['day']] + $none);
        assertCount(1, $byAction['entries']);
        assertSame('vacate', $byAction['entries'][0]['action']);

        $byActor = trailFor($db)->entries(['actor' => $officer, 'shift' => $fix['day']] + $none);
        assertCount(2, $byActor['entries'], 'the officer made both day-shift rows');

        // The cursor: everything strictly older than the newest row.
        $all = trailFor($db)->entries(['shift' => $fix['day']] + $none);
        $newest = (int) $all['entries'][0]['id'];
        $older = trailFor($db)->entries(['before' => $newest, 'shift' => $fix['day']] + $none);
        assertCount(1, $older['entries']);
        assertTrue((int) $older['entries'][0]['id'] < $newest);
    });
});

test('the screen resolves ids to names: actor, subject, team', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'audn');
        $officer = officerUser($db, 'audn');
        $subject = $fix['roster'][0];

        $shiftRow = shiftRow($db, $fix['day']);
        $actor = new Identity(
            id: $officer, memberId: 'test-audn-officer', firstName: 'Pat', lastName: 'Boone',
            role: Role::Officer, isActive: true, tokenId: 1, teamIds: [$fix['teamB']],
        );
        $result = (new Attendance($db, new AuditLog($db)))
            ->record($actor, $shiftRow, $subject, 'in', utc('2027-03-06 09:00'));
        assertTrue($result['ok'], (string) $result['error']);

        $page = trailFor($db)->entries(
            ['shift' => $fix['day'], 'actor' => null, 'action' => 'check_in', 'before' => null]
        );
        assertCount(1, $page['entries']);
        $entry = $page['entries'][0];

        assertSame('Boone', $entry['actor_last']);
        assertSame('Hand1', $entry['subject_last'], 'entity user resolves to the person acted on');
        assertSame('Team B', $entry['team_name']);
    });
});

test('an offline replay keeps its claimed time in the record', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'audo');
        $subject = $fix['roster'][0];
        $shiftRow = shiftRow($db, $fix['day']);
        $me = new Identity(
            id: $subject, memberId: 'test-audo-1', firstName: 'Test', lastName: 'Hand1',
            role: Role::Committeeman, isActive: true, tokenId: 1, teamIds: [$fix['teamB']],
        );

        // The handset claimed 08:03; the queue replayed it hours later.
        $claimed = utc('2027-03-06 08:03');
        $result = (new Attendance($db, new AuditLog($db)))
            ->record($me, $shiftRow, $subject, 'in', utc('2027-03-06 12:00'), $claimed);
        assertTrue($result['ok'], (string) $result['error']);

        $page = trailFor($db)->entries(
            ['shift' => $fix['day'], 'actor' => null, 'action' => 'check_in', 'before' => null]
        );
        $after = (array) json_decode((string) $page['entries'][0]['after_json'], true);

        assertSame('offline_sync', $after['source']);
        assertTrue(str_starts_with((string) $after['claimed_at'], '2027-03-06'),
            'the raw claim rides in the audit row');
    });
});

test('the dropdowns are built from the data inside the window', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'audd');
        $officer = officerUser($db, 'audd');
        (new AuditLog($db))->record($officer, 'phase_change', 'shift', $fix['night'],
            ['phase' => 'unload'], ['phase' => 'bump_run'], $fix['night']);

        $trail = trailFor($db);
        assertTrue(in_array('phase_change', $trail->actions(), true));
        assertTrue(in_array($officer, array_map(
            static fn (array $a): int => (int) $a['id'], $trail->actors()
        ), true));
        assertTrue(in_array($fix['night'], array_map(
            static fn (array $s): int => (int) $s['id'], $trail->shifts()
        ), true));
    });
});

test('retention bounds what is shown, and deletes nothing', function (): void {
    inRollback(function (Database $db): void {
        $fix = officerFixture($db, 'audr');
        $officer = officerUser($db, 'audr');
        $audit = new AuditLog($db);

        $audit->record($officer, 'pin_reset', 'user', $fix['roster'][0], null, null, $fix['day']);
        $db->execute(
            'UPDATE audit_log SET occurred_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 YEAR)
              WHERE shift_id = :s', ['s' => $fix['day']]
        );

        $page = trailFor($db)->entries(
            ['shift' => $fix['day'], 'actor' => null, 'action' => null, 'before' => null]
        );
        assertCount(0, $page['entries'], 'six years old is outside the window');
        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM audit_log WHERE shift_id = :s', ['s' => $fix['day']]
        ), 'the row itself is untouched — the bound is on the query');
    });
});

test('the trail class has no way to write', function (): void {
    // Append-only is a property of the code, not a habit. The reader exposes
    // SELECTs and nothing else; if someone adds a mutator, this fails and the
    // diff has to argue with spec 6.10.9 in review.
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(AuditTrail::class))->getMethods(ReflectionMethod::IS_PUBLIC)
    );
    sort($methods);
    assertSame(['__construct', 'actions', 'actors', 'entries', 'shifts'], $methods);
});
