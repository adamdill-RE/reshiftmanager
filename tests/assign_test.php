<?php

declare(strict_types=1);

use Resm\AuditLog;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Officer\Assignments;
use Resm\Officer\Board;

/**
 * The assign board (spec 6.9.4) and carry-forward (6.9.5).
 *
 * The screen that determines whether the application succeeds, so its seven
 * rules each get a test, and so does the case two officers cause between them.
 */

function assignmentsFor(Database $db): Assignments
{
    return new Assignments($db, new AuditLog($db));
}

function boardFor(Database $db): Board
{
    return new Board($db);
}

/** The shift row assign() needs: id, team and season. */
function shiftRow(Database $db, int $shiftId): array
{
    return (array) $db->one(
        'SELECT id, team_id, season_id, starts_at, ends_at, current_phase FROM shift WHERE id = :id',
        ['id' => $shiftId]
    );
}

/** Everyone on the day shift is on the tarmac. */
function checkInAll(Database $db, array $f): void
{
    foreach ($f['roster'] as $u) {
        checkEvent($db, $f['day'], $u, 'in', '2027-03-06 08:05');
    }
}

function positionId(Database $db, string $label): int
{
    $id = (int) $db->value('SELECT id FROM position WHERE label = :l', ['l' => $label]);

    // A mistyped label would otherwise resolve to 0 and be refused by the
    // board as "not on this board" — a passing test measuring the wrong guard.
    if ($id === 0) {
        throw new RuntimeException("No position labelled {$label}");
    }

    return $id;
}

function holdsPosition(Database $db, int $shift, string $phase, int $user, string $label): bool
{
    return (int) $db->value(
        'SELECT COUNT(*) FROM assignment a JOIN position p ON p.id = a.position_id
          WHERE a.shift_id = :s AND a.phase = :ph AND a.user_id = :u
            AND p.label = :l AND a.is_current = 1',
        ['s' => $shift, 'ph' => $phase, 'u' => $user, 'l' => $label]
    ) > 0;
}

// ---------------------------------------------------------------------------
// The seven rules of 6.9.4
// ---------------------------------------------------------------------------

test('rule 1: assigning someone already placed vacates the prior position', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-r1');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-r1'));
        $shift = shiftRow($db, $f['day']);
        [$a] = $f['roster'];

        $service = assignmentsFor($db);
        $service->assign($actor, $shift, 'unload', positionId($db, 'Reed Starter 1'), $a);
        $r = $service->assign($actor, $shift, 'unload', positionId($db, 'Reed/Employee Runner 1'), $a);

        assertTrue($r['ok'], (string) $r['error']);
        assertSame(1, $r['vacated'], 'the old spot was freed in the same transaction');
        assertTrue(!holdsPosition($db, $f['day'], 'unload', $a, 'Reed Starter 1'), 'off the old one');
        assertTrue(holdsPosition($db, $f['day'], 'unload', $a, 'Reed/Employee Runner 1'), 'on the new one');
        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM assignment WHERE shift_id = :s AND phase = 'unload'
               AND user_id = :u AND is_current = 1",
            ['s' => $f['day'], 'u' => $a]
        ), 'exactly one position per person per phase');
    });
});

test('rule 2: the same person may hold different positions in the two phases', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-r2');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-r2'));
        $shift = shiftRow($db, $f['day']);
        [$a] = $f['roster'];

        $service = assignmentsFor($db);
        // OST is Bump and Run only and never carries, so this is a deliberate
        // second placement rather than an inherited one.
        $service->assign($actor, $shift, 'bump_run', positionId($db, 'OST Starter 1'), $a);
        $r = $service->assign($actor, $shift, 'unload', positionId($db, 'Unload Starter'), $a);

        assertTrue($r['ok'], (string) $r['error']);
        assertTrue(holdsPosition($db, $f['day'], 'bump_run', $a, 'OST Starter 1'), 'still on OST');
        assertTrue(holdsPosition($db, $f['day'], 'unload', $a, 'Unload Starter'), 'and on Unload');
    });
});

test('rule 3: only the Unload group takes more than one man', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-r3');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-r3'));
        $shift = shiftRow($db, $f['day']);
        [$a, $b, $c] = $f['roster'];

        $service = assignmentsFor($db);
        foreach ([$a, $b, $c] as $man) {
            $r = $service->assign($actor, $shift, 'unload', positionId($db, 'Unload Starter'), $man);
            assertTrue($r['ok'], (string) $r['error']);
        }

        assertSame(3, (int) $db->value(
            "SELECT COUNT(*) FROM assignment a JOIN position p ON p.id = a.position_id
              WHERE a.shift_id = :s AND a.phase = 'unload' AND p.label = 'Unload Starter'
                AND a.is_current = 1",
            ['s' => $f['day']]
        ));
    });
});

