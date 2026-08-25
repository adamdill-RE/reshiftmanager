<?php

declare(strict_types=1);

namespace Resm\Shift;

use Resm\Database;

/**
 * Who else is on a shift (spec 6.5).
 *
 * My Shift Status answers two questions a committeeman actually has on the
 * tarmac: who is standing near me, and who do I call. Both are phone numbers
 * with a name attached, which is why every row here carries the E.164 form —
 * a tap-to-call link that dials the wrong person is worse than no link.
 */
final class Roster
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Everyone else holding a position in the same group, this phase.
     *
     * The group rather than the position, because that is who is within
     * shouting distance: the man on Reed Runner 2 wants Reed Starter 1, not
     * whoever happens to be on the other side of the tarmac.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groupMates(int $shiftId, string $phase, int $userId, int $groupId): array
    {
        return $this->db->all(
            'SELECT u.id, u.first_name, u.last_name, u.phone, u.phone_e164,
                    p.label AS position, pp.is_critical
             FROM assignment a
             JOIN `user` u ON u.id = a.user_id
             JOIN position p ON p.id = a.position_id
             JOIN position_phase pp ON pp.position_id = p.id AND pp.phase = a.phase
             WHERE a.shift_id = :shift_id
               AND a.phase = :phase
               AND a.is_current = 1
               AND p.group_id = :group_id
               AND a.user_id <> :user_id
             ORDER BY p.sort_order, u.last_name',
            [
                'shift_id' => $shiftId,
                'phase' => $phase,
                'group_id' => $groupId,
                'user_id' => $userId,
            ]
        );
    }

    /**
     * The officers covering this shift's team (spec 6.5, 6.10.6).
     *
     * Admins are included: on a short night an admin is an officer with more
     * buttons, and a committeeman who needs somebody does not care which.
     *
     * @return array<int, array<string, mixed>>
     */
    public function officers(int $shiftId): array
    {
        return $this->db->all(
            "SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.phone, u.phone_e164
             FROM shift s
             JOIN team_member tm ON tm.team_id = s.team_id AND tm.season_id = s.season_id
             JOIN `user` u ON u.id = tm.user_id
             WHERE s.id = :shift_id
               AND u.is_active = 1
               AND u.role IN ('officer', 'admin')
             ORDER BY u.role DESC, u.last_name",
            ['shift_id' => $shiftId]
        );
    }

    /**
     * Every shift this user is rostered on in a season (spec 6.6).
     *
     * Carries the first check-in and last check-out, which is what makes the
     * list a record of what he worked rather than a copy of the schedule.
     *
     * @return array<int, array<string, mixed>>
     */
    public function season(int $userId, int $seasonId): array
    {
        return $this->db->all(
            "SELECT s.id, s.shift_type, s.starts_at, s.ends_at, t.name AS team_name,
                    (SELECT MIN(ce.occurred_at) FROM check_event ce
                      WHERE ce.shift_id = s.id AND ce.user_id = :in_user AND ce.type = 'in') AS first_in,
                    (SELECT MAX(ce.occurred_at) FROM check_event ce
                      WHERE ce.shift_id = s.id AND ce.user_id = :out_user AND ce.type = 'out') AS last_out
             FROM shift s
             JOIN team t ON t.id = s.team_id
             JOIN team_member tm ON tm.team_id = s.team_id AND tm.season_id = s.season_id
             WHERE tm.user_id = :member_user AND s.season_id = :season_id
             ORDER BY s.starts_at DESC",
            [
                'in_user' => $userId,
                'out_user' => $userId,
                'member_user' => $userId,
                'season_id' => $seasonId,
            ]
        );
    }
}
