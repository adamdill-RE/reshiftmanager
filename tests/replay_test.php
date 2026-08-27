<?php

declare(strict_types=1);

use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Shift\Attendance;
use Resm\Shift\Replay;

/**
 * Replaying the offline queue (spec 10.3).
 *
 * The property that matters most is not that a queued tap arrives — it is that
 * it arrives carrying the moment it HAPPENED rather than the moment it synced.
 * A shift's attendance record is the thing this application exists to produce,
 * and a queue that quietly rewrote every offline check-in to "whenever the man
 * next found signal" would leave a record that looks complete and is wrong.
 */

function replayFor(Database $db): Replay
{
    return new Replay($db, new Attendance($db, new AuditLog($db)));
}

function hand(int $id, array $teamIds): Identity
{
    return new Identity(
        id: $id, memberId: 'test-hand', firstName: 'Al', lastName: 'Hand',
        role: Role::Committeeman, isActive: true, tokenId: 1, teamIds: $teamIds,
    );
}

/** The one check_event on a shift for a user, as stored. */
function storedCheck(Database $db, int $shift, int $user): ?array
{
    return $db->one(
        'SELECT type, occurred_at, recorded_at, source FROM check_event
          WHERE shift_id = :s AND user_id = :u ORDER BY id DESC LIMIT 1',
        ['s' => $shift, 'u' => $user]
    );
}

// ---------------------------------------------------------------------------
// The original timestamp survives
// ---------------------------------------------------------------------------

test('a replayed check-in keeps the moment of the tap, not the moment it synced', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-time');
        $user = $f['roster'][0];

        // He tapped at 09:00 with no signal. The phone found some at 11:30.
        $tapped = utc('2027-03-06 09:00');
        $synced = utc('2027-03-06 11:30');

        $result = replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => $tapped->format('c')],
            $synced
        );

        assertTrue($result['ok'], (string) $result['error']);

        $row = storedCheck($db, $f['day'], $user);
        assertSame($tapped->format('Y-m-d H:i:s'), (string) $row['occurred_at'], 'occurred_at is the tap');
        assertSame($synced->format('Y-m-d H:i:s'), (string) $row['recorded_at'], 'recorded_at is the sync');

        // The gap between the two is the only thing that exposes a handset
        // with a wrong clock, and it only exists if both are kept.
        assertSame('offline_sync', (string) $row['source']);
    });
});

test('a live tap records both times as the same moment', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-live');
        $user = $f['roster'][0];
        $now = utc('2027-03-06 10:00');

        (new Attendance($db, new AuditLog($db)))->record(
            hand($user, [$f['teamB']]),
            shiftRow($db, $f['day']),
            $user,
            'in',
            $now
        );

        $row = storedCheck($db, $f['day'], $user);
        assertSame((string) $row['occurred_at'], (string) $row['recorded_at'], 'no gap on a live tap');
        assertSame('self', (string) $row['source'], 'and it is not marked as a replay');
    });
});

test('a replayed lunch change keeps its own moment too', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-lunch');
        $user = $f['roster'][0];
        $tapped = utc('2027-03-06 12:15');
        $synced = utc('2027-03-06 14:00');

        $result = replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            ['kind' => 'lunch', 'shift' => $f['day'], 'state' => 'at_lunch', 'at' => $tapped->format('c')],
            $synced
        );

        assertTrue($result['ok'], (string) $result['error']);

        // Migration 006 added these two columns for exactly this. Without them
        // a lunch change replayed hours late is indistinguishable from one
        // made now — and a man shown At Lunch is a position the board calls
        // covered and is not.
        $row = $db->one(
            'SELECT state, occurred_at, recorded_at, source FROM lunch_event
              WHERE shift_id = :s AND user_id = :u ORDER BY id DESC LIMIT 1',
            ['s' => $f['day'], 'u' => $user]
        );

        assertSame($tapped->format('Y-m-d H:i:s'), (string) $row['occurred_at']);
        assertSame($synced->format('Y-m-d H:i:s'), (string) $row['recorded_at']);
        assertSame('offline_sync', (string) $row['source']);
    });
});

// ---------------------------------------------------------------------------
// Handsets whose clocks are wrong
// ---------------------------------------------------------------------------

test('a clock running fast cannot record a tap in the future', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-fast');
        $user = $f['roster'][0];
        $now = utc('2027-03-06 10:00');

        replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => utc('2027-03-08 10:00')->format('c')],
            $now
        );

        $row = storedCheck($db, $f['day'], $user);
        assertSame($now->format('Y-m-d H:i:s'), (string) $row['occurred_at'], 'clamped to now');
    });
});

