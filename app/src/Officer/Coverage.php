<?php

declare(strict_types=1);

namespace Resm\Officer;

use Resm\Database;

/**
 * The coverage counter (spec 6.9.2).
 *
 * Five figures across the top of every officer screen. The one that matters is
 * critical coverage, and it is allowed to read red: Bump and Run has 37
 * critical positions and a shift can run 25 people, so on a short night red is
 * the truth rather than a fault (spec 5.4, 8.1). Nothing here rounds that
 * away, and nothing treats a shortfall as an error state.
 *
 * "Not checked in" is deliberately not the complement of "checked in". A man
 * who checked in and went home is neither: he is not on the tarmac, and he is
 * not someone to ring either. Only a roster member with no check event at all
 * is Absent (spec 6.9.8), and the gap between the two figures is the people
 * who have already left.
 *
 * Every repeated value is bound under its own name. PDO with emulated prepares
 * off will not accept the same placeholder twice, and the failure is a runtime
 * exception rather than anything a reader would spot.
 */
final class Coverage
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     roster: int, checked_in: int, not_checked_in: int, left: int,
     *     assigned: int, unassigned: int, open: int, positions: int, filled: int,
     *     critical_filled: int, critical_total: int, critical_short: bool
     * }
     */
    public function forShift(int $shiftId, int $teamId, int $seasonId, string $phase): array
    {
        $attendance = $this->db->one(
            "SELECT COUNT(*) AS roster,
                    SUM(CASE WHEN last_type = 'in' THEN 1 ELSE 0 END) AS checked_in,
                    SUM(CASE WHEN last_type IS NULL THEN 1 ELSE 0 END) AS not_checked_in
               FROM (
                    SELECT tm.user_id,
                           (SELECT ce.type FROM check_event ce
                             WHERE ce.shift_id = :shift_id AND ce.user_id = tm.user_id
                             ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) AS last_type
                      FROM team_member tm
                      JOIN `user` u ON u.id = tm.user_id
                     WHERE tm.team_id = :team_id
                       AND tm.season_id = :season_id
                       AND u.is_active = 1
                       AND u.role = 'committeeman'
               ) roster",
            ['shift_id' => $shiftId, 'team_id' => $teamId, 'season_id' => $seasonId]
        ) ?? [];

        $roster = (int) ($attendance['roster'] ?? 0);
        $checkedIn = (int) ($attendance['checked_in'] ?? 0);
        $notCheckedIn = (int) ($attendance['not_checked_in'] ?? 0);

        $assigned = (int) $this->db->value(
            'SELECT COUNT(DISTINCT user_id) FROM assignment
              WHERE shift_id = :shift_id AND phase = :phase AND is_current = 1',
            ['shift_id' => $shiftId, 'phase' => $phase]
        );

        // A multi position holding three people is one filled position, not
        // three — the DISTINCT is what keeps Open honest for the Unload group.
        $board = $this->db->one(
            'SELECT COUNT(*) AS positions,
                    SUM(pp.is_critical) AS critical_total,
                    SUM(CASE WHEN f.position_id IS NULL THEN 0 ELSE 1 END) AS filled,
                    SUM(CASE WHEN pp.is_critical = 1 AND f.position_id IS NOT NULL THEN 1 ELSE 0 END)
                        AS critical_filled
               FROM position_phase pp
               JOIN position p ON p.id = pp.position_id
               JOIN shift_group sg
                 ON sg.group_id = p.group_id
                AND sg.shift_id = :group_shift
                AND sg.is_active = 1
               LEFT JOIN (
                    SELECT DISTINCT position_id FROM assignment
                     WHERE shift_id = :filled_shift AND phase = :filled_phase AND is_current = 1
               ) f ON f.position_id = pp.position_id
              WHERE pp.phase = :board_phase
                AND p.is_active = 1',
            [
                'group_shift' => $shiftId,
                'filled_shift' => $shiftId,
                'filled_phase' => $phase,
                'board_phase' => $phase,
            ]
        ) ?? [];

        $positions = (int) ($board['positions'] ?? 0);
        $filled = (int) ($board['filled'] ?? 0);
        $criticalTotal = (int) ($board['critical_total'] ?? 0);
        $criticalFilled = (int) ($board['critical_filled'] ?? 0);

        return [
            'roster' => $roster,
            'checked_in' => $checkedIn,
            'not_checked_in' => $notCheckedIn,
            // Checked in and then out again: on the roster, not on the tarmac,
            // and not someone to chase either.
            'left' => max(0, $roster - $checkedIn - $notCheckedIn),
            'assigned' => $assigned,
            'unassigned' => max(0, $checkedIn - $assigned),
            'positions' => $positions,
            'filled' => $filled,
            'open' => max(0, $positions - $filled),
            'critical_filled' => $criticalFilled,
            'critical_total' => $criticalTotal,
            'critical_short' => $criticalFilled < $criticalTotal,
        ];
    }
}
