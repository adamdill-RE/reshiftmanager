<?php

declare(strict_types=1);

use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Shift\Attendance;
use Resm\Shift\CurrentShift;

/**
 * Checking in and out (spec 6.4).
 */
function attendanceFor(Database $db): Attendance
{
    return new Attendance($db, new AuditLog($db));
}

/** The committeeman himself, as the actor. */
function samActor(int $id): Identity
{
    return new Identity(
        id: $id, memberId: 'test-sam', firstName: 'Sam', lastName: 'Hardy',
        role: Role::Committeeman, isActive: true, tokenId: 1, teamIds: [],
    );
}

/** Put Sam on a position so there is something for a check-out to free. */
function placeOn(Database $db, int $shift, int $user, string $phase, string $positionLabel): void
{
    $db->execute(
        'INSERT INTO assignment (shift_id, phase, position_id, user_id, is_multi)
         VALUES (:s, :ph, (SELECT id FROM position WHERE label = :label), :u, 0)',
        ['s' => $shift, 'ph' => $phase, 'label' => $positionLabel, 'u' => $user]
    );
}

test('checking in records the time and leaves the board alone', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-in');
        $shift = currentShiftFor($db)->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));

        $r = attendanceFor($db)->record(samActor($f['user']), $shift, $f['user'], 'in', utc('2027-03-06 09:00'));

        assertTrue($r['ok'], (string) $r['error']);
        assertSame(0, $r['vacated']);
        assertSame('in', (string) $db->value(
            'SELECT type FROM check_event WHERE shift_id = :s AND user_id = :u ORDER BY id DESC LIMIT 1',
            ['s' => $f['day'], 'u' => $f['user']]
        ));
    });
});

test('checking out frees the position in both phases', function (): void {
    // Spec 6.4. This is what makes a dual-team handover self-healing (5.5):
    // the spot he leaves falls open on his officer's board as he leaves it.
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-out');
        placeOn($db, $f['day'], $f['user'], 'unload', 'Reed Starter 1');
        placeOn($db, $f['day'], $f['user'], 'bump_run', 'Reed Starter 1');

        $shifts = currentShiftFor($db);
        $in = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));
        attendanceFor($db)->record(samActor($f['user']), $in, $f['user'], 'in', utc('2027-03-06 09:00'));

        $out = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 17:50'));
        $r = attendanceFor($db)->record(samActor($f['user']), $out, $f['user'], 'out', utc('2027-03-06 17:50'));

        assertSame(2, $r['vacated'], 'both phases');
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE shift_id = :s AND user_id = :u AND is_current = 1',
            ['s' => $f['day'], 'u' => $f['user']]
        ));

        // The history survives underneath — the board can be rebuilt for any
        // moment of the evening.
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE shift_id = :s AND user_id = :u AND vacated_at IS NOT NULL',
            ['s' => $f['day'], 'u' => $f['user']]
        ));
    });
});

test('checking out of one shift does not touch the other', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-other');
        placeOn($db, $f['day'], $f['user'], 'unload', 'Reed Starter 1');
        placeOn($db, $f['night'], $f['user'], 'bump_run', 'OST Starter 1');

        $shifts = currentShiftFor($db);
        $day = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 17:50'));
        attendanceFor($db)->record(samActor($f['user']), $day, $f['user'], 'out', utc('2027-03-06 17:50'));

        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE shift_id = :s AND user_id = :u AND is_current = 1',
            ['s' => $f['night'], 'u' => $f['user']]
        ), "the night shift's position must survive");
    });
});

test('tapping the button twice does not write a second event', function (): void {
    // Cold hands on wet glass. The state is what it says it is.
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-twice');
        $shifts = currentShiftFor($db);

        $first = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));
        attendanceFor($db)->record(samActor($f['user']), $first, $f['user'], 'in', utc('2027-03-06 09:00'));

        $again = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));
        $r = attendanceFor($db)->record(samActor($f['user']), $again, $f['user'], 'in', utc('2027-03-06 09:01'));

        assertTrue($r['ok']);
        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM check_event WHERE shift_id = :s AND user_id = :u',
            ['s' => $f['day'], 'u' => $f['user']]
        ), 'one event, not two');
    });
});

test('a correction leaves both events and the newer one wins', function (): void {
    // check_event is append-only on purpose: the record stays honest about
    // what happened, not only about how it ended up.
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-fix');
        $shifts = currentShiftFor($db);
        $att = attendanceFor($db);

        $a = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));
        $att->record(samActor($f['user']), $a, $f['user'], 'in', utc('2027-03-06 09:00'));
        $b = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:01'));
        $att->record(samActor($f['user']), $b, $f['user'], 'out', utc('2027-03-06 09:01'));

        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM check_event WHERE shift_id = :s AND user_id = :u',
            ['s' => $f['day'], 'u' => $f['user']]
        ));

        $now = $shifts->forUser($f['user'], $f['season'], utc('2027-03-06 09:02'));
        assertTrue(!$now['current']['checked_in'], 'the latest event decides');
    });
});

