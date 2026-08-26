<?php

declare(strict_types=1);

namespace Resm\Officer;

use Resm\Database;

/**
 * The team, as the officer screens need it (spec 6.9.3, 6.9.8, 6.9.9, 6.9.11).
 *
 * One read behind five screens. View Roster, View Checked In, View Absent,
 * Lunch Management and Reset PINs are the same list of people filtered five
 * ways, and building it once means the five cannot disagree about who is on
 * the tarmac.
 *
 * Every row carries the E.164 phone as well as the typed one. A tap-to-call
 * link that dials the wrong person is worse than no link, and chasing down who
 * has not shown up is the practical use of half these screens.
 */
final class TeamRoster
{
    public function __construct(
        private Database $db,
        private Board $board,
    ) {
    }

    /**
     * Everyone on the team, sorted Last Name, First Name (spec 6.9.3).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forShift(int $shiftId, int $teamId, int $seasonId, string $search = ''): array
    {
        $rows = $this->db->all(
            "SELECT u.id, u.member_id, u.last_name, u.first_name, u.phone, u.phone_e164,
                    u.is_walkon, u.pin_changed_at,
                    (SELECT ce.type FROM check_event ce
                      WHERE ce.shift_id = :check_shift AND ce.user_id = u.id
                      ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) AS check_state,
                    (SELECT ce.occurred_at FROM check_event ce
                      WHERE ce.shift_id = :time_shift AND ce.user_id = u.id
                      ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) AS checked_at,
                    (SELECT le.state FROM lunch_event le
                      WHERE le.shift_id = :lunch_shift AND le.user_id = u.id
                      ORDER BY le.occurred_at DESC, le.id DESC LIMIT 1) AS lunch_state
               FROM team_member tm
               JOIN `user` u ON u.id = tm.user_id
              WHERE tm.team_id = :team_id
                AND tm.season_id = :season_id
                AND u.is_active = 1
                AND u.role = 'committeeman'
              ORDER BY u.last_name, u.first_name",
            [
                'check_shift' => $shiftId,
                'time_shift' => $shiftId,
                'lunch_shift' => $shiftId,
                'team_id' => $teamId,
                'season_id' => $seasonId,
            ]
        );

        $assignments = $this->assignmentsByUser($shiftId);
        $people = $this->board->decorate($rows, $shiftId, $teamId, $seasonId, ['search' => $search]);

        foreach ($people as $i => $person) {
            $id = (int) $person['id'];
            $state = (string) ($person['check_state'] ?? '');

            $people[$i]['checked_in'] = $state === 'in';
            // Spec 6.9.8: Absent is no check event at all. Somebody who came
            // and went is neither on the tarmac nor someone to ring.
            $people[$i]['absent'] = $state === '';
            $people[$i]['has_left'] = $state === 'out';
            $people[$i]['assignments'] = $assignments[$id] ?? [];
        }

        return $people;
    }

    /**
     * Current positions on this shift, keyed by user then phase.
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function assignmentsByUser(int $shiftId): array
    {
        $rows = $this->db->all(
            'SELECT a.user_id, a.phase, a.is_inherited, p.label, pp.is_critical,
                    g.label AS group_label
               FROM assignment a
               JOIN position p ON p.id = a.position_id
               JOIN position_group g ON g.id = p.group_id
               JOIN position_phase pp ON pp.position_id = p.id AND pp.phase = a.phase
              WHERE a.shift_id = :shift_id AND a.is_current = 1',
            ['shift_id' => $shiftId]
        );

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['user_id']][(string) $row['phase']] = [
                'label' => (string) $row['label'],
                'group_label' => (string) $row['group_label'],
                'is_critical' => (int) $row['is_critical'] === 1,
                'is_inherited' => (int) $row['is_inherited'] === 1,
            ];
        }

        return $byUser;
    }

    /**
     * How the team splits across the three lunch states (spec 6.9.9).
     *
     * @param array<int, array<string, mixed>> $people from forShift()
     * @return array{not_yet: int, at_lunch: int, done: int}
     */
    public static function lunchCounts(array $people): array
    {
        $counts = ['not_yet' => 0, 'at_lunch' => 0, 'done' => 0];

        foreach ($people as $person) {
            $state = (string) ($person['lunch'] ?? 'not_yet');
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        return $counts;
    }
}
