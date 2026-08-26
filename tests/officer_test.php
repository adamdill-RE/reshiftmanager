<?php

declare(strict_types=1);

use Resm\AuditLog;
use Resm\Auth\Capability;
use Resm\Auth\Identity;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Officer\Coverage;
use Resm\Officer\OfficerShift;
use Resm\Officer\PhaseControl;
use Resm\Shift\Window;

/**
 * The Officer Menu's foundation (spec 6.9.1, 6.9.2) — which board an officer
 * is looking at, the phase toggle, and the coverage counter.
 */

function officerShiftFor(Database $db): OfficerShift
{
    return new OfficerShift($db, new Window(new DateTimeZone('America/Chicago')));
}

function phaseControlFor(Database $db): PhaseControl
{
    return new PhaseControl($db, new AuditLog($db));
}

// ---------------------------------------------------------------------------
// Which board an officer is looking at
// ---------------------------------------------------------------------------

test('an officer sees only the teams he is assigned to', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'off-teams');
        $officer = officerIdentity(Role::Officer, [$f['teamB']]);

        $teams = officerShiftFor($db)->teams($officer, $f['season'], Capability::AssignPositions);
        $ids = array_map(static fn (array $t): int => (int) $t['id'], $teams);

        assertTrue(in_array($f['teamB'], $ids, true), 'his own team');
        assertTrue(!in_array($f['teamC'], $ids, true), 'not the other one');
    });
});

test('an admin sees every active team in the season', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'off-admin');
        $admin = officerIdentity(Role::Admin, []);

        $ids = array_map(
            static fn (array $t): int => (int) $t['id'],
            officerShiftFor($db)->teams($admin, $f['season'], Capability::AssignPositions)
        );

        assertTrue(in_array($f['teamB'], $ids, true), 'team B');
        assertTrue(in_array($f['teamC'], $ids, true), 'team C — an admin is not limited to his assignments');
    });
});

test('a committeeman sees no teams at all', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'off-comm');
        $man = officerIdentity(Role::Committeeman, [$f['teamB']]);

        assertCount(0, officerShiftFor($db)->teams($man, $f['season'], Capability::AssignPositions));
    });
});

test('asking for another team by id does not get you that team', function (): void {
    // The team in a query string is matched against the permitted list rather
    // than trusted, so an officer editing the URL lands back on his own board
    // instead of reading someone else's.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'off-forge');
        $officer = officerIdentity(Role::Officer, [$f['teamB']]);

        $ctx = officerShiftFor($db)->context(
            $officer,
            $f['season'],
            Capability::AssignPositions,
            $f['teamC'],
            null,
            utc('2027-03-06 12:00'),
        );

        assertSame($f['teamB'], (int) $ctx['team']['id'], 'fell back to his own team');
    });
});

test('a membership from another season is not a permission this season', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'off-season');

        // A team the session still remembers, belonging to a different season.
        $db->execute(
            'INSERT INTO season (name, start_date, end_date, is_active) VALUES (:n, :s, :e, 0)',
            ['n' => 'test-off-season-old', 's' => '2026-02-25', 'e' => '2026-03-21']
        );
        $old = $db->lastInsertId();
        $db->execute(
            "INSERT INTO team (season_id, name, is_active) VALUES (:s, 'Team Z', 1)",
            ['s' => $old]
        );
        $stale = $db->lastInsertId();

        $officer = officerIdentity(Role::Officer, [$f['teamB'], $stale]);
        $ids = array_map(
            static fn (array $t): int => (int) $t['id'],
            officerShiftFor($db)->teams($officer, $f['season'], Capability::AssignPositions)
        );

        assertSame([$f['teamB']], $ids);
    });
});

test('the board opens on the shift that is live now', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'off-live');
        $officer = officerIdentity(Role::Officer, [$f['teamB']]);

        $ctx = officerShiftFor($db)->context(
            $officer, $f['season'], Capability::AssignPositions, $f['teamB'], null,
            utc('2027-03-06 12:00'),
        );

        assertSame($f['day'], (int) $ctx['shift']['id']);
        assertTrue($ctx['shift']['is_live'] === true, 'and it knows it is live');
    });
});

test('with nothing live it opens on the next shift, not the last one', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'off-next');
        $officer = officerIdentity(Role::Officer, [$f['teamB']]);

        // Two days before: the 6 March shift has not started.
        $ctx = officerShiftFor($db)->context(
            $officer, $f['season'], Capability::AssignPositions, $f['teamB'], null,
            utc('2027-03-05 09:00'),
        );

        assertSame($f['day'], (int) $ctx['shift']['id']);
        assertTrue($ctx['shift']['has_ended'] === false);
    });
});

