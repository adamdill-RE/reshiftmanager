<?php

declare(strict_types=1);

use Resm\Admin\PositionMatrix;
use Resm\AuditLog;
use Resm\Auth\Role;
use Resm\Database;

/**
 * Position Matrix Editor, spec 6.10.8.
 *
 * position_matrix_test.php pins what the SEED must contain; this file is
 * about what the editor may do to it afterwards — and the two invariants
 * that outlive the generator's guarantee: history survives every operation,
 * and every operation lands in the audit log.
 */

function matrixFor(Database $db): PositionMatrix
{
    return new PositionMatrix($db, new AuditLog($db));
}

/** An admin who really exists, so audit_log's actor foreign key holds. */
function matrixAdmin(Database $db, string $tag): Resm\Auth\Identity
{
    $db->execute(
        "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
         VALUES (:m, 'Root', 'Test', '!x', 'admin')",
        ['m' => "test-{$tag}-admin"]
    );

    return officerIdentity(Role::Admin, [], $db->lastInsertId());
}

/** @return array{unload: array<string,bool>, bump_run: array<string,bool>} */
function phasesInput(bool $unload = true, bool $bumpRun = true, array $flags = []): array
{
    $one = static fn (bool $present): array => [
        'present' => $present,
        'multi_assign' => $present && ($flags['multi'] ?? false),
        'carry_forward' => $present && ($flags['carry'] ?? false),
        'is_critical' => $present && ($flags['critical'] ?? false),
    ];

    return ['unload' => $one($unload), 'bump_run' => $one($bumpRun)];
}

test('the live counts match the seed baseline until somebody edits', function (): void {
    inRollback(function (Database $db): void {
        assertSame(PositionMatrix::BASELINE, matrixFor($db)->counts(),
            'a fresh seed measures exactly what spec 8.3 pins');
    });
});

test('creating a position applies the spec 7.2 skill rule, in rule order', function (): void {
    inRollback(function (Database $db): void {
        $matrix = matrixFor($db);
        $admin = matrixAdmin($db, 'mtx-s');

        $general = (int) $db->value("SELECT id FROM position_group WHERE code = 'general'");
        $crosswalk = (int) $db->value("SELECT id FROM position_group WHERE code = 'naomi_crosswalk'");

        $skillOf = static function (Database $db, ?int $id): ?string {
            return $id === null ? null : $db->value(
                'SELECT s.code FROM position p LEFT JOIN skill s ON s.id = p.skill_id WHERE p.id = :id',
                ['id' => $id]
            );
        };

        $gate = $matrix->create($admin, [
            'label' => 'test East Gate', 'group_id' => $general, 'sort_order' => 90,
            'is_radio' => true, 'phases' => phasesInput(),
        ]);
        assertTrue($gate['ok'], (string) $gate['error']);
        assertSame('gate', $skillOf($db, $gate['id']));

        // "Center Starter" in a crosswalk group is the middle, not a Starter —
        // the ordering the spec calls out by name.
        $centre = $matrix->create($admin, [
            'label' => 'test Center Starter', 'group_id' => $crosswalk, 'sort_order' => 91,
            'phases' => phasesInput(),
        ]);
        assertSame('crosswalk_middle', $skillOf($db, $centre['id']));

        $bridge = $matrix->create($admin, [
            'label' => 'test Bridge 9', 'group_id' => $crosswalk, 'sort_order' => 92,
            'phases' => phasesInput(),
        ]);
        assertSame(null, $skillOf($db, $bridge['id']), 'bridge work is its own thing');

        $edge = $matrix->create($admin, [
            'label' => 'test Far Edge', 'group_id' => $crosswalk, 'sort_order' => 93,
            'phases' => phasesInput(),
        ]);
        assertSame('crosswalk_perimeter', $skillOf($db, $edge['id']));

        // A rename re-applies the rule rather than freezing the old answer.
        $matrix->update($admin, $gate['id'], [
            'label' => 'test East Counter', 'group_id' => $general, 'sort_order' => 90,
            'is_radio' => true, 'phases' => phasesInput(),
        ]);
        assertSame('counter', $skillOf($db, $gate['id']));
    });
});

test('the editor refuses what the schema or the spec rules out', function (): void {
    inRollback(function (Database $db): void {
        $matrix = matrixFor($db);
        $admin = matrixAdmin($db, 'mtx-r');
        $general = (int) $db->value("SELECT id FROM position_group WHERE code = 'general'");

        $dup = $matrix->create($admin, [
            'label' => 'Curve 1', 'group_id' => $general, 'sort_order' => 1,
            'phases' => phasesInput(),
        ]);
        assertTrue(!$dup['ok'], 'General already has a Curve 1');

        $noPhase = $matrix->create($admin, [
            'label' => 'test Nowhere', 'group_id' => $general, 'sort_order' => 1,
            'phases' => phasesInput(false, false),
        ]);
        assertTrue(!$noPhase['ok'], 'present in no phase is not a position');

        $halfCarry = $matrix->create($admin, [
            'label' => 'test Half Carry', 'group_id' => $general, 'sort_order' => 1,
            'phases' => ['unload' => ['present' => '1', 'carry_forward' => '1'],
                         'bump_run' => ['present' => '1']],
        ]);
        assertTrue(!$halfCarry['ok'], 'carry-forward is both phase rows or neither (spec 8.2)');

        $blank = $matrix->create($admin, [
            'label' => '   ', 'group_id' => $general, 'sort_order' => 1,
            'phases' => phasesInput(),
        ]);
        assertTrue(!$blank['ok']);
    });
});

