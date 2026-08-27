<?php

declare(strict_types=1);

namespace Resm\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Resm\Csv;
use Resm\Database;

/**
 * Export Roster (spec 6.10.4).
 *
 * One shift at a time, CSV out. The column set opens with what the spec names
 * — names, Member ID, shift day, check timestamps, last assigned position in
 * each phase, certified skills — and then carries Phone, Email and Team so the
 * file round-trips: Import Roster matches headers by alias and ignores what it
 * does not recognise, so this export can be handed straight back to it.
 * Preferred skills ride along too (spec 7.3): they are the training list
 * nobody had to compile, and the export is where it becomes printable.
 *
 * This is the one screen that hands personal data out in bulk (spec 10.5).
 * The route guards it with ImportExportRoster, which Access grants to Admins
 * only; this class assumes that has already happened.
 *
 * Every free-text cell goes through Csv::guard — a roster row is user-entered
 * text, and the file is opened in Excel by exactly the person whose machine
 * an injected formula would run on.
 */
final class RosterExport
{
    public const COLUMNS = [
        'Lastname', 'Firstname', 'Member ID', 'Shift Day',
        'Check In Timestamp', 'Check Out Timestamp',
        'Last Assigned Position (Unload)', 'Last Assigned Position (Bump and Run)',
        'Assigned Skills', 'Preferred Skills',
        'Phone', 'Email', 'Team',
    ];

    public function __construct(
        private Database $db,
        private DateTimeZone $displayTz,
        private int $retentionYears,
    ) {
    }

