<?php

declare(strict_types=1);

namespace Resm\Admin;

use DateTimeImmutable;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;
use Resm\ShiftClock;
use Resm\ShiftType;

/**
 * Creating shifts (spec 6.10.5).
 *
 * A shift belongs to a team and carries a type, a start, an end, and the
 * position groups actually staffed on it. Shift patterns repeat across the
 * season, so a date range creates a month of them in one pass — which is also
 * why every guard here matters: a mistyped range is thirty wrong shifts, not
 * one.
 *
 * All ten groups are active by default (spec 5.4). Shift type decides almost
 * nothing about which groups run: the phase matrix already filters positions
 * per phase, and Rodeo Express confirmed the four route stops always open. The
 * per-shift set exists for weather and closures, which an officer decides on
 * the night.
 */
final class Shifts
{
    /**
     * A season is about five weeks. This is not a business rule, it is a
     * fat-finger stop: 2027 typed into a year field should not create three
     * hundred shifts before anyone notices.
     */
    public const MAX_RANGE = 120;

    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private ShiftClock $clock,
    ) {
    }

    /**
     * @param array<int, mixed> $groupIds
     * @return array{ok: bool, error: ?string, id: ?int, notice: ?string}
     */
    public function create(
        Identity $actor,
        int $seasonId,
        int $teamId,
        string $typeValue,
        string $date,
        string $startTime,
        string $endTime,
        array $groupIds,
    ): array {
        $type = ShiftType::tryFrom($typeValue);
        if ($type === null) {
            return self::fail('That is not a shift type.');
        }

        if (!$this->teamInSeason($teamId, $seasonId)) {
            return self::fail('That team is not part of the active season.');
        }

        $when = $this->clock->resolve($date, $startTime, $endTime);
        if (!$when['ok']) {
            return self::fail((string) $when['error']);
        }

        $groups = $this->validGroupIds($groupIds);
        if ($groups === []) {
            return self::fail('A shift needs at least one active position group.');
        }

        $clashes = $this->overlapping($teamId, $when['start'], $when['end']);
        $id = $this->insert($actor, $seasonId, $teamId, $type, $when['start'], $when['end'], $groups);

        return [
            'ok' => true,
            'error' => null,
            'id' => $id,
            'notice' => $this->noticeFor($when['adjusted'], $clashes),
        ];
    }

    /**
     * Create the same shift on every wanted weekday across a date range.
     *
     * Nothing is written until every date resolves, so a range that would fail
     * halfway leaves no half-built week behind.
     *
     * @param array<int, mixed> $weekdays ISO 1 (Monday) to 7 (Sunday)
     * @param array<int, mixed> $groupIds
     * @return array{ok: bool, error: ?string, created: int, skipped: int, notice: ?string}
     */
    public function createRange(
        Identity $actor,
        int $seasonId,
        int $teamId,
        string $typeValue,
        string $fromDate,
        string $toDate,
        array $weekdays,
        string $startTime,
        string $endTime,
        array $groupIds,
    ): array {
        $type = ShiftType::tryFrom($typeValue);
        if ($type === null) {
            return self::rangeFail('That is not a shift type.');
        }
        if (!$this->teamInSeason($teamId, $seasonId)) {
            return self::rangeFail('That team is not part of the active season.');
        }

        $groups = $this->validGroupIds($groupIds);
        if ($groups === []) {
            return self::rangeFail('A shift needs at least one active position group.');
        }

        $from = ShiftClock::parseDate($fromDate);
        $to = ShiftClock::parseDate($toDate);
        if ($from === null || $to === null) {
            return self::rangeFail('Those dates are not real ones.');
        }
        if ($to < $from) {
            return self::rangeFail('The range ends before it starts.');
        }
        if ((int) $from->diff($to)->days >= self::MAX_RANGE) {
            return self::rangeFail(sprintf('That range is longer than %d days.', self::MAX_RANGE));
        }

        $dates = $this->clock->datesInRange($fromDate, $toDate, array_map('intval', $weekdays), self::MAX_RANGE);
        if ($dates === []) {
            return self::rangeFail('No dates in that range fall on the days you picked.');
        }

        // Resolve every date before writing any of them.
        $resolved = [];
        $adjusted = null;
        foreach ($dates as $date) {
            $when = $this->clock->resolve($date, $startTime, $endTime);
            if (!$when['ok']) {
                return self::rangeFail("{$date}: {$when['error']}");
            }
            $adjusted ??= $when['adjusted'];
            $resolved[] = $when;
        }

        $created = 0;
        $skipped = 0;
        $clashes = [];

        $this->db->transaction(function () use (
            $resolved, $actor, $seasonId, $teamId, $type, $groups, &$created, &$skipped, &$clashes
        ): void {
            foreach ($resolved as $when) {
                // A shift this team already starts at this instant is the same
                // shift, so re-running a range after adding one date is safe
                // rather than duplicating a month.
                if ($this->startsAt($teamId, $when['start'])) {
                    $skipped++;
                    continue;
                }

                foreach ($this->overlapping($teamId, $when['start'], $when['end']) as $clash) {
                    $clashes[] = $clash;
                }

                $this->insert($actor, $seasonId, $teamId, $type, $when['start'], $when['end'], $groups);
                $created++;
            }
        });

        return [
            'ok' => true,
            'error' => null,
            'created' => $created,
            'skipped' => $skipped,
            'notice' => $this->noticeFor($adjusted, $clashes, $skipped),
        ];
    }

    /**
     * Shifts in a season, newest first, with their active group count and
     * whether anything has happened on them yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forSeason(int $seasonId, ?int $teamId = null): array
    {
        $params = ['season_id' => $seasonId];
        $where = 's.season_id = :season_id';
        if ($teamId !== null) {
            $where .= ' AND s.team_id = :team_id';
            $params['team_id'] = $teamId;
        }

        return $this->db->all(
            "SELECT s.id, s.team_id, s.shift_type, s.starts_at, s.ends_at, s.current_phase,
                    t.name AS team_name,
                    (SELECT COUNT(*) FROM shift_group sg
                      WHERE sg.shift_id = s.id AND sg.is_active = 1) AS group_count,
                    (SELECT COUNT(*) FROM check_event ce WHERE ce.shift_id = s.id) AS check_count,
                    (SELECT COUNT(*) FROM assignment a WHERE a.shift_id = s.id) AS assignment_count,
                    (SELECT GROUP_CONCAT(sg.group_id) FROM shift_group sg
                      WHERE sg.shift_id = s.id AND sg.is_active = 1) AS group_ids
             FROM shift s
             JOIN team t ON t.id = s.team_id
             WHERE {$where}
             ORDER BY s.starts_at DESC, s.id DESC",
            $params
        );
    }

    /**
     * Other shifts this team already has running across the same window.
     *
     * Touching at the edges is not an overlap: a Weekend Day ending at 18:00
     * and a Weekend Night starting at 16:45 do overlap, but one ending exactly
     * when the next begins is a handover.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overlapping(
        int $teamId,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
        ?int $exceptId = null,
    ): array {
        $params = [
            'team_id' => $teamId,
            'start' => $startUtc->format('Y-m-d H:i:s'),
            'end' => $endUtc->format('Y-m-d H:i:s'),
            'except' => $exceptId ?? 0,
        ];

        return $this->db->all(
            'SELECT id, shift_type, starts_at, ends_at
             FROM shift
             WHERE team_id = :team_id AND id <> :except
               AND starts_at < :end AND ends_at > :start
             ORDER BY starts_at',
            $params
        );
    }

    /**
     * @param array<int, mixed> $groupIds
     * @return array{ok: bool, error: ?string, id: ?int, notice: ?string}
     */
    public function setGroups(Identity $actor, int $shiftId, array $groupIds): array
    {
        $shift = $this->db->one('SELECT id FROM shift WHERE id = :id', ['id' => $shiftId]);
        if ($shift === null) {
            return self::fail('That shift no longer exists.');
        }

        $groups = $this->validGroupIds($groupIds);
        if ($groups === []) {
            return self::fail('A shift needs at least one active position group.');
        }

        $before = $this->activeGroupIds($shiftId);
        sort($before);
        $after = $groups;
        sort($after);
        if ($before === $after) {
            return ['ok' => true, 'error' => null, 'id' => $shiftId, 'notice' => null];
        }

        $this->db->transaction(function (Database $db) use ($shiftId, $after): void {
            $db->execute('DELETE FROM shift_group WHERE shift_id = :id', ['id' => $shiftId]);
            $this->writeGroups($db, $shiftId, $after);
        });

        $this->audit->record(
            $actor->id,
            'shift_set_groups',
            'shift',
            $shiftId,
            ['group_ids' => $before],
            ['group_ids' => $after],
            $shiftId
        );

        return ['ok' => true, 'error' => null, 'id' => $shiftId, 'notice' => null];
    }

    /**
     * Remove a shift that was never used.
     *
     * Deleting cascades to check-ins, assignments and broadcasts, so a shift
     * anyone has worked is never removable — the season is a record. An
     * untouched one has to be, because bulk creation makes mistakes thirty at
     * a time.
     *
     * @return array{ok: bool, error: ?string, id: ?int, notice: ?string}
     */
    public function delete(Identity $actor, int $shiftId): array
    {
        $shift = $this->db->one(
            'SELECT id, team_id, shift_type, starts_at, ends_at FROM shift WHERE id = :id',
            ['id' => $shiftId]
        );
        if ($shift === null) {
            return self::fail('That shift no longer exists.');
        }

        $used = (int) $this->db->value(
            'SELECT (SELECT COUNT(*) FROM check_event WHERE shift_id = :check_id)
                  + (SELECT COUNT(*) FROM assignment WHERE shift_id = :assign_id)',
            ['check_id' => $shiftId, 'assign_id' => $shiftId]
        );
        if ($used > 0) {
            return self::fail('People have already checked in or been assigned on that shift, so it stays.');
        }

        $this->db->execute('DELETE FROM shift WHERE id = :id', ['id' => $shiftId]);

        $this->audit->record($actor->id, 'shift_delete', 'shift', $shiftId, [
            'team_id' => (int) $shift['team_id'],
            'shift_type' => (string) $shift['shift_type'],
            'starts_at' => (string) $shift['starts_at'],
            'ends_at' => (string) $shift['ends_at'],
        ], null);

        return ['ok' => true, 'error' => null, 'id' => $shiftId, 'notice' => null];
    }

    /** @return array<int, int> */
    public function activeGroupIds(int $shiftId): array
    {
        $rows = $this->db->all(
            'SELECT group_id FROM shift_group WHERE shift_id = :id AND is_active = 1',
            ['id' => $shiftId]
        );

        return array_map(static fn (array $r): int => (int) $r['group_id'], $rows);
    }

    /** Every position group, for the checkbox list. @return array<int, array<string, mixed>> */
    public function allGroups(): array
    {
        return $this->db->all('SELECT id, code, label FROM position_group ORDER BY sort_order, id');
    }

    /**
     * The group ids a new shift starts with: all of them (spec 5.4).
     *
     * @return array<int, int>
     */
    public function defaultGroupIds(): array
    {
        return array_map(static fn (array $g): int => (int) $g['id'], $this->allGroups());
    }

    /** The ids on a listing row, for ticking checkboxes. @return array<int, int> */
    public static function rowGroupIds(array $row): array
    {
        $raw = $row['group_ids'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return array_map('intval', explode(',', $raw));
    }

    // -----------------------------------------------------------------------

    /** @param array<int, int> $groups */
    private function insert(
        Identity $actor,
        int $seasonId,
        int $teamId,
        ShiftType $type,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $groups,
    ): int {
        $id = 0;

        $this->db->transaction(function (Database $db) use (
            $seasonId, $teamId, $type, $start, $end, $groups, $actor, &$id
        ): void {
            $db->execute(
                'INSERT INTO shift (season_id, team_id, shift_type, starts_at, ends_at, current_phase, created_by)
                 VALUES (:season_id, :team_id, :shift_type, :starts_at, :ends_at, :current_phase, :created_by)',
                [
                    'season_id' => $seasonId,
                    'team_id' => $teamId,
                    'shift_type' => $type->value,
                    'starts_at' => $start->format('Y-m-d H:i:s'),
                    'ends_at' => $end->format('Y-m-d H:i:s'),
                    'current_phase' => $type->defaultPhase(),
                    'created_by' => $actor->id,
                ]
            );

            $id = $db->lastInsertId();
            $this->writeGroups($db, $id, $groups);

            // The polling endpoint reads this row for every client on the
            // shift, so it exists from the moment the shift does rather than
            // being created by whichever request happens to be first.
            $db->execute(
                'INSERT INTO state_version (shift_id, version) VALUES (:shift_id, 1)',
                ['shift_id' => $id]
            );
        });

        $this->audit->record($actor->id, 'shift_create', 'shift', $id, null, [
            'team_id' => $teamId,
            'shift_type' => $type->value,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            'group_ids' => $groups,
        ], $id);

        return $id;
    }

    /** @param array<int, int> $groups */
    private function writeGroups(Database $db, int $shiftId, array $groups): void
    {
        foreach ($groups as $groupId) {
            $db->execute(
                'INSERT INTO shift_group (shift_id, group_id, is_active) VALUES (:shift_id, :group_id, 1)',
                ['shift_id' => $shiftId, 'group_id' => $groupId]
            );
        }
    }

    private function teamInSeason(int $teamId, int $seasonId): bool
    {
        return $this->db->one(
            'SELECT id FROM team WHERE id = :id AND season_id = :season_id AND is_active = 1',
            ['id' => $teamId, 'season_id' => $seasonId]
        ) !== null;
    }

    private function startsAt(int $teamId, DateTimeImmutable $start): bool
    {
        return $this->db->one(
            'SELECT id FROM shift WHERE team_id = :team_id AND starts_at = :starts_at',
            ['team_id' => $teamId, 'starts_at' => $start->format('Y-m-d H:i:s')]
        ) !== null;
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<int, int>
     */
    private function validGroupIds(array $candidates): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $candidates))));
        if ($wanted === []) {
            return [];
        }

        $real = array_map(static fn (array $g): int => (int) $g['id'], $this->allGroups());

        return array_values(array_intersect($wanted, $real));
    }

    /** @param array<int, array<string, mixed>> $clashes */
    private function noticeFor(?string $adjusted, array $clashes, int $skipped = 0): ?string
    {
        $parts = [];

        if ($adjusted !== null) {
            $parts[] = sprintf(
                'The clocks go forward that night, so %s — the shift ends at the right moment, '
                . 'the board just reads the later time.',
                $adjusted
            );
        }

        if ($clashes !== []) {
            $parts[] = sprintf(
                'This team already has %d shift%s running across the same hours.',
                count($clashes),
                count($clashes) === 1 ? '' : 's'
            );
        }

        if ($skipped > 0) {
            $parts[] = sprintf(
                '%d date%s already had a shift starting at that time and %s left alone.',
                $skipped,
                $skipped === 1 ? '' : 's',
                $skipped === 1 ? 'was' : 'were'
            );
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** @return array{ok: false, error: string, id: null, notice: null} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'id' => null, 'notice' => null];
    }

    /** @return array{ok: false, error: string, created: 0, skipped: 0, notice: null} */
    private static function rangeFail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'created' => 0, 'skipped' => 0, 'notice' => null];
    }
}