// ---------------------------------------------------------------------------
// The coverage counter (spec 6.9.2)
// ---------------------------------------------------------------------------

function coverageFor(Database $db): Coverage
{
    return new Coverage($db);
}

test('an empty shift reads as the whole board open and no critical covered', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cov-empty');

        $c = coverageFor($db)->forShift($f['day'], $f['teamB'], $f['season'], 'unload');

        assertSame(4, $c['roster']);
        assertSame(0, $c['checked_in']);
        assertSame(4, $c['not_checked_in']);
        // Spec 8.1: 62 position-phase records in Unload, 23 of them critical.
        assertSame(62, $c['positions']);
        assertSame(62, $c['open']);
        assertSame(23, $c['critical_total']);
        assertSame(0, $c['critical_filled']);
        assertTrue($c['critical_short'], 'nothing covered is short');
    });
});

test('Bump and Run counts 95 positions and the 37-critical floor', function (): void {
    // Spec 8.1. 37 against a shift that can run 25 people is the floor, not a
    // target — this figure is expected to read red on a short night.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cov-br');

        $c = coverageFor($db)->forShift($f['night'], $f['teamC'], $f['season'], 'bump_run');

        assertSame(95, $c['positions']);
        assertSame(37, $c['critical_total']);
    });
});

test('checking in and assigning moves the figures', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cov-move');
        [$a, $b] = $f['roster'];

        checkEvent($db, $f['day'], $a, 'in', '2027-03-06 08:05');
        checkEvent($db, $f['day'], $b, 'in', '2027-03-06 08:06');
        officerPlace($db, $f['day'], $a, 'unload', 'Reed Starter 1');

        $c = coverageFor($db)->forShift($f['day'], $f['teamB'], $f['season'], 'unload');

        assertSame(2, $c['checked_in']);
        assertSame(2, $c['not_checked_in'], 'the two who never turned up');
        assertSame(1, $c['assigned']);
        assertSame(1, $c['unassigned'], 'checked in, standing without a position');
        assertSame(61, $c['open']);
        assertSame(1, $c['critical_filled'], 'Reed Starter 1 is critical');
        assertTrue($c['critical_short'], '1 of 23 is still short');
    });
});

test('not checked in is not the complement of checked in', function (): void {
    // Spec 6.9.8: Absent is a roster member with no check event at all. A man
    // who checked in and went home is neither on the tarmac nor someone to
    // ring, and collapsing the two would put him on the chase list.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cov-left');
        [$a] = $f['roster'];

        checkEvent($db, $f['day'], $a, 'in', '2027-03-06 08:05');
        checkEvent($db, $f['day'], $a, 'out', '2027-03-06 14:00');

        $c = coverageFor($db)->forShift($f['day'], $f['teamB'], $f['season'], 'unload');

        assertSame(0, $c['checked_in']);
        assertSame(3, $c['not_checked_in'], 'the three who never came, not four');
        assertSame(1, $c['left'], 'he is counted as having left');
    });
});

test('a multi position holding three people is one filled position', function (): void {
    // Only the Unload group takes more than one man (spec 6.9.4 rule 3), and
    // Open would be wrong by two if it counted people instead of positions.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cov-multi');
        [$a, $b, $c] = $f['roster'];

        foreach ([$a, $b, $c] as $man) {
            checkEvent($db, $f['day'], $man, 'in', '2027-03-06 08:05');
            officerPlace($db, $f['day'], $man, 'unload', 'Unload Starter');
        }

        $cov = coverageFor($db)->forShift($f['day'], $f['teamB'], $f['season'], 'unload');

        assertSame(3, $cov['assigned'], 'three men');
        assertSame(1, $cov['filled'], 'one position');
        assertSame(61, $cov['open']);
    });
});

test('a group switched off leaves the board and stops being counted', function (): void {
    // Spec 5.4: a shift carries its own set of groups so an officer can trim
    // one during the shift — weather and closures, not staffing.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'cov-groups');

        $db->execute(
            "UPDATE shift_group SET is_active = 0
              WHERE shift_id = :s
                AND group_id = (SELECT id FROM position_group WHERE code = 'reed_road')",
            ['s' => $f['day']]
        );

        $c = coverageFor($db)->forShift($f['day'], $f['teamB'], $f['season'], 'unload');

        assertSame(47, $c['positions'], '62 less the 15 Reed Road positions');
        assertSame(19, $c['critical_total'], '23 less the 4 Reed Road criticals');
    });
});

