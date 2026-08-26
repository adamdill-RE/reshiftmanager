<?php

declare(strict_types=1);

use Resm\AuditLog;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Officer\Broadcasts;
use Resm\Officer\CopyPrevious;
use Resm\Shift\Attendance;

/**
 * Copy From Previous Shift (spec 6.9.6) and Broadcast Message (6.9.10).
 */

function copyFor(Database $db): CopyPrevious
{
    return new CopyPrevious($db, new AuditLog($db));
}

function broadcastsFor(Database $db): Broadcasts
{
    return new Broadcasts($db, new AuditLog($db));
}

// ---------------------------------------------------------------------------
// Copy From Previous Shift
// ---------------------------------------------------------------------------

test('the preview reports what will land and what will be left open', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cp-prev');
        $db->execute('UPDATE shift SET team_id = :t WHERE id = :id', ['t' => $f['teamB'], 'id' => $f['night']]);
        [$a, $b, $c] = $f['roster'];

        // Last night: three men on three Reed Road positions.
        officerPlace($db, $f['night'], $a, 'unload', 'Reed Starter 1');
        officerPlace($db, $f['night'], $b, 'unload', 'Reed Starter 2');
        officerPlace($db, $f['night'], $c, 'unload', 'Reed Computer');

        // Tonight only two of them turned up.
        checkEvent($db, $f['day'], $a, 'in', '2027-03-06 08:05');
        checkEvent($db, $f['day'], $b, 'in', '2027-03-06 08:05');

        $preview = copyFor($db)->preview($f['night'], $f['day'], $f['teamB'], $f['season'], 'unload');

        assertCount(2, $preview['apply']);
        assertCount(1, $preview['missing'], 'the man who is not here tonight');
        assertSame('Reed Computer', (string) $preview['missing'][0]['position']);
    });
});

test('a preview writes nothing', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cp-dry');
        [$a] = $f['roster'];
        officerPlace($db, $f['night'], $a, 'unload', 'Reed Starter 1');
        checkEvent($db, $f['day'], $a, 'in', '2027-03-06 08:05');

        copyFor($db)->preview($f['night'], $f['day'], $f['teamB'], $f['season'], 'unload');

        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE shift_id = :s',
            ['s' => $f['day']]
        ));
    });
});

test('only people checked in tonight are placed', function (): void {
    // Spec 6.9.6 step 3. Everybody else's position is left vacant, and the
    // officer fills the holes by hand.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cp-apply');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cp-apply'));
        // Last night's shift has to be this team's, or the copy is refused.
        $db->execute('UPDATE shift SET team_id = :t WHERE id = :id', ['t' => $f['teamB'], 'id' => $f['night']]);
        [$a, $b, $c] = $f['roster'];

        officerPlace($db, $f['night'], $a, 'unload', 'Reed Starter 1');
        officerPlace($db, $f['night'], $b, 'unload', 'Reed Starter 2');
        officerPlace($db, $f['night'], $c, 'unload', 'Reed Computer');
        checkEvent($db, $f['day'], $a, 'in', '2027-03-06 08:05');
        checkEvent($db, $f['day'], $b, 'in', '2027-03-06 08:05');

        $shift = shiftRow($db, $f['day']);
        $r = copyFor($db)->apply($actor, $shift, $f['night'], 'unload');

        assertTrue($r['ok'], (string) $r['error']);
        assertSame(2, $r['applied']);
        assertTrue(holdsPosition($db, $f['day'], 'unload', $a, 'Reed Starter 1'));
        assertTrue(holdsPosition($db, $f['day'], 'unload', $b, 'Reed Starter 2'));
        assertTrue(!holdsPosition($db, $f['day'], 'unload', $c, 'Reed Computer'), 'left open');
    });
});

test('a copy adds to a board and never overwrites one', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cp-keep');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cp-keep'));
        // Last night's shift has to be this team's, or the copy is refused.
        $db->execute('UPDATE shift SET team_id = :t WHERE id = :id', ['t' => $f['teamB'], 'id' => $f['night']]);
        [$a, $b] = $f['roster'];

        officerPlace($db, $f['night'], $a, 'unload', 'Reed Starter 1');
        checkEvent($db, $f['day'], $a, 'in', '2027-03-06 08:05');
        checkEvent($db, $f['day'], $b, 'in', '2027-03-06 08:05');

        // Tonight the officer has already put somebody else there.
        officerPlace($db, $f['day'], $b, 'unload', 'Reed Starter 1');

        $r = copyFor($db)->apply($actor, shiftRow($db, $f['day']), $f['night'], 'unload');

        assertSame(0, $r['applied']);
        assertTrue(holdsPosition($db, $f['day'], 'unload', $b, 'Reed Starter 1'), 'tonight’s man keeps it');
    });
});