test('rule 4: a filled position can be vacated to free the man', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-r4');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-r4'));
        $shift = shiftRow($db, $f['day']);
        [$a] = $f['roster'];
        $reed = positionId($db, 'Reed Starter 1');

        $service = assignmentsFor($db);
        $service->assign($actor, $shift, 'unload', $reed, $a);
        $r = $service->vacate($actor, $shift, 'unload', $reed, $a);

        assertTrue($r['ok'], (string) $r['error']);
        assertTrue(!holdsPosition($db, $f['day'], 'unload', $a, 'Reed Starter 1'));
    });
});

test('rule 5: someone who is not checked in cannot be placed', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-r5');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-r5'));
        $shift = shiftRow($db, $f['day']);
        [$a] = $f['roster'];

        // Nobody has checked in.
        $r = assignmentsFor($db)->assign($actor, $shift, 'unload', positionId($db, 'Reed Starter 1'), $a);

        assertTrue($r['ok'] === false);
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE shift_id = :s',
            ['s' => $f['day']]
        ), 'and nothing was written');
    });
});

test('rule 5: someone on another team cannot be placed on this board', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-scope');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-scope'));
        $shift = shiftRow($db, $f['day']);

        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES ('test-as-scope-outsider', 'Stranger', 'Sam', '!x', 'committeeman')"
        );
        $outsider = $db->lastInsertId();
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $outsider, 't' => $f['teamC'], 's' => $f['season']]
        );
        checkEvent($db, $f['day'], $outsider, 'in', '2027-03-06 08:05');

        $r = assignmentsFor($db)->assign($actor, $shift, 'unload', positionId($db, 'Reed Starter 1'), $outsider);

        assertTrue($r['ok'] === false);
        assertSame(0, (int) $db->value('SELECT COUNT(*) FROM assignment WHERE shift_id = :s', ['s' => $f['day']]));
    });
});

test('rule 7: an overlapping shift is named, and never blocks the placement', function (): void {
    // Spec 5.5 and 6.9.4 rule 7. Neither officer can see the other's board, so
    // the server is the only party that knows.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-r7');
        checkInAll($db, $f);
        [$a] = $f['roster'];

        // He is on Team C as well, and standing on their board — Team C runs
        // 16:45 to 02:00 against Team B's 08:00 to 18:00, so 75 minutes overlap.
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $a, 't' => $f['teamC'], 's' => $f['season']]
        );
        $db->execute(
            "INSERT INTO assignment (shift_id, phase, position_id, user_id, is_multi)
             VALUES (:s, 'bump_run', :p, :u, 0)",
            ['s' => $f['night'], 'p' => positionId($db, 'OST Starter 1'), 'u' => $a]
        );

        $overlaps = boardFor($db)->overlaps($f['day'], $f['teamB'], $f['season']);

        assertTrue(isset($overlaps[$a]), 'the clash is visible to the server');
        assertSame('Team C', $overlaps[$a]['team_name']);

        // And it does not stop anything.
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-r7'));
        $r = assignmentsFor($db)->assign(
            $actor, shiftRow($db, $f['day']), 'unload', positionId($db, 'Reed Starter 1'), $a
        );

        assertTrue($r['ok'], 'permitted, warned, never blocked');
    });
});

test('back-to-back shifts are a handover and warn about nothing', function (): void {
    // Spec 5.5: at every instant there is exactly one true answer to "what is
    // my shift", so touching edges are not an overlap.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-touch');
        [$a] = $f['roster'];

        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $a, 't' => $f['teamC'], 's' => $f['season']]
        );
        // Team C moved to start exactly when Team B ends.
        $db->execute(
            'UPDATE shift SET starts_at = :a WHERE id = :id',
            ['a' => utc('2027-03-06 18:00')->format('Y-m-d H:i:s'), 'id' => $f['night']]
        );
        $db->execute(
            "INSERT INTO assignment (shift_id, phase, position_id, user_id, is_multi)
             VALUES (:s, 'bump_run', :p, :u, 0)",
            ['s' => $f['night'], 'p' => positionId($db, 'OST Starter 1'), 'u' => $a]
        );

        assertCount(0, boardFor($db)->overlaps($f['day'], $f['teamB'], $f['season']));
    });
});

