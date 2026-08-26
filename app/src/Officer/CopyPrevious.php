<?php

declare(strict_types=1);

namespace Resm\Officer;

use DateTimeImmutable;
use DateTimeZone;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * Copy From Previous Shift (spec 6.9.6).
 *
 * The single largest time-saver in the application: largely the same people
 * stand in the same places night after night, so a twenty-minute job becomes a
 * three-minute one. The officer picks a prior shift, sees a preview, confirms,
 * and then fills the flagged holes by hand.
 *
 * Only people who are checked in tonight are placed. Everybody else's position
 * is left vacant, which is the point of the preview: an officer needs to know
 * before he confirms how many holes he is about to be left with, not after.
 *
 * One phase at a time, named on the screen. Copying both boards at once would
 * make the preview's figures ambiguous — "how many will be applied" would be a
 * sum across two boards the officer is looking at separately.
 */
final class CopyPrevious
{
    /** How far back a shift is still worth offering as a source. */
    private const LOOK_BACK_DAYS = 21;

    public function __construct(
        private Database $db,
        private AuditLog $audit,
    ) {
    }

    /**
     * The team's recent shifts that have a board worth copying.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sources(int $teamId, int $seasonId, int $excludeShiftId, string $phase, DateTimeImmutable $now): array
    {
        $rows = $this->db->all(
            'SELECT s.id, s.shift_type, s.starts_at, s.ends_at,
                    (SELECT COUNT(*) FROM assignment a
                      WHERE a.shift_id = s.id AND a.phase = :count_phase AND a.is_current = 1) AS placements
               FROM shift s
              WHERE s.team_id = :team_id
                AND s.season_id = :season_id
                AND s.id <> :exclude
                AND s.starts_at BETWEEN :from AND :to
              ORDER BY s.starts_at DESC',
            [
                'count_phase' => $phase,
                'team_id' => $teamId,
                'season_id' => $seasonId,
                'exclude' => $excludeShiftId,
                'from' => $now->modify('-' . self::LOOK_BACK_DAYS . ' days')->format('Y-m-d H:i:s'),
                'to' => $now->format('Y-m-d H:i:s'),
            ]
        );

        $utc = new DateTimeZone('UTC');
        $offered = [];
        foreach ($rows as $row) {
            if ((int) $row['placements'] === 0) {
                continue;
            }
            $row['starts_at_utc'] = new DateTimeImmutable((string) $row['starts_at'], $utc);
            $row['ends_at_utc'] = new DateTimeImmutable((string) $row['ends_at'], $utc);
            $offered[] = $row;
        }

        return $offered;
    }

    /**
     * What confirming would do (spec 6.9.6 step 2).
     *
     * Three figures, and the third is the one that matters: which positions
     * will be left open because the man who stood there last time is not here
     * tonight.
     *
     * @return array{
     *     apply: array<int, array<string, mixed>>,
     *     missing: array<int, array<string, mixed>>,
     *     blocked: array<int, array<string, mixed>>
     * }
     */
    public function preview(int $fromShiftId, int $toShiftId, int $teamId, int $seasonId, string $phase): array
    {
        $rows = $this->db->all(
            "SELECT a.position_id, a.user_id, p.label AS position, g.label AS group_label,
                    pp.is_critical, pp.multi_assign,
                    u.first_name, u.last_name, u.phone, u.phone_e164,
                    (SELECT ce.type FROM check_event ce
                      WHERE ce.shift_id = :check_shift AND ce.user_id = a.user_id
                      ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) AS check_state,
                    EXISTS (SELECT 1 FROM assignment held
                             WHERE held.shift_id = :held_shift AND held.phase = :held_phase
                               AND held.position_id = a.position_id AND held.is_current = 1) AS position_taken,
                    EXISTS (SELECT 1 FROM assignment busy
                             WHERE busy.shift_id = :busy_shift AND busy.phase = :busy_phase
                               AND busy.user_id = a.user_id AND busy.is_current = 1) AS person_placed,
                    EXISTS (SELECT 1 FROM shift_group sg
                             WHERE sg.shift_id = :group_shift AND sg.group_id = p.group_id
                               AND sg.is_active = 1) AS group_active
               FROM assignment a
               JOIN position p ON p.id = a.position_id AND p.is_active = 1
               JOIN position_group g ON g.id = p.group_id
               JOIN position_phase pp ON pp.position_id = p.id AND pp.phase = a.phase
               JOIN `user` u ON u.id = a.user_id
               JOIN team_member tm
                 ON tm.user_id = a.user_id AND tm.team_id = :team_id AND tm.season_id = :season_id
              WHERE a.shift_id = :from_shift
                AND a.phase = :from_phase
                AND a.is_current = 1
                AND u.is_active = 1
              ORDER BY g.sort_order, p.sort_order",
            [
                'check_shift' => $toShiftId,
                'held_shift' => $toShiftId,
                'held_phase' => $phase,
                'busy_shift' => $toShiftId,
                'busy_phase' => $phase,
                'group_shift' => $toShiftId,
                'team_id' => $teamId,
                'season_id' => $seasonId,
                'from_shift' => $fromShiftId,
                'from_phase' => $phase,
            ]
        );

        $apply = [];
        $missing = [];
        $blocked = [];

        foreach ($rows as $row) {
            $row['name'] = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            $row['list_name'] = (string) $row['last_name'] . ', ' . (string) $row['first_name'];

            if ((int) $row['group_active'] !== 1) {
                // The group is switched off for tonight (spec 5.4), so the
                // position is not on this board at all.
                continue;
            }

            if ((string) ($row['check_state'] ?? '') !== 'in') {
                // Spec 6.9.6 step 3: his position is left vacant, and flagged.
                $missing[] = $row;
                continue;
            }

            $taken = (int) $row['position_taken'] === 1 && (int) $row['multi_assign'] !== 1;
            if ($taken || (int) $row['person_placed'] === 1) {
                // Somebody is already standing there tonight, or this man is.
                // A copy adds to a board; it never overwrites one.
                $blocked[] = $row;
                continue;
            }

            $apply[] = $row;
        }

        return ['apply' => $apply, 'missing' => $missing, 'blocked' => $blocked];
    }