test('every edit is in the audit log with its before and after', function (): void {
    inRollback(function (Database $db): void {
        $matrix = matrixFor($db);
        $admin = matrixAdmin($db, 'mtx-r');
        $general = (int) $db->value("SELECT id FROM position_group WHERE code = 'general'");

        $created = $matrix->create($admin, [
            'label' => 'test Audited', 'group_id' => $general, 'sort_order' => 77,
            'phases' => phasesInput(true, true, ['critical' => true]),
        ]);
        $id = $created['id'];

        $matrix->update($admin, $id, [
            'label' => 'test Audited Still', 'group_id' => $general, 'sort_order' => 77,
            'phases' => phasesInput(true, true),
        ]);
        $matrix->retire($admin, $id);
        $matrix->restore($admin, $id);

        $actions = array_map(
            static fn (array $r): string => (string) $r['action'],
            $db->all(
                "SELECT action FROM audit_log
                  WHERE entity = 'position' AND entity_id = :id ORDER BY id",
                ['id' => $id]
            )
        );
        assertSame(['position_create', 'position_update', 'position_retire', 'position_restore'], $actions);

        $update = $db->one(
            "SELECT before_json, after_json FROM audit_log
              WHERE entity = 'position' AND entity_id = :id AND action = 'position_update'",
            ['id' => $id]
        );
        $before = (array) json_decode((string) $update['before_json'], true);
        $after = (array) json_decode((string) $update['after_json'], true);
        assertSame('test Audited', $before['label']);
        assertSame('test Audited Still', $after['label']);
        assertSame(true, $before['phases']['unload']['is_critical']);
        assertSame(false, $after['phases']['unload']['is_critical'],
            'the un-flagging is in the record, not just the new state');

        // Saving the form unchanged is not an event.
        $rows = (int) $db->value(
            "SELECT COUNT(*) FROM audit_log WHERE entity = 'position' AND entity_id = :id",
            ['id' => $id]
        );
        $matrix->update($admin, $id, [
            'label' => 'test Audited Still', 'group_id' => $general, 'sort_order' => 77,
            'phases' => phasesInput(true, true),
        ]);
        assertSame($rows, (int) $db->value(
            "SELECT COUNT(*) FROM audit_log WHERE entity = 'position' AND entity_id = :id",
            ['id' => $id]
        ), 'a no-op save writes no audit row');
    });
});

test('a position holding a live assignment cannot be retired or lose that phase', function (): void {
    inRollback(function (Database $db): void {
        $matrix = matrixFor($db);
        $admin = matrixAdmin($db, 'mtx-l');
        $fix = officerFixture($db, 'mtx');
        [$a] = $fix['roster'];

        // The 2027 fixture shift has not ended, and a man stands on Curve 2
        // in Unload.
        officerPlace($db, $fix['day'], $a, 'unload', 'Curve 2');
        $curve2 = (int) $db->value("SELECT id FROM position WHERE label = 'Curve 2'");
        $before = $matrix->position($curve2);

        $refused = $matrix->retire($admin, $curve2);
        assertTrue(!$refused['ok'], 'he would vanish from the board while still on the tarmac');

        $dropPhase = $matrix->update($admin, $curve2, [
            'label' => 'Curve 2', 'group_id' => $before['group_id'],
            'sort_order' => $before['sort_order'], 'is_radio' => $before['is_radio'] === 1,
            'phases' => phasesInput(false, true),
        ]);
        assertTrue(!$dropPhase['ok'], 'losing Unload strands the same man the same way');

        // Vacated, both operations go through — and the history survives.
        $db->execute(
            'UPDATE assignment SET is_current = 0, vacated_at = UTC_TIMESTAMP()
              WHERE shift_id = :s AND user_id = :u', ['s' => $fix['day'], 'u' => $a]
        );
        $retired = $matrix->retire($admin, $curve2);
        assertTrue($retired['ok'], (string) $retired['error']);
        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM assignment WHERE position_id = :p AND user_id = :u',
            ['p' => $curve2, 'u' => $a]
        ), 'retiring preserves historical assignment records (spec 6.10.8)');
        assertSame(157 - 2, (int) $db->value(
            'SELECT COUNT(*) FROM position_phase pp JOIN position p ON p.id = pp.position_id
              WHERE p.is_active = 1'
        ), 'retired means off the boards; its own phase rows still exist');
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM position_phase WHERE position_id = :p', ['p' => $curve2]
        ));
    });
});

test('a matrix change wakes the pollers on every live board', function (): void {
    inRollback(function (Database $db): void {
        $matrix = matrixFor($db);
        $admin = matrixAdmin($db, 'mtx-v');
        $fix = officerFixture($db, 'mtxv');

        $version = static fn (): int => (int) $db->value(
            'SELECT version FROM state_version WHERE shift_id = :s', ['s' => $fix['day']]
        );
        $was = $version();

        $general = (int) $db->value("SELECT id FROM position_group WHERE code = 'general'");
        $matrix->create($admin, [
            'label' => 'test Poll Wake', 'group_id' => $general, 'sort_order' => 99,
            'phases' => phasesInput(),
        ]);

        assertSame($was + 1, $version(), 'the 2027 shift has not ended, so its board redraws');
    });
});
