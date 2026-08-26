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

/**
 * Two teams, two shifts, a roster on each, and the shift_group rows a real
 * shift is created with (Admin\Shifts). The counter joins shift_group, so a
 * fixture without it reports a board of nothing and every coverage assertion
 * passes for the wrong reason.
 *
 * @return array{season: int, teamB: int, teamC: int, day: int, night: int, roster: array<int, int>}
 */
function officerFixture(Database $db, string $tag): array
{
    $db->execute(
        'INSERT INTO season (name, start_date, end_date, is_active) VALUES (:n, :s, :e, 0)',
        ['n' => "test-{$tag}", 's' => '2027-02-25', 'e' => '2027-03-21']
    );
    $season = $db->lastInsertId();

    $team = static function (string $name) use ($db, $season): int {
        $db->execute(
            'INSERT INTO team (season_id, name, is_active) VALUES (:s, :n, 1)',
            ['s' => $season, 'n' => $name]
        );

        return $db->lastInsertId();
    };
    $teamB = $team('Team B');
    $teamC = $team('Team C');

    $shift = static function (int $teamId, string $type, string $from, string $to) use ($db, $season): int {
        $db->execute(
            'INSERT INTO shift (season_id, team_id, shift_type, starts_at, ends_at, current_phase)
             VALUES (:s, :t, :ty, :a, :b, :p)',
            [
                's' => $season, 't' => $teamId, 'ty' => $type,
                'a' => utc($from)->format('Y-m-d H:i:s'),
                'b' => utc($to)->format('Y-m-d H:i:s'),
                'p' => $type === 'weekend_night' ? 'bump_run' : 'unload',
            ]
        );
        $id = $db->lastInsertId();

        // What Admin\Shifts writes alongside a new shift: all ten groups
        // active (spec 5.4) and a state_version row for the pollers.
        $db->execute(
            'INSERT INTO shift_group (shift_id, group_id, is_active)
             SELECT :shift, id, 1 FROM position_group',
            ['shift' => $id]
        );
        $db->execute('INSERT INTO state_version (shift_id, version) VALUES (:s, 1)', ['s' => $id]);

        return $id;
    };

    $day = $shift($teamB, 'weekend_day', '2027-03-06 08:00', '2027-03-06 18:00');
    $night = $shift($teamC, 'weekend_night', '2027-03-06 16:45', '2027-03-07 02:00');

    $roster = [];
    for ($i = 1; $i <= 4; $i++) {
        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES (:m, :l, 'Test', '!x', 'committeeman')",
            ['m' => "test-{$tag}-{$i}", 'l' => 'Hand' . $i]
        );
        $id = $db->lastInsertId();
        $roster[] = $id;
        $db->execute(
            'INSERT INTO team_member (user_id, team_id, season_id) VALUES (:u, :t, :s)',
            ['u' => $id, 't' => $teamB, 's' => $season]
        );
    }

    return [
        'season' => $season, 'teamB' => $teamB, 'teamC' => $teamC,
        'day' => $day, 'night' => $night, 'roster' => $roster,
    ];
}

function officerIdentity(Role $role, array $teamIds, int $id = 90001): Identity
{
    return new Identity(
        id: $id, memberId: 'test-officer', firstName: 'Pat', lastName: 'Boone',
        role: $role, isActive: true, tokenId: 1, teamIds: $teamIds,
    );
}

function officerShiftFor(Database $db): OfficerShift
{
    return new OfficerShift($db, new Window(new DateTimeZone('America/Chicago')));
}

function phaseControlFor(Database $db): PhaseControl
{
    return new PhaseControl($db, new AuditLog($db));
}

/** An officer row that really exists, so assigned_by's foreign key holds. */
function officerUser(Database $db, string $tag): int
{
    $db->execute(
        "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
         VALUES (:m, 'Boone', 'Pat', '!x', 'officer')",
        ['m' => "test-{$tag}-officer"]
    );

    return $db->lastInsertId();
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

/** Place someone on a named position, the way the board does. */
function officerPlace(
    Database $db,
    int $shift,
    int $user,
    string $phase,
    string $label,
    string $source = 'manual',
): void {
    $db->execute(
        'INSERT INTO assignment (shift_id, phase, position_id, user_id, is_multi, source, is_inherited)
         SELECT :s, :ph, p.id, :u, pp.multi_assign, :src, :inh
           FROM position p
           JOIN position_phase pp ON pp.position_id = p.id AND pp.phase = :ph2
          WHERE p.label = :label',
        [
            's' => $shift, 'ph' => $phase, 'u' => $user, 'label' => $label,
            'ph2' => $phase, 'src' => $source,
            'inh' => $source === 'carry_forward' ? 1 : 0,
        ]
    );
}

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