    /**
     * Apply the copy (spec 6.9.6 step 3).
     *
     * INSERT ... SELECT with the same conditions the preview reported, so what
     * lands is what was shown rather than a second, separately-computed answer.
     *
     * INSERT IGNORE, and only here. A bulk copy of fifty placements should not
     * lose all fifty because another officer took one spot while the officer
     * was reading the preview; the row that lost is skipped and the count comes
     * back lower than the preview said. Single placements are the opposite —
     * there the 1062 is the answer, and Assignments::assign surfaces it.
     *
     * @param array<string, mixed> $shift
     * @return array{ok: bool, error: ?string, applied: int, carried: int}
     */
    public function apply(
        Identity $actor,
        array $shift,
        int $fromShiftId,
        string $phase,
        ?DateTimeImmutable $now = null,
    ): array {
        if (!PhaseControl::isPhase($phase)) {
            return ['ok' => false, 'error' => 'That is not a phase.', 'applied' => 0, 'carried' => 0];
        }

        $toShiftId = (int) $shift['id'];
        $teamId = (int) $shift['team_id'];
        $seasonId = (int) $shift['season_id'];

        if ($fromShiftId === $toShiftId) {
            return ['ok' => false, 'error' => 'That is this shift.', 'applied' => 0, 'carried' => 0];
        }

        // The source has to belong to the same team and season, or an officer
        // could copy another team's board by posting its shift id.
        $source = $this->db->one(
            'SELECT id FROM shift WHERE id = :id AND team_id = :team_id AND season_id = :season_id',
            ['id' => $fromShiftId, 'team_id' => $teamId, 'season_id' => $seasonId]
        );

        if ($source === null) {
            return [
                'ok' => false,
                'error' => 'That is not one of this team\'s shifts.',
                'applied' => 0,
                'carried' => 0,
            ];
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $applied = 0;
        $carried = 0;

        $this->db->transaction(function (Database $db) use (
            $fromShiftId, $toShiftId, $phase, $actor, $now, &$applied, &$carried
        ): void {
            $applied = $db->execute(
                "INSERT IGNORE INTO assignment
                     (shift_id, phase, position_id, user_id, assigned_by, assigned_at,
                      is_current, is_inherited, is_multi, source)
                 SELECT :to_shift, :phase, a.position_id, a.user_id, :actor, :at,
                        1, 0, pp.multi_assign, 'copy_previous'
                   FROM assignment a
                   JOIN position p ON p.id = a.position_id AND p.is_active = 1
                   JOIN position_phase pp ON pp.position_id = p.id AND pp.phase = :pp_phase
                   JOIN `user` u ON u.id = a.user_id AND u.is_active = 1
                   JOIN shift_group sg
                     ON sg.shift_id = :group_shift AND sg.group_id = p.group_id AND sg.is_active = 1
                   JOIN shift target ON target.id = :target_shift
                   JOIN team_member tm
                     ON tm.user_id = a.user_id
                    AND tm.team_id = target.team_id
                    AND tm.season_id = target.season_id
                  WHERE a.shift_id = :from_shift
                    AND a.phase = :from_phase
                    AND a.is_current = 1
                    AND (SELECT ce.type FROM check_event ce
                          WHERE ce.shift_id = :check_shift AND ce.user_id = a.user_id
                          ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) = 'in'
                    AND NOT EXISTS (
                        SELECT 1 FROM assignment busy
                         WHERE busy.shift_id = :busy_shift AND busy.phase = :busy_phase
                           AND busy.user_id = a.user_id AND busy.is_current = 1
                    )
                    AND (pp.multi_assign = 1 OR NOT EXISTS (
                        SELECT 1 FROM assignment held
                         WHERE held.shift_id = :held_shift AND held.phase = :held_phase
                           AND held.position_id = a.position_id AND held.is_current = 1
                    ))",
                [
                    'to_shift' => $toShiftId,
                    'phase' => $phase,
                    'actor' => $actor->id,
                    'at' => $now->format('Y-m-d H:i:s'),
                    'pp_phase' => $phase,
                    'group_shift' => $toShiftId,
                    'target_shift' => $toShiftId,
                    'from_shift' => $fromShiftId,
                    'from_phase' => $phase,
                    'check_shift' => $toShiftId,
                    'busy_shift' => $toShiftId,
                    'busy_phase' => $phase,
                    'held_shift' => $toShiftId,
                    'held_phase' => $phase,
                ]
            );

            // A copy into Unload is still an assignment, so 6.9.5 applies to it:
            // the carrying positions place the same man in Bump and Run. Without
            // this, copying Unload and then flipping the phase would show an
            // empty board, which is exactly the link carry-forward promises.
            //
            // Written as the invariant rather than as "the rows I just copied":
            // any current Unload placement on a carrying position whose Bump and
            // Run counterpart is free and unoverridden should be carried, so
            // running it repairs the board rather than only extending it.
            if ($phase === 'unload') {
                $carried = $this->carryForward($db, $toShiftId, $actor, $now);
            }

            if ($applied > 0 || $carried > 0) {
                $db->execute(
                    'UPDATE state_version SET version = version + 1 WHERE shift_id = :shift_id',
                    ['shift_id' => $toShiftId]
                );
            }
        });

        $this->audit->record(
            $actor->id,
            'copy_previous',
            'shift',
            $toShiftId,
            ['from_shift' => $fromShiftId, 'phase' => $phase],
            ['applied' => $applied, 'carried' => $carried],
            $toShiftId
        );

        return ['ok' => true, 'error' => null, 'applied' => $applied, 'carried' => $carried];
    }

    /**
     * Carry every eligible Unload placement into Bump and Run (spec 6.9.5).
     *
     * The three things that stop a single placement carrying stop these too,
     * and for the same reasons: the position does not carry, the Bump and Run
     * slot was overridden by hand -- any row there that carry-forward did not
     * write -- or the man is already standing somewhere in Bump and Run.
     */
    private function carryForward(Database $db, int $shiftId, Identity $actor, DateTimeImmutable $now): int
    {
        return $db->execute(
            "INSERT IGNORE INTO assignment
                 (shift_id, phase, position_id, user_id, assigned_by, assigned_at,
                  is_current, is_inherited, is_multi, source)
             SELECT :to_shift, 'bump_run', a.position_id, a.user_id, :actor, :at,
                    1, 1, carried.multi_assign, 'carry_forward'
               FROM assignment a
               JOIN position_phase unloaded
                 ON unloaded.position_id = a.position_id AND unloaded.phase = 'unload'
               JOIN position_phase carried
                 ON carried.position_id = a.position_id AND carried.phase = 'bump_run'
              WHERE a.shift_id = :from_shift
                AND a.phase = 'unload'
                AND a.is_current = 1
                AND unloaded.carry_forward = 1
                AND carried.carry_forward = 1
                AND NOT EXISTS (
                    SELECT 1 FROM assignment held
                     WHERE held.shift_id = :held_shift AND held.phase = 'bump_run'
                       AND held.position_id = a.position_id AND held.is_current = 1
                )
                AND NOT EXISTS (
                    SELECT 1 FROM assignment overridden
                     WHERE overridden.shift_id = :override_shift AND overridden.phase = 'bump_run'
                       AND overridden.position_id = a.position_id
                       AND overridden.source <> 'carry_forward'
                )
                AND NOT EXISTS (
                    SELECT 1 FROM assignment busy
                     WHERE busy.shift_id = :busy_shift AND busy.phase = 'bump_run'
                       AND busy.user_id = a.user_id AND busy.is_current = 1
                )",
            [
                'to_shift' => $shiftId,
                'actor' => $actor->id,
                'at' => $now->format('Y-m-d H:i:s'),
                'from_shift' => $shiftId,
                'held_shift' => $shiftId,
                'override_shift' => $shiftId,
                'busy_shift' => $shiftId,
            ]
        );
    }
}
