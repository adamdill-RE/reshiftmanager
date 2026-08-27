<?php

declare(strict_types=1);

namespace Resm\Admin;

use PDOException;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * Position Matrix Editor (spec 6.10.8): add, rename, reorder, retire and
 * re-flag positions without a code change, because tarmac layouts change
 * between seasons and occasionally mid-season.
 *
 * This screen ends the seed generator's guarantee — bin/gen-position-seed.php
 * refuses to emit unless the counts still match spec 8.3, and an editor
 * exists precisely to change those counts. What replaces the guarantee is
 * visibility rather than a lock: every write here lands in the audit log
 * with its before and after, the editor's own header shows the live counts
 * beside the seed baseline so drift is announced rather than silent, and the
 * generator still guards the one thing it can — the migrations, which stay
 * the immutable day-one record.
 *
 * Two rules are structural, not editorial:
 *
 *   Retiring preserves history. is_active = 0 and nothing else; every
 *   assignment row that points at the position stays. A position holding a
 *   live assignment on an unended shift cannot be retired (nor lose that
 *   phase), because the boards filter on is_active and the man standing
 *   there would vanish from the screen while still on the tarmac.
 *
 *   The skill mapping is a rule, not 98 decisions (spec 7.2), so create and
 *   rename apply the rule rather than asking: crosswalk groups first
 *   (Center is the middle, Bridge is nothing, the rest are perimeter), then
 *   Gate, then the four job words. An editor screen that asked would drift
 *   from the rule one hand-pick at a time.
 */
final class PositionMatrix
{
    /** Spec 8.3's totals — the seed baseline the header compares against. */
    public const BASELINE = [
        'positions' => 98,
        'phase_records' => 157,
        'radio' => 22,
        'critical' => 39,
        'multi' => 3,
    ];

    private const PHASES = ['unload', 'bump_run'];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /**
     * Every group with every position, retired ones included — this is the
     * management view, not a board.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groups(): array
    {
        $groups = $this->db->all(
            'SELECT id, code, label, sort_order FROM position_group ORDER BY sort_order'
        );

        $positions = $this->db->all(
            'SELECT p.id, p.group_id, p.label, p.is_radio, p.sort_order, p.is_active,
                    s.label AS skill_label,
                    MAX(CASE WHEN pp.phase = \'unload\' THEN 1 ELSE 0 END) AS in_unload,
                    MAX(CASE WHEN pp.phase = \'bump_run\' THEN 1 ELSE 0 END) AS in_bump_run,
                    MAX(pp.multi_assign) AS any_multi,
                    MAX(pp.carry_forward) AS any_carry,
                    MAX(pp.is_critical) AS any_critical
               FROM position p
               LEFT JOIN position_phase pp ON pp.position_id = p.id
               LEFT JOIN skill s ON s.id = p.skill_id
              GROUP BY p.id
              ORDER BY p.group_id, p.sort_order, p.label'
        );

        $byGroup = [];
        foreach ($positions as $position) {
            $byGroup[(int) $position['group_id']][] = $position;
        }

        foreach ($groups as &$group) {
            $group['positions'] = $byGroup[(int) $group['id']] ?? [];
        }

        return $groups;
    }

    /**
     * Live totals for the header, active positions only — the numbers spec
     * 8.3 pins for the seed, measured against what the tables now hold.
     *
     * @return array{positions: int, phase_records: int, radio: int, critical: int, multi: int}
     */
    public function counts(): array
    {
        $row = $this->db->one(
            'SELECT COUNT(DISTINCT p.id) AS positions,
                    COUNT(pp.position_id) AS phase_records,
                    COUNT(DISTINCT CASE WHEN p.is_radio = 1 THEN p.id END) AS radio,
                    SUM(COALESCE(pp.is_critical, 0)) AS critical,
                    SUM(COALESCE(pp.multi_assign, 0)) AS multi
               FROM position p
               LEFT JOIN position_phase pp ON pp.position_id = p.id
              WHERE p.is_active = 1'
        );

        return [
            'positions' => (int) ($row['positions'] ?? 0),
            'phase_records' => (int) ($row['phase_records'] ?? 0),
            'radio' => (int) ($row['radio'] ?? 0),
            // Critical and multi are per phase-record; spec 8.3 counts a
            // position once (criticality does not differ between phases), so
            // measure the way the spec counts.
            'critical' => (int) $this->db->value(
                'SELECT COUNT(DISTINCT pp.position_id) FROM position_phase pp
                   JOIN position p ON p.id = pp.position_id
                  WHERE pp.is_critical = 1 AND p.is_active = 1'
            ),
            'multi' => (int) $this->db->value(
                'SELECT COUNT(DISTINCT pp.position_id) FROM position_phase pp
                   JOIN position p ON p.id = pp.position_id
                  WHERE pp.multi_assign = 1 AND p.is_active = 1'
            ),
        ];
    }