    /**
     * The shifts the selector offers: the active season's, oldest first, with
     * enough context to tell two Saturdays apart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function shifts(): array
    {
        return $this->db->all(
            'SELECT sh.id, sh.starts_at, sh.ends_at, sh.shift_type, t.name AS team_name
               FROM shift sh
               JOIN team t ON t.id = sh.team_id
               JOIN season se ON se.id = sh.season_id
              WHERE se.is_active = 1
              ORDER BY sh.starts_at, t.name'
        );
    }

    /**
     * The shift a CSV is being asked for, or null when it does not exist or
     * has aged past the retention window (spec 11.5 #7) — five years is the
     * span the export ranges over, and the bound is on the query, never a
     * delete.
     *
     * @return array<string, mixed>|null
     */
    public function shift(int $shiftId): ?array
    {
        return $this->db->one(
            'SELECT sh.id, sh.season_id, sh.team_id, sh.starts_at, sh.ends_at,
                    sh.shift_type, t.name AS team_name, se.name AS season_name
               FROM shift sh
               JOIN team t ON t.id = sh.team_id
               JOIN season se ON se.id = sh.season_id
              WHERE sh.id = :id
                AND sh.starts_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :years YEAR)',
            ['id' => $shiftId, 'years' => $this->retentionYears]
        );
    }

    /**
     * One row per person the shift touched: the team's roster for that season,
     * plus anyone with a check event or an assignment on the shift who is not
     * on it — a man working a double out of another team (spec 5.5) belongs in
     * the record of the shift he actually worked.
     *
     * @param array<string, mixed> $shift
     * @return array<int, array<string, mixed>>
     */
    public function rows(array $shift): array
    {
        $shiftId = (int) $shift['id'];
        $params = [
            'team' => (int) $shift['team_id'],
            'season' => (int) $shift['season_id'],
            'shift_check' => $shiftId,
            'shift_assign' => $shiftId,
        ];

        $people = $this->db->all(
            'SELECT DISTINCT u.id, u.last_name, u.first_name, u.member_id,
                    u.phone, u.email
               FROM `user` u
               LEFT JOIN team_member tm
                 ON tm.user_id = u.id AND tm.team_id = :team AND tm.season_id = :season
              WHERE tm.user_id IS NOT NULL
                 OR u.id IN (SELECT user_id FROM check_event WHERE shift_id = :shift_check)
                 OR u.id IN (SELECT user_id FROM assignment WHERE shift_id = :shift_assign)
              ORDER BY u.last_name, u.first_name, u.id',
            $params
        );

        if ($people === []) {
            return [];
        }

        $checks = $this->checkTimes($shiftId);
        $positions = $this->lastPositions($shiftId);
        $skills = $this->skills(array_map(static fn (array $p): int => (int) $p['id'], $people));

        $rows = [];
        foreach ($people as $person) {
            $id = (int) $person['id'];
            $rows[] = [
                'user_id' => $id,
                'last_name' => (string) $person['last_name'],
                'first_name' => (string) $person['first_name'],
                'member_id' => (string) ($person['member_id'] ?? ''),
                'check_in' => $checks[$id]['in'] ?? null,
                'check_out' => $checks[$id]['out'] ?? null,
                'position_unload' => $positions[$id]['unload'] ?? '',
                'position_bump_run' => $positions[$id]['bump_run'] ?? '',
                'certified' => $skills[$id]['certified'] ?? '',
                'preferred' => $skills[$id]['preferred'] ?? '',
                'phone' => (string) ($person['phone'] ?? ''),
                'email' => (string) ($person['email'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * The file itself. Timestamps and the shift day are in the display
     * timezone — the export is read by people, and the people are in Texas.
     *
     * @param array<string, mixed> $shift
     * @param array<int, array<string, mixed>> $rows
     */
    public function csv(array $shift, array $rows): string
    {
        $day = $this->localDay($shift);
        $team = (string) $shift['team_name'];

        $out = Csv::line(self::COLUMNS);

        foreach ($rows as $row) {
            $out .= Csv::line([
                Csv::guard($row['last_name']),
                Csv::guard($row['first_name']),
                Csv::guard($row['member_id']),
                $day,
                $this->stamp($row['check_in']),
                $this->stamp($row['check_out']),
                Csv::guard($row['position_unload']),
                Csv::guard($row['position_bump_run']),
                Csv::guard($row['certified']),
                Csv::guard($row['preferred']),
                Csv::guard($row['phone']),
                Csv::guard($row['email']),
                Csv::guard($team),
            ]);
        }

        return $out;
    }

    /** roster-2027-03-06-team-a.csv — sortable, and safe as a header value. */
    public function filename(array $shift): string
    {
        $team = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $shift['team_name']));

        return sprintf('roster-%s-%s.csv', $this->localDay($shift), trim($team, '-'));
    }

    // -----------------------------------------------------------------------

    /**
     * First check-in and last check-out per person. First in and last out on
     * purpose: a mis-tap corrected a second later leaves both events on the
     * record (the audit stays honest), but the export answers "when did he
     * arrive and when did he leave", which is the span.
     *
     * @return array<int, array{in: ?string, out: ?string}>
     */
    private function checkTimes(int $shiftId): array
    {
        $events = $this->db->all(
            'SELECT user_id,
                    MIN(CASE WHEN type = \'in\' THEN occurred_at END) AS first_in,
                    MAX(CASE WHEN type = \'out\' THEN occurred_at END) AS last_out
               FROM check_event
              WHERE shift_id = :shift
              GROUP BY user_id',
            ['shift' => $shiftId]
        );

        $times = [];
        foreach ($events as $event) {
            $times[(int) $event['user_id']] = [
                'in' => $event['first_in'] === null ? null : (string) $event['first_in'],
                'out' => $event['last_out'] === null ? null : (string) $event['last_out'],
            ];
        }

        return $times;
    }

    /**
     * The most recent assignment per person per phase, current or not: a man
     * moved off Curve 2 was last assigned wherever he was moved TO, and a man
     * vacated without a replacement was still last assigned there.
     *
     * @return array<int, array<string, string>>
     */
    private function lastPositions(int $shiftId): array
    {
        $rows = $this->db->all(
            'SELECT a.user_id, a.phase, p.label
               FROM assignment a
               JOIN position p ON p.id = a.position_id
               JOIN (SELECT user_id, phase, MAX(id) AS last_id
                       FROM assignment
                      WHERE shift_id = :latest_shift
                      GROUP BY user_id, phase) latest
                 ON latest.last_id = a.id
              WHERE a.shift_id = :shift',
            ['latest_shift' => $shiftId, 'shift' => $shiftId]
        );

        $positions = [];
        foreach ($rows as $row) {
            $positions[(int) $row['user_id']][(string) $row['phase']] = (string) $row['label'];
        }

        return $positions;
    }

    /**
     * Certified and preferred skills per person, each as one cell of
     * semicolon-joined labels in chip order. Equipment certifications are in
     * here by construction — the export is one of the two places they surface
     * (spec 7.1).
     *
     * @param array<int, int> $userIds
     * @return array<int, array{certified: string, preferred: string}>
     */
    private function skills(array $userIds): array
    {
        $marks = str_repeat('?,', count($userIds) - 1) . '?';
        $rows = $this->db->all(
            "SELECT us.user_id, s.label,
                    us.granted_at IS NOT NULL AS certified,
                    us.is_preferred
               FROM user_skill us
               JOIN skill s ON s.id = us.skill_id
              WHERE us.user_id IN ({$marks})
              ORDER BY us.user_id, s.sort_order",
            array_values($userIds)
        );

        $skills = [];
        foreach ($rows as $row) {
            $id = (int) $row['user_id'];
            $skills[$id] ??= ['certified' => [], 'preferred' => []];
            if ((int) $row['certified'] === 1) {
                $skills[$id]['certified'][] = (string) $row['label'];
            }
            if ((int) $row['is_preferred'] === 1) {
                $skills[$id]['preferred'][] = (string) $row['label'];
            }
        }

        return array_map(
            static fn (array $s): array => [
                'certified' => implode('; ', $s['certified']),
                'preferred' => implode('; ', $s['preferred']),
            ],
            $skills
        );
    }

    /** A stored UTC datetime, rendered local; empty when there is none. */
    private function stamp(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone($this->displayTz)
            ->format('Y-m-d H:i');
    }

    /** @param array<string, mixed> $shift */
    private function localDay(array $shift): string
    {
        return (new DateTimeImmutable((string) $shift['starts_at'], new DateTimeZone('UTC')))
            ->setTimezone($this->displayTz)
            ->format('Y-m-d');
    }
}