// ---------------------------------------------------------------------------
// Phase control (spec 6.9.1, rules in 5.2)
// ---------------------------------------------------------------------------

test('moving forward to Bump and Run is one tap', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'ph-fwd');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'ph-fwd'));
        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['day']]);

        $r = phaseControlFor($db)->set($actor, $shift, 'bump_run');

        assertTrue($r['ok'], (string) $r['error']);
        assertTrue($r['confirm'] === false, 'forward never asks');
        assertSame('bump_run', (string) $db->value(
            'SELECT current_phase FROM shift WHERE id = :id',
            ['id' => $f['day']]
        ));
    });
});

test('moving back to Unload asks first and writes nothing until it is confirmed', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'ph-back');
        $actor = officerIdentity(Role::Officer, [$f['teamC']], officerUser($db, 'ph-back'));
        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['night']]);

        $asked = phaseControlFor($db)->set($actor, $shift, 'unload');

        assertTrue($asked['confirm'], 'it asks');
        assertTrue($asked['ok'] === false, 'and has not done it');
        assertSame('bump_run', (string) $db->value(
            'SELECT current_phase FROM shift WHERE id = :id',
            ['id' => $f['night']]
        ), 'nothing was written');

        $done = phaseControlFor($db)->set($actor, $shift, 'unload', confirmed: true);

        assertTrue($done['ok'], (string) $done['error']);
        assertSame('unload', (string) $db->value(
            'SELECT current_phase FROM shift WHERE id = :id',
            ['id' => $f['night']]
        ));
    });
});

test('toggling back and forth never destroys a board', function (): void {
    // Spec 5.2: assignments in each phase persist independently.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'ph-keep');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'ph-keep'));
        [$a, $b] = $f['roster'];

        officerPlace($db, $f['day'], $a, 'unload', 'Reed Starter 1');
        officerPlace($db, $f['day'], $b, 'bump_run', 'OST Starter 1');

        $control = phaseControlFor($db);
        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['day']]);
        $control->set($actor, $shift, 'bump_run');

        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['day']]);
        $control->set($actor, $shift, 'unload', confirmed: true);

        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM assignment
              WHERE shift_id = :s AND phase = 'unload' AND is_current = 1 AND user_id = :u",
            ['s' => $f['day'], 'u' => $a]
        ), 'the Unload board is untouched');
        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM assignment
              WHERE shift_id = :s AND phase = 'bump_run' AND is_current = 1 AND user_id = :u",
            ['s' => $f['day'], 'u' => $b]
        ), 'and so is Bump and Run');
    });
});

test('falling back to Unload pre-populates an empty board from Bump and Run', function (): void {
    // Spec 5.2: a Weekend Night shift opens in Bump and Run with Unload empty,
    // and the carry-forward rule runs in reverse if weather forces a fallback.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'ph-seed');
        $actor = officerIdentity(Role::Officer, [$f['teamC']], officerUser($db, 'ph-seed'));
        [$a, $b] = $f['roster'];

        // One carrying position and one that never carries (spec 6.9.5).
        officerPlace($db, $f['night'], $a, 'bump_run', 'Reed Starter 1');
        officerPlace($db, $f['night'], $b, 'bump_run', 'OST Starter 1');

        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['night']]);
        $r = phaseControlFor($db)->set($actor, $shift, 'unload', confirmed: true);

        assertSame(1, $r['seeded'], 'only the carrying position came across');
        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM assignment a JOIN position p ON p.id = a.position_id
              WHERE a.shift_id = :s AND a.phase = 'unload' AND a.is_current = 1
                AND a.user_id = :u AND p.label = 'Reed Starter 1' AND a.is_inherited = 1",
            ['s' => $f['night'], 'u' => $a]
        ));
        assertSame(0, (int) $db->value(
            "SELECT COUNT(*) FROM assignment a JOIN position p ON p.id = a.position_id
              WHERE a.shift_id = :s AND a.phase = 'unload' AND p.label = 'OST Starter 1'",
            ['s' => $f['night']]
        ), 'OST is Bump and Run only and never carries');
    });
});