test('an officer recording it is marked as such', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-officer');
        $officer = new Identity(
            id: adminActor()->id, memberId: '7001', firstName: 'Bea', lastName: 'Officer',
            role: Role::Officer, isActive: true, tokenId: 1, teamIds: [],
        );

        $shift = currentShiftFor($db)->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));
        attendanceFor($db)->record($officer, $shift, $f['user'], 'in', utc('2027-03-06 09:00'));

        $row = $db->one(
            'SELECT source, recorded_by, user_id FROM check_event WHERE shift_id = :s ORDER BY id DESC LIMIT 1',
            ['s' => $f['day']]
        );
        assertSame('officer', (string) $row['source']);
        assertSame($officer->id, (int) $row['recorded_by'], 'who did it');
        assertSame($f['user'], (int) $row['user_id'], 'and to whom');
    });
});

test('a check event bumps the version every client is polling', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-version');
        $db->execute('INSERT INTO state_version (shift_id, version) VALUES (:s, 1)', ['s' => $f['day']]);

        $shift = currentShiftFor($db)->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));
        attendanceFor($db)->record(samActor($f['user']), $shift, $f['user'], 'in', utc('2027-03-06 09:00'));

        assertSame(2, (int) $db->value('SELECT version FROM state_version WHERE shift_id = :s', ['s' => $f['day']]));
    });
});

test('anything that is not in or out is refused', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-bad');
        $shift = currentShiftFor($db)->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));

        foreach (['', 'IN', 'maybe', 'lunch'] as $bad) {
            assertTrue(
                !attendanceFor($db)->record(samActor($f['user']), $shift, $f['user'], $bad)['ok'],
                "'{$bad}' was accepted"
            );
        }
        assertSame(0, (int) $db->value('SELECT COUNT(*) FROM check_event WHERE shift_id = :s', ['s' => $f['day']]));
    });
});

test('check events are written to the audit log', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-audit');
        placeOn($db, $f['day'], $f['user'], 'unload', 'Reed Starter 1');
        $shifts = currentShiftFor($db);
        $att = attendanceFor($db);

        $in = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 09:00'));
        $att->record(samActor($f['user']), $in, $f['user'], 'in', utc('2027-03-06 09:00'));
        $out = $shifts->pick($f['user'], $f['season'], $f['day'], utc('2027-03-06 17:50'));
        $att->record(samActor($f['user']), $out, $f['user'], 'out', utc('2027-03-06 17:50'));

        $rows = $db->all(
            "SELECT action, shift_id, after_json FROM audit_log
              WHERE entity = 'user' AND entity_id = :u AND action IN ('check_in', 'check_out')
              ORDER BY id",
            ['u' => $f['user']]
        );
        assertCount(2, $rows);
        assertSame('check_in', (string) $rows[0]['action']);
        assertSame($f['day'], (int) $rows[0]['shift_id'], 'the shift is named so the log reads per shift');

        $after = json_decode((string) $rows[1]['after_json'], true);
        assertSame(1, $after['vacated'], 'what the check-out freed is recorded');
    });
});

test('the pinned broadcast is the live one', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-cast');
        $att = attendanceFor($db);

        assertSame(null, $att->broadcast($f['day']), 'nothing pinned yet');

        $db->execute(
            "INSERT INTO broadcast (shift_id, body, created_at) VALUES (:s, 'Older', :t)",
            ['s' => $f['day'], 't' => utc('2027-03-06 10:00')->format('Y-m-d H:i:s')]
        );
        $db->execute(
            "INSERT INTO broadcast (shift_id, body, created_at) VALUES (:s, 'Newest', :t)",
            ['s' => $f['day'], 't' => utc('2027-03-06 11:00')->format('Y-m-d H:i:s')]
        );
        assertSame('Newest', (string) $att->broadcast($f['day'], utc('2027-03-06 12:00'))['body']);

        // Expired and retired ones drop out.
        $db->execute("UPDATE broadcast SET expires_at = :t WHERE body = 'Newest'",
            ['t' => utc('2027-03-06 11:30')->format('Y-m-d H:i:s')]);
        assertSame('Older', (string) $att->broadcast($f['day'], utc('2027-03-06 12:00'))['body']);

        $db->execute("UPDATE broadcast SET retired_at = NOW() WHERE body = 'Older'");
        assertSame(null, $att->broadcast($f['day'], utc('2027-03-06 12:00')));
    });
});

test('the assignment lookup reports criticality and group', function (): void {
    inRollback(function (Database $db): void {
        $f = dualFixture($db, 'att-assign');
        placeOn($db, $f['day'], $f['user'], 'unload', 'Reed Starter 1');

        $found = attendanceFor($db)->assignments($f['day'], $f['user']);
        assertCount(1, $found);
        assertSame('Reed Starter 1', (string) $found['unload']['position']);
        assertSame('Reed Road', (string) $found['unload']['group_label']);
        assertSame(1, (int) $found['unload']['is_critical'], 'confirmed critical in migration 004');
    });
});