test('skills never gate an assignment', function (): void {
    // Spec 7.4. Certification is not a permission and preference is not a
    // claim: an officer places whoever turned up.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-skill');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-skill'));
        [$a] = $f['roster'];

        // Reed Starter 1 calls for the Starter skill. He has no skills at all.
        assertSame(0, (int) $db->value('SELECT COUNT(*) FROM user_skill WHERE user_id = :u', ['u' => $a]));

        $r = assignmentsFor($db)->assign(
            $actor, shiftRow($db, $f['day']), 'unload', positionId($db, 'Reed Starter 1'), $a
        );

        assertTrue($r['ok'], 'no block and no warning — a game-time decision');
    });
});

// ---------------------------------------------------------------------------
// Carry-forward, Unload into Bump and Run (spec 6.9.5)
// ---------------------------------------------------------------------------

test('a carrying position places the same man in Bump and Run', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cf-on');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cf-on'));
        [$a] = $f['roster'];

        $r = assignmentsFor($db)->assign(
            $actor, shiftRow($db, $f['day']), 'unload', positionId($db, 'Reed Starter 1'), $a
        );

        assertTrue($r['carried'], 'Reed Road carries');
        assertTrue(holdsPosition($db, $f['day'], 'bump_run', $a, 'Reed Starter 1'));
        assertSame(1, (int) $db->value(
            "SELECT is_inherited FROM assignment a JOIN position p ON p.id = a.position_id
              WHERE a.shift_id = :s AND a.phase = 'bump_run' AND a.user_id = :u AND a.is_current = 1",
            ['s' => $f['day'], 'u' => $a]
        ), 'and it is marked as inherited, not placed by hand');
    });
});

test('the Unload group never carries forward', function (): void {
    // Spec 6.9.5: OST, West Loop, Monroe, Maxey and Unload never carry. The
    // Unload group does not exist in Bump and Run at all.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cf-off');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cf-off'));
        [$a] = $f['roster'];

        $r = assignmentsFor($db)->assign(
            $actor, shiftRow($db, $f['day']), 'unload', positionId($db, 'Unload Starter'), $a
        );

        assertTrue($r['ok'], (string) $r['error']);
        assertTrue($r['carried'] === false);
        assertSame(0, (int) $db->value(
            "SELECT COUNT(*) FROM assignment WHERE shift_id = :s AND phase = 'bump_run'",
            ['s' => $f['day']]
        ));
    });
});

test('an override stops a position tracking Unload', function (): void {
    // Spec 6.9.5: once an officer places someone on the Bump and Run position
    // by hand, it stops inheriting and no longer follows Unload changes.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cf-over');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cf-over'));
        $shift = shiftRow($db, $f['day']);
        [$a, $b, $c] = $f['roster'];
        $reed = positionId($db, 'Reed Starter 1');

        $service = assignmentsFor($db);
        $service->assign($actor, $shift, 'unload', $reed, $a);
        // The officer overrides Bump and Run by hand, displacing the inherited
        // row A's Unload placement put there.
        $override = $service->assign($actor, $shift, 'bump_run', $reed, $b);
        assertTrue($override['ok'], (string) $override['error']);
        assertTrue(holdsPosition($db, $f['day'], 'bump_run', $b, 'Reed Starter 1'), 'B took it');

        // And now changes his mind about Unload entirely.
        $cleared = $service->vacate($actor, $shift, 'unload', $reed, $a);
        assertTrue($cleared['carried'] === false, 'the vacate no longer reaches Bump and Run');
        assertTrue(holdsPosition($db, $f['day'], 'bump_run', $b, 'Reed Starter 1'), 'B is untouched by it');

        $r = $service->assign($actor, $shift, 'unload', $reed, $c);

        assertTrue($r['ok'], (string) $r['error']);
        assertTrue($r['carried'] === false, 'and it no longer carries');
        assertTrue(holdsPosition($db, $f['day'], 'bump_run', $b, 'Reed Starter 1'), 'B still holds it');
        assertTrue(holdsPosition($db, $f['day'], 'unload', $c, 'Reed Starter 1'), 'C has Unload');
    });
});