    /**
     * One position with its phase rows, for the edit form.
     *
     * @return array<string, mixed>|null
     */
    public function position(int $id): ?array
    {
        $position = $this->db->one(
            'SELECT p.id, p.group_id, p.label, p.is_radio, p.sort_order, p.is_active,
                    p.skill_id, s.label AS skill_label, g.label AS group_label
               FROM position p
               JOIN position_group g ON g.id = p.group_id
               LEFT JOIN skill s ON s.id = p.skill_id
              WHERE p.id = :id',
            ['id' => $id]
        );
        if ($position === null) {
            return null;
        }

        $position['phases'] = [];
        foreach (self::PHASES as $phase) {
            $position['phases'][$phase] = [
                'present' => false, 'multi_assign' => false,
                'carry_forward' => false, 'is_critical' => false,
            ];
        }
        $rows = $this->db->all(
            'SELECT phase, multi_assign, carry_forward, is_critical
               FROM position_phase WHERE position_id = :id',
            ['id' => $id]
        );
        foreach ($rows as $row) {
            $position['phases'][(string) $row['phase']] = [
                'present' => true,
                'multi_assign' => (int) $row['multi_assign'] === 1,
                'carry_forward' => (int) $row['carry_forward'] === 1,
                'is_critical' => (int) $row['is_critical'] === 1,
            ];
        }

        return $position;
    }

