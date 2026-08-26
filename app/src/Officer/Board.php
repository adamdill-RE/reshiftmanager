<?php

declare(strict_types=1);

namespace Resm\Officer;

use Resm\Database;

/**
 * Reading an assign board (spec 6.9.4).
 *
 * Two shapes of the same shift. The board is positions with their holders, for
 * position-first; the pool is checked-in people without a position, for
 * roster-first. Both carry the same facts beside each name — certified and
 * preferred skills (7.3), lunch state, and the overlap warning from 5.5 — so
 * whichever way an officer works, he is deciding on the same information.
 *
 * Nothing in here filters anybody out on the basis of a skill. Certification
 * is not a permission and preference is not a claim (7.4): the chip row
 * narrows the list because the officer asked it to, and that is the whole of
 * it. Who stands where is settled on the ground with the people who turned up.
 */
final class Board
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Every position on this phase's board, in group order, with its holders.
     *
     * One query rather than one per group. The database is on separate
     * hardware (docs/hosting.md) and this is the screen an officer reloads
     * most, so a 95-position board is one round trip and the stitching happens
     * here.
     *
     * @return array<int, array<string, mixed>> groups, each with a positions list
     */
    public function groups(int $shiftId, string $phase): array
    {
        $rows = $this->db->all(
            'SELECT p.id AS position_id, p.label, p.is_radio, p.definition,
                    g.id AS group_id, g.label AS group_label,
                    pp.is_critical, pp.multi_assign, pp.carry_forward,
                    sk.label AS skill_label, sk.code AS skill_code,
                    a.user_id, a.is_inherited, a.source,
                    u.first_name, u.last_name
               FROM position_phase pp
               JOIN position p ON p.id = pp.position_id AND p.is_active = 1
               JOIN position_group g ON g.id = p.group_id
               JOIN shift_group sg
                 ON sg.group_id = g.id AND sg.shift_id = :group_shift AND sg.is_active = 1
               LEFT JOIN skill sk ON sk.id = p.skill_id
               LEFT JOIN assignment a
                 ON a.position_id = p.id
                AND a.shift_id = :assign_shift
                AND a.phase = :assign_phase
                AND a.is_current = 1
               LEFT JOIN `user` u ON u.id = a.user_id
              WHERE pp.phase = :board_phase
              ORDER BY g.sort_order, p.sort_order, u.last_name, u.first_name',
            [
                'group_shift' => $shiftId,
                'assign_shift' => $shiftId,
                'assign_phase' => $phase,
                'board_phase' => $phase,
            ]
        );

        $groups = [];

        foreach ($rows as $row) {
            $groupId = (int) $row['group_id'];
            $positionId = (int) $row['position_id'];

            $groups[$groupId] ??= [
                'id' => $groupId,
                'label' => (string) $row['group_label'],
                'positions' => [],
                'filled' => 0,
                'total' => 0,
            ];

            if (!isset($groups[$groupId]['positions'][$positionId])) {
                $groups[$groupId]['positions'][$positionId] = [
                    'id' => $positionId,
                    'label' => (string) $row['label'],
                    'definition' => $row['definition'],
                    'is_radio' => (int) $row['is_radio'] === 1,
                    'is_critical' => (int) $row['is_critical'] === 1,
                    'multi_assign' => (int) $row['multi_assign'] === 1,
                    'carry_forward' => (int) $row['carry_forward'] === 1,
                    'skill_label' => $row['skill_label'],
                    'skill_code' => $row['skill_code'],
                    'group_label' => (string) $row['group_label'],
                    'holders' => [],
                ];
                $groups[$groupId]['total']++;
            }

            if ($row['user_id'] !== null) {
                if ($groups[$groupId]['positions'][$positionId]['holders'] === []) {
                    $groups[$groupId]['filled']++;
                }
                $groups[$groupId]['positions'][$positionId]['holders'][] = [
                    'user_id' => (int) $row['user_id'],
                    'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                    'list_name' => (string) $row['last_name'] . ', ' . (string) $row['first_name'],
                    'is_inherited' => (int) $row['is_inherited'] === 1,
                    'source' => (string) $row['source'],
                ];
            }
        }

        // Re-key so the templates iterate lists rather than id-keyed maps.
        foreach ($groups as $id => $group) {
            $groups[$id]['positions'] = array_values($group['positions']);
        }

        return array_values($groups);
    }

    /**
     * Vacant critical positions, which pin to the top of the board in red
     * (spec 6.9.4).
     *
     * @param array<int, array<string, mixed>> $groups from groups()
     * @return array<int, array<string, mixed>>
     */
    public static function criticalVacancies(array $groups): array
    {
        $vacant = [];

        foreach ($groups as $group) {
            foreach ($group['positions'] as $position) {
                if ($position['is_critical'] && $position['holders'] === []) {
                    $vacant[] = $position;
                }
            }
        }

        return $vacant;
    }

    /**
     * Every vacant position on this board, for the roster-first sheet.
     *
     * A multi position is offered as long as it exists — the Unload group
     * takes as many people as an officer puts on it (spec 6.9.4 rule 3).
     *
     * @param array<int, array<string, mixed>> $groups from groups()
     * @return array<int, array<string, mixed>>
     */
    public static function vacancies(array $groups): array
    {
        $vacant = [];

        foreach ($groups as $group) {
            foreach ($group['positions'] as $position) {
                if ($position['holders'] === [] || $position['multi_assign']) {
                    $vacant[] = $position;
                }
            }
        }

        return $vacant;
    }

    /**
     * The people an officer can place right now: on the roster, checked in,
     * and not already holding a position in this phase (spec 6.9.4 rule 5).
     *
     * At Lunch is shown rather than hidden. Rule 6 puts a man who has gone to
     * eat back in the pool, and 7.4's principle holds here too — the state is
     * displayed beside the name and the officer decides.
     *
     * @param array{search?: string, skill?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function available(
        int $shiftId,
        int $teamId,
        int $seasonId,
        string $phase,
        array $filters = [],
    ): array {
        $rows = $this->db->all(
            "SELECT u.id, u.first_name, u.last_name, u.phone, u.phone_e164, u.is_walkon,
                    (SELECT le.state FROM lunch_event le
                      WHERE le.shift_id = :lunch_shift AND le.user_id = u.id
                      ORDER BY le.occurred_at DESC, le.id DESC LIMIT 1) AS lunch_state
               FROM team_member tm
               JOIN `user` u ON u.id = tm.user_id
              WHERE tm.team_id = :team_id
                AND tm.season_id = :season_id
                AND u.is_active = 1
                AND u.role = 'committeeman'
                AND (SELECT ce.type FROM check_event ce
                      WHERE ce.shift_id = :check_shift AND ce.user_id = u.id
                      ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) = 'in'
                AND NOT EXISTS (
                    SELECT 1 FROM assignment a
                     WHERE a.shift_id = :assign_shift
                       AND a.phase = :assign_phase
                       AND a.user_id = u.id
                       AND a.is_current = 1
                )
              ORDER BY u.last_name, u.first_name",
            [
                'lunch_shift' => $shiftId,
                'team_id' => $teamId,
                'season_id' => $seasonId,
                'check_shift' => $shiftId,
                'assign_shift' => $shiftId,
                'assign_phase' => $phase,
            ]
        );

        return $this->decorate($rows, $shiftId, $teamId, $seasonId, $filters);
    }

    /**
     * Attach skills and overlap warnings to a list of people, then apply the
     * officer's optional filters.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array{search?: string, skill?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function decorate(
        array $rows,
        int $shiftId,
        int $teamId,
        int $seasonId,
        array $filters = [],
    ): array {
        $skills = $this->skillsByUser($teamId, $seasonId);
        $overlaps = $this->overlaps($shiftId, $teamId, $seasonId);

        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $skill = trim((string) ($filters['skill'] ?? ''));

        $people = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if ($search !== '' && !str_contains(mb_strtolower((string) $row['last_name']), $search)) {
                continue;
            }

            $mine = $skills[$id] ?? ['certified' => [], 'preferred' => [], 'equipment' => []];

            // The chip row narrows the list because the officer tapped it.
            // Nothing here blocks anything (7.4).
            if ($skill !== '' && !isset($mine['certified'][$skill]) && !isset($mine['preferred'][$skill])) {
                continue;
            }

            $people[] = $row + [
                'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                'list_name' => (string) $row['last_name'] . ', ' . (string) $row['first_name'],
                'certified' => array_values($mine['certified']),
                'preferred' => array_values($mine['preferred']),
                'equipment' => array_values($mine['equipment']),
                'lunch' => (string) ($row['lunch_state'] ?? 'not_yet'),
                'overlap' => $overlaps[$id] ?? null,
            ];
        }

        return $people;
    }

    /**
     * Certified and preferred skills for a team, keyed by user.
     *
     * Joined through team_member rather than an IN list built from the caller's
     * ids: nothing in this application assembles SQL from a variable number of
     * placeholders, and the team is already the boundary being read.
     *
     * @return array<int, array{certified: array<string, array<string, mixed>>, preferred: array<string, array<string, mixed>>, equipment: array<string, array<string, mixed>>}>
     */
    public function skillsByUser(int $teamId, int $seasonId): array
    {
        $rows = $this->db->all(
            'SELECT us.user_id, s.code, s.label, s.kind,
                    us.granted_at, us.is_preferred
               FROM team_member tm
               JOIN user_skill us ON us.user_id = tm.user_id
               JOIN skill s ON s.id = us.skill_id
              WHERE tm.team_id = :team_id AND tm.season_id = :season_id
              ORDER BY s.sort_order',
            ['team_id' => $teamId, 'season_id' => $seasonId]
        );

        $byUser = [];

        foreach ($rows as $row) {
            $id = (int) $row['user_id'];
            $code = (string) $row['code'];
            $byUser[$id] ??= ['certified' => [], 'preferred' => [], 'equipment' => []];

            $entry = ['code' => $code, 'label' => (string) $row['label']];

            // Certified is granted_at being set; preferred is its own flag.
            // Two independent facts about the same pair (7.3).
            if ($row['granted_at'] !== null) {
                if ((string) $row['kind'] === 'equipment') {
                    $byUser[$id]['equipment'][$code] = $entry;
                } else {
                    $byUser[$id]['certified'][$code] = $entry;
                }
            }

            if ((int) $row['is_preferred'] === 1 && (string) $row['kind'] === 'position') {
                $byUser[$id]['preferred'][$code] = $entry;
            }
        }

        return $byUser;
    }

    /**
     * Who is standing on another team's board at the same time (spec 5.5).
     *
     * The whole point of this query is that it crosses team scope: neither
     * officer can see the other's board, so the server is the only party that
     * knows. It answers with the other team's name and when that shift ends —
     * what 6.9.4 rule 7 says to show — and deliberately not which position he
     * is on, which is the other officer's business.
     *
     * Strict inequality on both sides, so back-to-back shifts that touch at
     * the edges are a handover and warn about nothing (5.5).
     *
     * @return array<int, array<string, mixed>> keyed by user id
     */
    public function overlaps(int $shiftId, int $teamId, int $seasonId): array
    {
        $rows = $this->db->all(
            'SELECT a.user_id, other.ends_at, t.name AS team_name
               FROM assignment a
               JOIN shift other ON other.id = a.shift_id
               JOIN team t ON t.id = other.team_id
               JOIN team_member tm
                 ON tm.user_id = a.user_id AND tm.team_id = :team_id AND tm.season_id = :season_id
               JOIN shift mine ON mine.id = :this_shift
              WHERE a.is_current = 1
                AND a.shift_id <> :other_shift
                AND other.starts_at < mine.ends_at
                AND other.ends_at > mine.starts_at
              ORDER BY other.ends_at',
            [
                'team_id' => $teamId,
                'season_id' => $seasonId,
                'this_shift' => $shiftId,
                'other_shift' => $shiftId,
            ]
        );

        $byUser = [];
        foreach ($rows as $row) {
            // The earliest-ending clash is the one worth naming.
            $byUser[(int) $row['user_id']] ??= [
                'team_name' => (string) $row['team_name'],
                'ends_at' => (string) $row['ends_at'],
            ];
        }

        return $byUser;
    }

    /** The position skills that appear on the chip row (spec 7.1). */
    public function chipSkills(): array
    {
        return $this->db->all(
            "SELECT code, label FROM skill WHERE kind = 'position' ORDER BY sort_order"
        );
    }

    /** One position, as the position-first sheet needs it. */
    public function position(int $shiftId, string $phase, int $positionId): ?array
    {
        foreach ($this->groups($shiftId, $phase) as $group) {
            foreach ($group['positions'] as $position) {
                if ($position['id'] === $positionId) {
                    return $position;
                }
            }
        }

        return null;
    }
}