test('clearing Unload clears the row it carried, until it is overridden', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cf-clear');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cf-clear'));
        $shift = shiftRow($db, $f['day']);
        [$a] = $f['roster'];
        $reed = positionId($db, 'Reed Starter 1');

        $service = assignmentsFor($db);
        $service->assign($actor, $shift, 'unload', $reed, $a);
        assertTrue(holdsPosition($db, $f['day'], 'bump_run', $a, 'Reed Starter 1'), 'carried');

        $r = $service->vacate($actor, $shift, 'unload', $reed, $a);

        assertTrue($r['carried'], 'the inherited row went with it');
        assertTrue(!holdsPosition($db, $f['day'], 'bump_run', $a, 'Reed Starter 1'));
    });
});

test('carry-forward does not move a man off a hand-placed Bump and Run spot', function (): void {
    // Rule 2 lets a man hold different positions in the two phases, and an
    // officer's deliberate placement is not carry-forward's to undo.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cf-hand');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'cf-hand'));
        $shift = shiftRow($db, $f['day']);
        [$a] = $f['roster'];

        $service = assignmentsFor($db);
        $service->assign($actor, $shift, 'bump_run', positionId($db, 'OST Starter 1'), $a);
        $r = $service->assign($actor, $shift, 'unload', positionId($db, 'Reed Starter 1'), $a);

        assertTrue($r['ok'], (string) $r['error']);
        assertTrue($r['carried'] === false, 'it left his Bump and Run alone');
        assertTrue(holdsPosition($db, $f['day'], 'bump_run', $a, 'OST Starter 1'), 'still on OST');
    });
});

test('an assignment bumps state_version for the pollers', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'as-ver');
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-ver'));
        [$a] = $f['roster'];
        $before = (int) $db->value('SELECT version FROM state_version WHERE shift_id = :s', ['s' => $f['day']]);

        assignmentsFor($db)->assign(
            $actor, shiftRow($db, $f['day']), 'unload', positionId($db, 'Reed Starter 1'), $a
        );

        assertSame($before + 1, (int) $db->value(
            'SELECT version FROM state_version WHERE shift_id = :s',
            ['s' => $f['day']]
        ));
    });
});

// ---------------------------------------------------------------------------
// Two officers at once (spec 10.4)
// ---------------------------------------------------------------------------

test('the officer who loses a race is told, and keeps the man he had', function (): void {
    // Not inRollback: this asserts that the losing write really rolled back,
    // and Database::transaction joins an outer transaction rather than nesting
    // — so inside inRollback no rollback would happen and the test would be
    // measuring the harness rather than the code.
    $db = testDb();
    $f = officerFixture($db, 'as-race');

    try {
        checkInAll($db, $f);
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'as-race'));
        $shift = shiftRow($db, $f['day']);
        [$a, $b] = $f['roster'];
        $reed = positionId($db, 'Reed Starter 1');
        $spare = positionId($db, 'Reed Starter 2');

        $service = assignmentsFor($db);

        // The other officer got there first, and this one already had B
        // standing somewhere.
        $service->assign($actor, $shift, 'unload', $reed, $a);
        $service->assign($actor, $shift, 'unload', $spare, $b);

        $r = $service->assign($actor, $shift, 'unload', $reed, $b);

        assertTrue($r['ok'] === false, 'refused');
        assertTrue($r['taken'], 'and recognised as a race, not a bug');
        assertTrue(
            str_contains((string) $r['error'], 'just took that spot'),
            'the message says what happened: ' . (string) $r['error']
        );

        // The whole point: the vacate that ran before the failed insert was
        // rolled back with it, so B is still standing where he was.
        assertTrue(holdsPosition($db, $f['day'], 'unload', $a, 'Reed Starter 1'), 'A keeps the spot');
        assertTrue(holdsPosition($db, $f['day'], 'unload', $b, 'Reed Starter 2'), 'B keeps his own');
    } finally {
        dropSeason($db, $f['season'], 'test-as-race');
    }
});

test('a duplicate on the person index is told apart from one on the slot', function (): void {
    inRollback(function (Database $db): void {
        // The two indexes mean different things to an officer: one says the
        // spot went, the other says the man did.
        $person = new PDOException('dup');
        $person->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'assignment.uq_assignment_person'"];
        assertSame('uq_assignment_person', Assignments::duplicateKey($person));

        $slot = new PDOException('dup');
        $slot->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'assignment.uq_assignment_slot'"];
        assertSame('uq_assignment_slot', Assignments::duplicateKey($slot));

        // Anything that is not a 1062 is not ours to translate.
        $other = new PDOException('nope');
        $other->errorInfo = ['42S02', 1146, 'Table does not exist'];
        assertSame(null, Assignments::duplicateKey($other));
    });
});