test('a clock running slow cannot record a tap before the shift began', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-slow');
        $user = $f['roster'][0];

        replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            // The day shift starts 08:00 local. This handset thinks it is
            // still the previous evening.
            ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => utc('2027-03-05 19:00')->format('c')],
            utc('2027-03-06 10:00')
        );

        $row = storedCheck($db, $f['day'], $user);
        assertSame(
            utc('2027-03-06 08:00')->format('Y-m-d H:i:s'),
            (string) $row['occurred_at'],
            'clamped to the start of the shift'
        );
    });
});

test('the claim a wrong clock made is kept in the audit trail', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-claim');
        $user = $f['roster'][0];

        replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => utc('2027-03-08 10:00')->format('c')],
            utc('2027-03-06 10:00')
        );

        // Clamping keeps the reports honest; the audit log keeps what the
        // device actually said, so a handset four hours out is findable rather
        // than merely corrected away.
        $after = (string) $db->value(
            "SELECT after_json FROM audit_log
              WHERE shift_id = :s AND action = 'check_in' ORDER BY id DESC LIMIT 1",
            ['s' => $f['day']]
        );

        assertTrue(str_contains($after, '2027-03-08'), "the raw claim is missing from: {$after}");
    });
});

// ---------------------------------------------------------------------------
// Who may replay what
// ---------------------------------------------------------------------------

test('a replay cannot reach another team\'s shift', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-scope');
        $user = $f['roster'][0];

        // The roster is on team B; the night shift belongs to team C. A queue
        // is a place an id can be edited as easily as a query string.
        $result = replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            ['kind' => 'check', 'shift' => $f['night'], 'type' => 'in', 'at' => utc('2027-03-06 18:00')->format('c')],
            utc('2027-03-06 19:00')
        );

        assertSame(false, $result['ok']);
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM check_event WHERE shift_id = :s AND user_id = :u',
            ['s' => $f['night'], 'u' => $user]
        ), 'and nothing was written');
    });
});

test('a replay cannot reach back further than a week', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-ancient');
        $user = $f['roster'][0];

        // A queue nobody drained for a month must not be able to rewrite a
        // season's attendance the day someone finally opens the app.
        $result = replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => utc('2027-03-06 09:00')->format('c')],
            utc('2027-04-20 09:00')
        );

        assertSame(false, $result['ok']);
    });
});

test('a replay still lands after the 5.3 window has closed', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-window');
        $user = $f['roster'][0];

        // The night shift ends at 02:00 and its window closes at 04:00. A
        // phone that finds no signal on the way to the car park reconnects
        // over breakfast. Refusing here would drop the check-OUT and leave him
        // showing on the tarmac — which is why the window guards what he can
        // do now, and the roster guards what he already did.
        $officer = officerUser($db, 'rp-window');
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $officer, 't' => $f['teamC'], 's' => $f['season']]
        );

        $result = replayFor($db)->apply(
            hand($officer, [$f['teamC']]),
            $f['season'],
            ['kind' => 'check', 'shift' => $f['night'], 'type' => 'in', 'at' => utc('2027-03-06 17:00')->format('c')],
            utc('2027-03-07 08:30')
        );

        assertTrue($result['ok'], 'a late replay must still be accepted: ' . (string) $result['error']);
    });
});

// ---------------------------------------------------------------------------
// Sending the same thing twice
// ---------------------------------------------------------------------------

test('replaying one event twice writes it once', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-twice');
        $user = $f['roster'][0];
        $tapped = utc('2027-03-06 09:00')->format('c');
        $replay = replayFor($db);
        $identity = hand($user, [$f['teamB']]);

        $first = $replay->apply($identity, $f['season'], ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => $tapped], utc('2027-03-06 11:00'));
        $second = $replay->apply($identity, $f['season'], ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => $tapped], utc('2027-03-06 11:05'));

        // Both report success, because the client deletes a queued item only
        // once the server confirms it — an answer lost on the way back is one
        // it is REQUIRED to send again, and an error would leave a queue that
        // can never drain.
        assertTrue($first['ok'], 'first');
        assertTrue($second['ok'], 'second');
        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM check_event WHERE shift_id = :s AND user_id = :u',
            ['s' => $f['day'], 'u' => $user]
        ), 'exactly one row');
    });
});