test('an Unload board that already has work on it is not overwritten', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'ph-noseed');
        $actor = officerIdentity(Role::Officer, [$f['teamC']], officerUser($db, 'ph-noseed'));
        [$a, $b] = $f['roster'];

        officerPlace($db, $f['night'], $a, 'bump_run', 'Reed Starter 1');
        officerPlace($db, $f['night'], $b, 'unload', 'Main Committee Gate Lead');

        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['night']]);
        $r = phaseControlFor($db)->set($actor, $shift, 'unload', confirmed: true);

        assertSame(0, $r['seeded'], 'the officer already has an Unload board');
        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM assignment WHERE shift_id = :s AND phase = 'unload' AND is_current = 1",
            ['s' => $f['night']]
        ));
    });
});

test('a phase change bumps state_version for the pollers', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'ph-ver');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'ph-ver'));
        $before = (int) $db->value('SELECT version FROM state_version WHERE shift_id = :s', ['s' => $f['day']]);

        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['day']]);
        phaseControlFor($db)->set($actor, $shift, 'bump_run');

        assertSame($before + 1, (int) $db->value(
            'SELECT version FROM state_version WHERE shift_id = :s',
            ['s' => $f['day']]
        ));
    });
});

test('asking for the phase it is already on changes nothing', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'ph-same');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'ph-same'));
        $before = (int) $db->value('SELECT version FROM state_version WHERE shift_id = :s', ['s' => $f['day']]);

        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['day']]);
        $r = phaseControlFor($db)->set($actor, $shift, 'unload');

        assertTrue($r['ok'], 'a no-op is not a failure');
        assertSame($before, (int) $db->value(
            'SELECT version FROM state_version WHERE shift_id = :s',
            ['s' => $f['day']]
        ), 'and did not wake every phone on the shift');
    });
});

test('a phase that is not a phase is refused', function (): void {
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'ph-bogus');
        $actor = officerIdentity(Role::Officer, [$f['teamB']], officerUser($db, 'ph-bogus'));

        $shift = $db->one('SELECT id, current_phase FROM shift WHERE id = :id', ['id' => $f['day']]);
        $r = phaseControlFor($db)->set($actor, $shift, 'lunch');

        assertTrue($r['ok'] === false);
        assertSame('unload', (string) $db->value(
            'SELECT current_phase FROM shift WHERE id = :id',
            ['id' => $f['day']]
        ));
    });
});

test('an officer on two teams gets the one he asked for', function (): void {
    // The single-team case cannot tell a working match from a broken one:
    // falling back to the first permitted team lands on the same answer. With
    // two teams it can, which is why this exists alongside the test above.
    inRollback(function (Database $db): void {
        $f = officerFixture($db, 'off-two');
        $officer = officerIdentity(Role::Officer, [$f['teamB'], $f['teamC']]);
        $shifts = officerShiftFor($db);

        $asked = $shifts->context(
            $officer, $f['season'], Capability::AssignPositions, $f['teamC'], null,
            utc('2027-03-06 12:00'),
        );
        assertSame($f['teamC'], (int) $asked['team']['id'], 'the team he named');

        $other = $shifts->context(
            $officer, $f['season'], Capability::AssignPositions, $f['teamB'], null,
            utc('2027-03-06 12:00'),
        );
        assertSame($f['teamB'], (int) $other['team']['id'], 'and the other one when he names that');

        // A team he is on neither of falls back rather than resolving.
        $db->execute(
            "INSERT INTO team (season_id, name, is_active) VALUES (:s, 'Team Z', 1)",
            ['s' => $f['season']]
        );
        $foreign = $db->lastInsertId();

        $refused = $shifts->context(
            $officer, $f['season'], Capability::AssignPositions, $foreign, null,
            utc('2027-03-06 12:00'),
        );
        assertTrue((int) $refused['team']['id'] !== $foreign, 'never the one he is not on');
    });
});

test('the officer page builders merge their overrides rather than union them', function (): void {
    // `$ctx + [...]` keeps the LEFT value for a duplicate key, and the officer
    // context carries error and notice as null -- so a builder written that way
    // silently discards every message it was asked to show. Nothing failed and
    // nothing appeared: the 409 came back with the right status and a blank
    // page. Only driving it over HTTP found it, so this is here to keep it
    // found.
    $source = (string) file_get_contents(__DIR__ . '/../app/routes-officer.php');

    assertSame(
        0,
        substr_count($source, '$ctx + ['),
        'use array_merge($ctx, [...]) so the later value wins'
    );
    assertTrue(
        substr_count($source, 'array_merge($ctx, [') > 0,
        'and the builders should still be assembling their data'
    );
});