    /**
     * @param array<string, mixed> $input see normalise() for the shape
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function create(Identity $actor, array $input): array
    {
        $clean = $this->normalise($input);
        if (($clean['error'] ?? null) !== null) {
            return ['ok' => false, 'error' => $clean['error'], 'id' => null];
        }

        try {
            $id = $this->db->transaction(function (Database $db) use ($clean, $actor): int {
                $db->execute(
                    'INSERT INTO position (group_id, label, is_radio, skill_id, sort_order, is_active)
                     VALUES (:group_id, :label, :is_radio, :skill_id, :sort_order, 1)',
                    [
                        'group_id' => $clean['group_id'],
                        'label' => $clean['label'],
                        'is_radio' => $clean['is_radio'],
                        'skill_id' => $this->skillFor($clean['group_id'], $clean['label']),
                        'sort_order' => $clean['sort_order'],
                    ]
                );
                $id = $db->lastInsertId();

                $this->writePhases($db, $id, $clean['phases']);
                $this->touchLiveBoards($db);

                $this->audit->record(
                    $actor->id, 'position_create', 'position', $id,
                    null, $this->snapshot($id)
                );

                return $id;
            });
        } catch (PDOException $e) {
            if (self::isDuplicate($e)) {
                return ['ok' => false, 'error' => 'That group already has a position with that name.', 'id' => null];
            }
            throw $e;
        }

        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function update(Identity $actor, int $id, array $input): array
    {
        $before = $this->snapshot($id);
        if ($before === null) {
            return ['ok' => false, 'error' => 'That position does not exist.', 'id' => null];
        }

        $clean = $this->normalise($input);
        if (($clean['error'] ?? null) !== null) {
            return ['ok' => false, 'error' => $clean['error'], 'id' => null];
        }

        // Losing a phase hides the position from that phase's board; a man
        // currently standing there would vanish from the screen mid-shift.
        foreach (self::PHASES as $phase) {
            if (!$clean['phases'][$phase]['present']
                && $before['phases'][$phase]['present']
                && $this->liveAssignments($id, $phase) > 0) {
                return [
                    'ok' => false,
                    'error' => 'Someone is assigned there in ' . self::phaseName($phase)
                        . ' on a shift that has not ended. Vacate them first.',
                    'id' => null,
                ];
            }
        }

        try {
            $this->db->transaction(function (Database $db) use ($id, $clean, $before, $actor): void {
                // Rename re-applies the spec 7.2 rule, same as create. The
                // radio flag stays the admin's call — it is orthogonal to the
                // job skill and the editor exposes it directly (spec 8.2).
                $db->execute(
                    'UPDATE position
                        SET group_id = :group_id, label = :label, is_radio = :is_radio,
                            skill_id = :skill_id, sort_order = :sort_order
                      WHERE id = :id',
                    [
                        'group_id' => $clean['group_id'],
                        'label' => $clean['label'],
                        'is_radio' => $clean['is_radio'],
                        'skill_id' => $this->skillFor($clean['group_id'], $clean['label']),
                        'sort_order' => $clean['sort_order'],
                        'id' => $id,
                    ]
                );

                $db->execute('DELETE FROM position_phase WHERE position_id = :id', ['id' => $id]);
                $this->writePhases($db, $id, $clean['phases']);
                $this->touchLiveBoards($db);

                $after = $this->snapshot($id);
                if ($after !== $before) {
                    $this->audit->record(
                        $actor->id, 'position_update', 'position', $id, $before, $after
                    );
                }
            });
        } catch (PDOException $e) {
            if (self::isDuplicate($e)) {
                return ['ok' => false, 'error' => 'That group already has a position with that name.', 'id' => null];
            }
            throw $e;
        }

        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /**
     * Retire: is_active = 0 and nothing else. Every historical assignment
     * stays (spec 6.10.8); the position simply stops appearing on boards.
     *
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function retire(Identity $actor, int $id): array
    {
        $before = $this->snapshot($id);
        if ($before === null) {
            return ['ok' => false, 'error' => 'That position does not exist.', 'id' => null];
        }
        if (!$before['is_active']) {
            return ['ok' => true, 'error' => null, 'id' => $id];
        }

        if ($this->liveAssignments($id) > 0) {
            return [
                'ok' => false,
                'error' => 'Someone is assigned there on a shift that has not ended. Vacate them first.',
                'id' => null,
            ];
        }

        $this->db->transaction(function (Database $db) use ($id, $before, $actor): void {
            $db->execute('UPDATE position SET is_active = 0 WHERE id = :id', ['id' => $id]);
            $this->touchLiveBoards($db);
            $this->audit->record(
                $actor->id, 'position_retire', 'position', $id, $before, $this->snapshot($id)
            );
        });

        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /** @return array{ok: bool, error: ?string, id: ?int} */
    public function restore(Identity $actor, int $id): array
    {
        $before = $this->snapshot($id);
        if ($before === null) {
            return ['ok' => false, 'error' => 'That position does not exist.', 'id' => null];
        }
        if ($before['is_active']) {
            return ['ok' => true, 'error' => null, 'id' => $id];
        }

        $this->db->transaction(function (Database $db) use ($id, $before, $actor): void {
            $db->execute('UPDATE position SET is_active = 1 WHERE id = :id', ['id' => $id]);
            $this->touchLiveBoards($db);
            $this->audit->record(
                $actor->id, 'position_restore', 'position', $id, $before, $this->snapshot($id)
            );
        });

        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /**
     * The spec 7.2 mapping rule, verbatim and in order. Order matters: the
     * crosswalk centre is called "Center Starter", and a rule that checked
     * Starter first would file it wrong.
     */
    public function skillFor(int $groupId, string $label): ?int
    {
        $groupCode = (string) $this->db->value(
            'SELECT code FROM position_group WHERE id = :id', ['id' => $groupId]
        );

        $code = null;
        if (str_contains($groupCode, 'crosswalk')) {
            $code = match (true) {
                stripos($label, 'Center') !== false => 'crosswalk_middle',
                stripos($label, 'Bridge') !== false => null,
                default => 'crosswalk_perimeter',
            };
        } elseif (stripos($label, 'Gate') !== false) {
            $code = 'gate';
        } else {
            foreach (['Computer' => 'computer', 'Counter' => 'counter',
                      'Runner' => 'runner', 'Starter' => 'starter'] as $word => $skill) {
                if (stripos($label, $word) !== false) {
                    $code = $skill;
                    break;
                }
            }
        }

        if ($code === null) {
            return null;
        }

        $id = $this->db->value('SELECT id FROM skill WHERE code = :code', ['code' => $code]);

        return $id === null ? null : (int) $id;
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> with 'error' set when invalid
     */
    private function normalise(array $input): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 80) {
            return ['error' => 'A position needs a name, at most 80 characters.'];
        }

        $groupId = (int) ($input['group_id'] ?? 0);
        $group = $this->db->one('SELECT id FROM position_group WHERE id = :id', ['id' => $groupId]);
        if ($group === null) {
            return ['error' => 'That group does not exist.'];
        }

        $sort = (int) ($input['sort_order'] ?? 0);
        if ($sort < 0 || $sort > 65535) {
            return ['error' => 'Sort order must be between 0 and 65535.'];
        }

        $phases = [];
        $anyPresent = false;
        foreach (self::PHASES as $phase) {
            $present = !empty($input['phases'][$phase]['present']);
            $anyPresent = $anyPresent || $present;
            $phases[$phase] = [
                'present' => $present,
                'multi_assign' => $present && !empty($input['phases'][$phase]['multi_assign']),
                'carry_forward' => $present && !empty($input['phases'][$phase]['carry_forward']),
                'is_critical' => $present && !empty($input['phases'][$phase]['is_critical']),
            ];
        }
        if (!$anyPresent) {
            return ['error' => 'A position has to be present in at least one phase — retire it instead.'];
        }

        // Carry-forward is an Unload-to-Bump-and-Run inheritance (spec 6.9.5):
        // it only means something on a position present in both phases.
        if (($phases['unload']['carry_forward'] || $phases['bump_run']['carry_forward'])
            && !($phases['unload']['present'] && $phases['bump_run']['present'])) {
            return ['error' => 'Carry-forward needs the position in both phases.'];
        }
        if ($phases['unload']['carry_forward'] !== $phases['bump_run']['carry_forward']) {
            return ['error' => 'Carry-forward is set on both phases or neither (spec 8.2).'];
        }

        return [
            'error' => null,
            'label' => $label,
            'group_id' => $groupId,
            'is_radio' => empty($input['is_radio']) ? 0 : 1,
            'sort_order' => $sort,
            'phases' => $phases,
        ];
    }