test('a correction a moment later is a second row, not a duplicate', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-fix');
        $user = $f['roster'][0];
        $replay = replayFor($db);
        $identity = hand($user, [$f['teamB']]);

        // Spec 6.4 keeps check_event append-only so a mis-tap corrected a
        // second later leaves both rows. The idempotency index must not undo
        // that: these differ in type and in time, so they are two events.
        $replay->apply($identity, $f['season'], ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => utc('2027-03-06 09:00')->format('c')], utc('2027-03-06 11:00'));
        $replay->apply($identity, $f['season'], ['kind' => 'check', 'shift' => $f['day'], 'type' => 'out', 'at' => utc('2027-03-06 09:00:30')->format('c')], utc('2027-03-06 11:00'));

        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM check_event WHERE shift_id = :s AND user_id = :u',
            ['s' => $f['day'], 'u' => $user]
        ));
    });
});

// ---------------------------------------------------------------------------
// What the queue is allowed to carry
// ---------------------------------------------------------------------------

test('the queue carries checks and lunches and nothing else', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-kind');
        $user = $f['roster'][0];

        // Spec 10.3 and 10.4: an officer assignment is NEVER optimistic,
        // because two officers assigning at once is resolved by the server and
        // by the unique indexes on assignment. A replay from a pocket cannot
        // take part in that, so this endpoint must not be a way in.
        $result = replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            ['kind' => 'assignment', 'shift' => $f['day'], 'at' => utc('2027-03-06 09:00')->format('c')],
            utc('2027-03-06 10:00')
        );

        assertSame(false, $result['ok']);
        assertSame(false, $result['retry'], 'and it must not be retried forever');
    });
});

test('a timestamp without an offset is refused rather than guessed at', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-naive');
        $user = $f['roster'][0];

        // Read without an offset it would be taken in the SERVER's timezone,
        // which is not the one the phone was in. Six hours of error in the one
        // number the queue exists to preserve.
        $result = replayFor($db)->apply(
            hand($user, [$f['teamB']]),
            $f['season'],
            ['kind' => 'check', 'shift' => $f['day'], 'type' => 'in', 'at' => '2027-03-06 09:00:00'],
            utc('2027-03-06 10:00')
        );

        assertSame(false, $result['ok']);
        assertSame(false, $result['retry']);
    });
});

test('a rejected item is never marked for retry', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'rp-retry');
        $user = $f['roster'][0];
        $replay = replayFor($db);
        $identity = hand($user, [$f['teamB']]);
        $now = utc('2027-03-06 10:00');

        // A queue that keeps resending something the server will never accept
        // never empties, and a "1 pending" badge that never clears is one
        // people learn to ignore — which hides the next one that matters.
        foreach ([
            ['kind' => 'nonsense', 'shift' => $f['day'], 'at' => $now->format('c')],
            ['kind' => 'check', 'shift' => 99999999, 'type' => 'in', 'at' => $now->format('c')],
            ['kind' => 'check', 'shift' => $f['day'], 'type' => 'sideways', 'at' => $now->format('c')],
            ['kind' => 'lunch', 'shift' => $f['day'], 'state' => 'napping', 'at' => $now->format('c')],
        ] as $item) {
            $result = $replay->apply($identity, $f['season'], $item, $now);
            assertSame(false, $result['ok'], (string) ($item['kind'] ?? '?'));
            assertSame(false, $result['retry'], 'retry for ' . (string) ($item['kind'] ?? '?'));
        }
    });
});

// ---------------------------------------------------------------------------
// What the client may queue at all
// ---------------------------------------------------------------------------

test('only the three writes spec 10.3 names are marked queueable', function (): void {
    $views = dirname(__DIR__) . '/app/views';
    $marked = [];

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($walk as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/data-offline="([a-z]+)"/', $source, $m) === 0) {
            continue;
        }

        foreach ($m[1] as $kind) {
            $marked[] = basename($file->getPathname()) . ':' . $kind;
        }
    }

    sort($marked);

    // offline.js diverts a form ONLY if it carries data-offline, which makes
    // this list the complete set of writes that can be recorded late. Spec
    // 10.3 and 10.4 are flat that an officer's assignment is never among them:
    // two officers assigning at once is resolved by the server and by the
    // unique indexes on assignment, and a phone in a pocket cannot take part
    // in that. A new form gaining the attribute should have to argue with this
    // test first.
    assertSame(
        ['check-in.php:check', 'my-shift.php:check', 'my-shift.php:lunch'],
        $marked
    );
});

test('no officer screen can queue anything', function (): void {
    $officer = dirname(__DIR__) . '/app/views/officer';

    foreach (glob($officer . '/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        assertTrue(
            !str_contains($source, 'data-offline="'),
            basename($file) . ' marks a form queueable; assignment writes are never optimistic'
        );
    }
});
