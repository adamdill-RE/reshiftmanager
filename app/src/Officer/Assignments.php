<?php

declare(strict_types=1);

namespace Resm\Officer;

use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * Placing people on positions (spec 6.9.4), and the carry-forward rule (6.9.5).
 *
 * Two officers will assign at the same moment. The rules that stop that
 * double-booking anybody are the two unique indexes on assignment, not
 * anything in this file: uq_assignment_person is one position per person per
 * phase, uq_assignment_slot is one person per slot. So nothing here reads the
 * board and then writes to it. Every placement is an INSERT that either lands
 * or comes back 1062, and the losing officer is told someone else just took
 * that spot rather than quietly overwriting the winner.
 *
 * The INSERT is an INSERT ... SELECT against position_phase for the same
 * reason. It validates the position against the phase, the shift's active
 * groups and the retired flag in the same statement that writes the row, and
 * it takes is_multi from the matrix rather than from the request — so a
 * hand-posted form cannot claim a one-to-one position holds a crowd.
 *
 * Nothing here consults a skill. Certification is not a permission and
 * preference is not a claim: neither blocks a placement and neither warns
 * about one (7.4).
 */
final class Assignments
{
    public function __construct(
        private Database $db,
        private AuditLog $audit,
    ) {
    }

    /**
     * Place someone on a position.
     *
     * @param array<string, mixed> $shift
     * @return array{ok: bool, error: ?string, carried: bool, vacated: int, taken: bool}
     */
    public function assign(
        Identity $actor,
        array $shift,
        string $phase,
        int $positionId,
        int $userId,
        ?DateTimeImmutable $now = null,
    ): array {
        if (!PhaseControl::isPhase($phase)) {
            return self::fail('That is not a phase.');
        }

        $shiftId = (int) $shift['id'];
        $teamId = (int) $shift['team_id'];
        $seasonId = (int) $shift['season_id'];

        // Spec 6.9.4 rule 5: only checked-in committeemen are available. The
        // sheet already filters to them; this is the same rule enforced on the
        // write, because the sheet is presentation.
        if (!$this->isAvailable($shiftId, $teamId, $seasonId, $userId)) {
            return self::fail('That person is not checked in on this shift.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $vacated = 0;
        $carried = false;
        $placed = 0;

        try {
            $this->db->transaction(function (Database $db) use (
                $shiftId, $phase, $positionId, $userId, $actor, $now,
                &$vacated, &$carried, &$placed
            ): void {
                // Rule 1: a person occupies at most one position per phase, and
                // assigning someone already placed vacates the prior spot in the
                // same transaction. There is no way to double-book a person.
                $vacated = $db->execute(
                    'UPDATE assignment
                        SET is_current = 0, vacated_at = :at
                      WHERE shift_id = :shift_id AND phase = :phase
                        AND user_id = :user_id AND is_current = 1',
                    [
                        'at' => $now->format('Y-m-d H:i:s'),
                        'shift_id' => $shiftId,
                        'phase' => $phase,
                        'user_id' => $userId,
                    ]
                );

                // Spec 6.9.5: an officer may override any inherited assignment.
                // An inherited row is a shadow of the other phase's board, not
                // a decision anybody made, so it yields to a hand placement --
                // otherwise the slot index would refuse the override and the
                // officer would be told a spot was taken by a row that only
                // exists because he filled it in the other phase. A genuine
                // race, against a hand-placed row, still comes back 1062.
                //
                // Never on a multi position: there the slot key includes the
                // user, so nothing collides and clearing the position would
                // throw off the other people standing on it.
                $db->execute(
                    'UPDATE assignment
                        SET is_current = 0, vacated_at = :at
                      WHERE shift_id = :shift_id AND phase = :phase
                        AND position_id = :position_id
                        AND is_current = 1 AND is_inherited = 1 AND is_multi = 0',
                    [
                        'at' => $now->format('Y-m-d H:i:s'),
                        'shift_id' => $shiftId,
                        'phase' => $phase,
                        'position_id' => $positionId,
                    ]
                );

                $placed = $this->insert($db, $shiftId, $phase, $positionId, $userId, $actor, $now, 'manual', false);

                if ($placed === 0) {
                    // Nothing matched: the position is retired, not in this
                    // phase, or in a group this shift has switched off.
                    return;
                }

                if ($phase === 'unload') {
                    $carried = $this->carryForward($db, $shiftId, $positionId, $userId, $actor, $now);
                }

                $db->execute(
                    'UPDATE state_version SET version = version + 1 WHERE shift_id = :shift_id',
                    ['shift_id' => $shiftId]
                );
            });
        } catch (PDOException $e) {
            $key = self::duplicateKey($e);
            if ($key === null) {
                throw $e;
            }

            // The transaction rolled back, so the prior position this officer
            // was about to move him off is still his. Nothing was written.
            return [
                'ok' => false,
                'error' => $key === 'uq_assignment_person'
                    ? 'Someone else just moved that person somewhere else. The board has been reloaded.'
                    : 'Someone else just took that spot. The board has been reloaded.',
                'carried' => false,
                'vacated' => 0,
                'taken' => true,
            ];
        }

        if ($placed === 0) {
            return self::fail('That position is not on this board.');
        }

        $this->audit->record(
            $actor->id,
            'assign',
            'assignment',
            $positionId,
            null,
            ['shift_id' => $shiftId, 'phase' => $phase, 'user_id' => $userId, 'carried' => $carried],
            $shiftId
        );

        return ['ok' => true, 'error' => null, 'carried' => $carried, 'vacated' => $vacated, 'taken' => false];
    }

    /**
     * Take someone off a position (spec 6.9.4 rule 4).
     *
     * @param array<string, mixed> $shift
     * @return array{ok: bool, error: ?string, carried: bool, vacated: int, taken: bool}
     */
    public function vacate(
        Identity $actor,
        array $shift,
        string $phase,
        int $positionId,
        int $userId,
        ?DateTimeImmutable $now = null,
    ): array {
        if (!PhaseControl::isPhase($phase)) {
            return self::fail('That is not a phase.');
        }

        $shiftId = (int) $shift['id'];
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $vacated = 0;
        $carried = false;

        $this->db->transaction(function (Database $db) use (
            $shiftId, $phase, $positionId, $userId, $now, &$vacated, &$carried
        ): void {
            $vacated = $db->execute(
                'UPDATE assignment
                    SET is_current = 0, vacated_at = :at
                  WHERE shift_id = :shift_id AND phase = :phase
                    AND position_id = :position_id AND user_id = :user_id
                    AND is_current = 1',
                [
                    'at' => $now->format('Y-m-d H:i:s'),
                    'shift_id' => $shiftId,
                    'phase' => $phase,
                    'position_id' => $positionId,
                    'user_id' => $userId,
                ]
            );

            if ($vacated === 0) {
                return;
            }

            // While a carrying position is still tracking Unload, clearing it
            // there clears the inherited row it put in Bump and Run. After an
            // override it tracks nothing, and this does nothing (6.9.5).
            if ($phase === 'unload' && $this->carries($db, $positionId) && !$this->overridden($db, $shiftId, $positionId)) {
                $carried = $db->execute(
                    "UPDATE assignment
                        SET is_current = 0, vacated_at = :at
                      WHERE shift_id = :shift_id AND phase = 'bump_run'
                        AND position_id = :position_id AND user_id = :user_id
                        AND is_current = 1 AND is_inherited = 1",
                    [
                        'at' => $now->format('Y-m-d H:i:s'),
                        'shift_id' => $shiftId,
                        'position_id' => $positionId,
                        'user_id' => $userId,
                    ]
                ) > 0;
            }

            $db->execute(
                'UPDATE state_version SET version = version + 1 WHERE shift_id = :shift_id',
                ['shift_id' => $shiftId]
            );
        });

        if ($vacated === 0) {
            return self::fail('That person is not on that position any more.');
        }

        $this->audit->record(
            $actor->id,
            'vacate',
            'assignment',
            $positionId,
            ['shift_id' => $shiftId, 'phase' => $phase, 'user_id' => $userId],
            ['carried' => $carried],
            $shiftId
        );

        return ['ok' => true, 'error' => null, 'carried' => $carried, 'vacated' => $vacated, 'taken' => false];
    }

    /**
     * Write one assignment row.
     *
     * INSERT ... SELECT so the position is validated against the phase, the
     * shift's active groups and the retired flag by the same statement that
     * writes — and so is_multi comes from the matrix rather than the request.
     * Zero rows means the position is not on this board.
     */
    private function insert(
        Database $db,
        int $shiftId,
        string $phase,
        int $positionId,
        int $userId,
        Identity $actor,
        DateTimeImmutable $now,
        string $source,
        bool $inherited,
    ): int {
        return $db->execute(
            'INSERT INTO assignment
                 (shift_id, phase, position_id, user_id, assigned_by, assigned_at,
                  is_current, is_inherited, is_multi, source)
             SELECT :shift_id, :phase, pp.position_id, :user_id, :actor, :at,
                    1, :inherited, pp.multi_assign, :source
               FROM position_phase pp
               JOIN position p ON p.id = pp.position_id AND p.is_active = 1
               JOIN shift_group sg
                 ON sg.group_id = p.group_id AND sg.shift_id = :sg_shift AND sg.is_active = 1
              WHERE pp.position_id = :position_id AND pp.phase = :pp_phase',
            [
                'shift_id' => $shiftId,
                'phase' => $phase,
                'user_id' => $userId,
                'actor' => $actor->id,
                'at' => $now->format('Y-m-d H:i:s'),
                'inherited' => $inherited ? 1 : 0,
                'source' => $source,
                'sg_shift' => $shiftId,
                'position_id' => $positionId,
                'pp_phase' => $phase,
            ]
        );
    }

    /**
     * Carry an Unload placement into Bump and Run (spec 6.9.5).
     *
     * Three things stop it, and all three are the officer's own decisions:
     * the position does not carry; the Bump and Run slot has been overridden
     * by hand, after which it tracks Unload no longer; or this man is already
     * standing somewhere in Bump and Run by hand, and rule 2 says he may hold
     * different positions in the two phases.
     */
    private function carryForward(
        Database $db,
        int $shiftId,
        int $positionId,
        int $userId,
        Identity $actor,
        DateTimeImmutable $now,
    ): bool {
        if (!$this->carries($db, $positionId) || $this->overridden($db, $shiftId, $positionId)) {
            return false;
        }

        $byHand = (int) $db->value(
            "SELECT COUNT(*) FROM assignment
              WHERE shift_id = :shift_id AND phase = 'bump_run'
                AND user_id = :user_id AND is_current = 1 AND is_inherited = 0",
            ['shift_id' => $shiftId, 'user_id' => $userId]
        );

        if ($byHand > 0) {
            return false;
        }

        // Clear the inherited rows being replaced: whoever this position was
        // carrying, and wherever this man was carried to before. Both are
        // inherited by construction — a hand-placed row on either would have
        // stopped us above — so nothing an officer chose is being undone.
        $db->execute(
            "UPDATE assignment
                SET is_current = 0, vacated_at = :at
              WHERE shift_id = :shift_id AND phase = 'bump_run' AND is_current = 1
                AND (position_id = :position_id OR user_id = :user_id)",
            [
                'at' => $now->format('Y-m-d H:i:s'),
                'shift_id' => $shiftId,
                'position_id' => $positionId,
                'user_id' => $userId,
            ]
        );

        return $this->insert($db, $shiftId, 'bump_run', $positionId, $userId, $actor, $now, 'carry_forward', true) > 0;
    }

    /** Does this position carry from Unload into Bump and Run (spec 6.9.5)? */
    private function carries(Database $db, int $positionId): bool
    {
        return (int) $db->value(
            "SELECT COUNT(*)
               FROM position_phase u
               JOIN position_phase b ON b.position_id = u.position_id AND b.phase = 'bump_run'
              WHERE u.position_id = :position_id AND u.phase = 'unload'
                AND u.carry_forward = 1 AND b.carry_forward = 1",
            ['position_id' => $positionId]
        ) > 0;
    }

    /**
     * Has an officer placed anyone on this Bump and Run position by hand?
     *
     * Spec 6.9.5: once overridden, the position stops inheriting and no longer
     * tracks Unload. The marker is the assignment history itself rather than a
     * flag somewhere else — any row on this position that was not written by
     * carry-forward is an officer having decided, and that decision outlives
     * the row being vacated again.
     */
    private function overridden(Database $db, int $shiftId, int $positionId): bool
    {
        return (int) $db->value(
            "SELECT COUNT(*) FROM assignment
              WHERE shift_id = :shift_id AND phase = 'bump_run'
                AND position_id = :position_id AND source <> 'carry_forward'",
            ['shift_id' => $shiftId, 'position_id' => $positionId]
        ) > 0;
    }

    /** On this team's roster, active, a committeeman, and checked in. */
    private function isAvailable(int $shiftId, int $teamId, int $seasonId, int $userId): bool
    {
        return (int) $this->db->value(
            "SELECT COUNT(*)
               FROM team_member tm
               JOIN `user` u ON u.id = tm.user_id
              WHERE tm.user_id = :user_id
                AND tm.team_id = :team_id
                AND tm.season_id = :season_id
                AND u.is_active = 1
                AND u.role = 'committeeman'
                AND (SELECT ce.type FROM check_event ce
                      WHERE ce.shift_id = :check_shift AND ce.user_id = tm.user_id
                      ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) = 'in'",
            [
                'user_id' => $userId,
                'team_id' => $teamId,
                'season_id' => $seasonId,
                'check_shift' => $shiftId,
            ]
        ) > 0;
    }

    /**
     * Which unique index a 1062 came from, or null if it was not a duplicate.
     *
     * MySQL names the key in the message; the name is matched rather than
     * parsed out, so a message format change degrades to the generic answer
     * instead of throwing.
     */
    public static function duplicateKey(PDOException $e): ?string
    {
        if (($e->errorInfo[1] ?? null) !== 1062) {
            return null;
        }

        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if (str_contains($message, 'uq_assignment_person')) {
            return 'uq_assignment_person';
        }

        return 'uq_assignment_slot';
    }

    /** @return array{ok: false, error: string, carried: false, vacated: 0, taken: false} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'carried' => false, 'vacated' => 0, 'taken' => false];
    }
}