test('a copy into Unload carries into Bump and Run', function (): void {
    // Spec 6.9.5 applies to a copy the same as to a placement, or copying
    // Unload and flipping the phase would show an empty board.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cp-carry');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cp-carry'));
        // Last night's shift has to be this team's, or the copy is refused.
        $db->execute('UPDATE shift SET team_id = :t WHERE id = :id', ['t' => $f['teamB'], 'id' => $f['night']]);
        [$a, $b] = $f['roster'];

        officerPlace($db, $f['night'], $a, 'unload', 'Reed Starter 1');
        officerPlace($db, $f['night'], $b, 'unload', 'Unload Starter');
        checkEvent($db, $f['day'], $a, 'in', '2027-03-06 08:05');
        checkEvent($db, $f['day'], $b, 'in', '2027-03-06 08:05');

        $r = copyFor($db)->apply($actor, shiftRow($db, $f['day']), $f['night'], 'unload');

        assertSame(2, $r['applied']);
        assertSame(1, $r['carried'], 'Reed Road carries; the Unload group never does');
        assertTrue(holdsPosition($db, $f['day'], 'bump_run', $a, 'Reed Starter 1'));
    });
});

test('a copy will not reach another team’s shift', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cp-scope');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cp-scope'));

        // f['night'] belongs to Team C; the target is Team B's day shift.
        $db->execute('UPDATE shift SET team_id = :t WHERE id = :id', ['t' => $f['teamC'], 'id' => $f['night']]);

        $r = copyFor($db)->apply($actor, shiftRow($db, $f['day']), $f['night'], 'unload');

        assertTrue($r['ok'] === false);
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE shift_id = :s',
            ['s' => $f['day']]
        ));
    });
});

test('a shift with an empty board is not offered as a source', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cp-src');
        $db->execute('UPDATE shift SET team_id = :t WHERE id = :id', ['t' => $f['teamB'], 'id' => $f['night']]);

        $empty = copyFor($db)->sources($f['teamB'], $f['season'], $f['day'], 'unload', utc('2027-03-08 12:00'));
        assertCount(0, $empty, 'nothing placed on it');

        officerPlace($db, $f['night'], $f['roster'][0], 'unload', 'Reed Starter 1');
        $offered = copyFor($db)->sources($f['teamB'], $f['season'], $f['day'], 'unload', utc('2027-03-08 12:00'));

        assertCount(1, $offered);
        assertSame(1, (int) $offered[0]['placements']);
    });
});

// ---------------------------------------------------------------------------
// Broadcast Message (spec 6.9.10)
// ---------------------------------------------------------------------------

test('a broadcast reaches the shift and bumps state_version', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'bc-send');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'bc-send'));
        $before = (int) $db->value('SELECT version FROM state_version WHERE shift_id = :s', ['s' => $f['day']]);

        $r = broadcastsFor($db)->send($actor, $f['day'], 'Bump and run in 15 minutes');

        assertTrue($r['ok'], (string) $r['error']);
        $live = (new Attendance($db, new AuditLog($db)))->broadcast($f['day']);
        assertSame('Bump and run in 15 minutes', (string) $live['body']);
        assertSame($before + 1, (int) $db->value(
            'SELECT version FROM state_version WHERE shift_id = :s',
            ['s' => $f['day']]
        ));
    });
});

test('a new broadcast replaces the one that was up', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'bc-replace');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'bc-replace'));

        $service = broadcastsFor($db);
        $service->send($actor, $f['day'], 'Reed lane closed');
        $service->send($actor, $f['day'], 'Reed lane open again');

        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM broadcast WHERE shift_id = :s AND retired_at IS NULL',
            ['s' => $f['day']]
        ), 'one live message at a time');
        // The earlier one is retired, not deleted: what an officer told the
        // team at 19:40 is part of the record of the evening.
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM broadcast WHERE shift_id = :s',
            ['s' => $f['day']]
        ));
    });
});

test('an expired broadcast stops showing without being taken down', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'bc-expire');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'bc-expire'));

        broadcastsFor($db)->send(
            $actor, $f['day'], 'Buses 20 minutes behind', '15', utc('2027-03-06 18:00')
        );

        $attendance = new Attendance($db, new AuditLog($db));
        assertTrue($attendance->broadcast($f['day'], utc('2027-03-06 18:10')) !== null, 'still up at 18:10');
        assertSame(null, $attendance->broadcast($f['day'], utc('2027-03-06 18:20')), 'gone by 18:20');
    });
});

test('an empty or oversized broadcast is refused', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'bc-bad');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'bc-bad'));
        $service = broadcastsFor($db);

        assertTrue($service->send($actor, $f['day'], '   ')['ok'] === false);
        assertTrue($service->send($actor, $f['day'], str_repeat('x', 281))['ok'] === false);
        assertTrue($service->send($actor, $f['day'], 'ok', 'soon')['ok'] === false, 'expiry must be minutes');

        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM broadcast WHERE shift_id = :s',
            ['s' => $f['day']]
        ), 'and none of them wrote anything');
    });
});

test('taking a broadcast down clears the widget', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'bc-retire');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'bc-retire'));

        $service = broadcastsFor($db);
        $service->send($actor, $f['day'], 'Reed lane closed');
        $service->retire($actor, $f['day']);

        assertSame(null, (new Attendance($db, new AuditLog($db)))->broadcast($f['day']));
        assertCount(1, $service->history($f['day']), 'but it is still in the record');
    });
});