    /** @param array<string, array<string, bool>> $phases */
    private function writePhases(Database $db, int $positionId, array $phases): void
    {
        foreach ($phases as $phase => $flags) {
            if (!$flags['present']) {
                continue;
            }
            $db->execute(
                'INSERT INTO position_phase (position_id, phase, multi_assign, carry_forward, is_critical)
                 VALUES (:position_id, :phase, :multi_assign, :carry_forward, :is_critical)',
                [
                    'position_id' => $positionId,
                    'phase' => $phase,
                    'multi_assign' => $flags['multi_assign'] ? 1 : 0,
                    'carry_forward' => $flags['carry_forward'] ? 1 : 0,
                    'is_critical' => $flags['is_critical'] ? 1 : 0,
                ]
            );
        }
    }

    /**
     * Current assignments on shifts that have not ended, optionally in one
     * phase — the people who would vanish from a live board.
     */
    private function liveAssignments(int $positionId, ?string $phase = null): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM assignment a
               JOIN shift sh ON sh.id = a.shift_id
              WHERE a.position_id = :id AND a.is_current = 1
                AND sh.ends_at > UTC_TIMESTAMP()'
            . ($phase === null ? '' : ' AND a.phase = :phase'),
            $phase === null ? ['id' => $positionId] : ['id' => $positionId, 'phase' => $phase]
        );
    }

    /**
     * A matrix change redraws every board that is still live, so their
     * pollers need to hear about it (spec 10.2).
     */
    private function touchLiveBoards(Database $db): void
    {
        $db->execute(
            'UPDATE state_version sv
               JOIN shift sh ON sh.id = sv.shift_id
                SET sv.version = sv.version + 1
              WHERE sh.ends_at > UTC_TIMESTAMP()'
        );
    }

    /**
     * The position as the audit log records it: every editable fact, phases
     * included, in one comparable array.
     *
     * @return array<string, mixed>|null
     */
    private function snapshot(int $id): ?array
    {
        $position = $this->position($id);
        if ($position === null) {
            return null;
        }

        return [
            'group_id' => (int) $position['group_id'],
            'label' => (string) $position['label'],
            'is_radio' => (int) $position['is_radio'] === 1,
            'skill' => $position['skill_label'],
            'sort_order' => (int) $position['sort_order'],
            'is_active' => (int) $position['is_active'] === 1,
            'phases' => $position['phases'],
        ];
    }

    private static function phaseName(string $phase): string
    {
        return $phase === 'unload' ? 'Unload' : 'Bump and Run';
    }

    private static function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
