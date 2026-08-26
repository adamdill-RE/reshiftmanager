<?php

declare(strict_types=1);

namespace Resm\Officer;

use DateTimeImmutable;
use DateTimeZone;
use Resm\Auth\Access;
use Resm\Auth\Capability;
use Resm\Auth\Identity;
use Resm\Database;
use Resm\Shift\Window;

/**
 * Which board an officer is looking at (spec 6.9).
 *
 * Every officer screen needs the same two answers before it can render
 * anything: which team, and which of that team's shifts. Resolving it in one
 * place is what stops each screen inventing its own rule — and, more to the
 * point, what stops one of them forgetting the team check and rendering
 * another team's board to an officer who asked for it by id.
 *
 * The security boundary is here, not in the caller: a team is only ever
 * returned if Access says this user may act on it, and a shift is only ever
 * returned if it belongs to a team that survived that filter. A shift id in a
 * query string is matched against that list rather than trusted.
 */
final class OfficerShift
{
    /**
     * How far either side of now a team's shifts are worth offering. Wide
     * enough that an officer opening the app on a Tuesday for Saturday's shift
     * finds it, narrow enough that a season's worth of history does not land
     * in a select box.
     */
    private const LOOK_BACK_DAYS = 2;
    private const LOOK_AHEAD_DAYS = 14;

    public function __construct(
        private Database $db,
        private Window $window,
    ) {
    }

    /**
     * Teams this user may run a board for, this season.
     *
     * An Admin gets every active team; an officer gets the ones he is assigned
     * to (spec 6.9). Access::teamsFor returns null for an Admin meaning "every
     * team", which is why the two branches read differently — an empty list
     * and "no restriction" are opposite answers and must not collapse.
     *
     * The capability is passed in rather than assumed. Every team-scoped
     * capability happens to resolve the same way today, so naming one here
     * would work — and would quietly stop working the day the matrix in
     * spec 2.2 stops being uniform.
     *
     * @return array<int, array<string, mixed>>
     */
    public function teams(Identity $user, int $seasonId, Capability $capability): array
    {
        $allowed = Access::teamsFor($user, $capability);

        if ($allowed === []) {
            return [];
        }

        if ($allowed === null) {
            return $this->db->all(
                'SELECT id, name FROM team
                  WHERE season_id = :season_id AND is_active = 1
                  ORDER BY name',
                ['season_id' => $seasonId]
            );
        }

        // An officer's team list comes from the session, so it is filtered
        // against the season here rather than taken as given: a membership
        // from last season is not a permission this season.
        $rows = [];
        foreach ($allowed as $teamId) {
            $row = $this->db->one(
                'SELECT id, name FROM team
                  WHERE id = :id AND season_id = :season_id AND is_active = 1',
                ['id' => $teamId, 'season_id' => $seasonId]
            );
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * A team's shifts an officer could plausibly be working on.
     *
     * Not window-filtered. The 5.3 window governs a committeeman's check-in,
     * not an officer's planning: he sets Saturday's board up on Thursday, and
     * he reviews last night's after it ended.
     *
     * @return array<int, array<string, mixed>>
     */
    public function shifts(int $teamId, int $seasonId, DateTimeImmutable $now): array
    {
        $rows = $this->db->all(
            'SELECT s.id, s.team_id, s.season_id, s.shift_type, s.starts_at, s.ends_at,
                    s.current_phase, s.phase_changed_at, t.name AS team_name
               FROM shift s
               JOIN team t ON t.id = s.team_id
              WHERE s.team_id = :team_id
                AND s.season_id = :season_id
                AND s.starts_at BETWEEN :from AND :to
              ORDER BY s.starts_at',
            [
                'team_id' => $teamId,
                'season_id' => $seasonId,
                'from' => $now->modify('-' . self::LOOK_BACK_DAYS . ' days')->format('Y-m-d H:i:s'),
                'to' => $now->modify('+' . self::LOOK_AHEAD_DAYS . ' days')->format('Y-m-d H:i:s'),
            ]
        );

        $utc = new DateTimeZone('UTC');
        foreach ($rows as $i => $row) {
            $startsAt = new DateTimeImmutable((string) $row['starts_at'], $utc);
            $endsAt = new DateTimeImmutable((string) $row['ends_at'], $utc);

            $rows[$i]['starts_at_utc'] = $startsAt;
            $rows[$i]['ends_at_utc'] = $endsAt;
            $rows[$i]['is_live'] = $startsAt <= $now && $endsAt > $now;
            $rows[$i]['has_ended'] = $endsAt <= $now;
            $rows[$i]['in_window'] = $this->window->contains($startsAt, $now);
        }

        return $rows;
    }

    /**
     * The whole view context for an officer screen.
     *
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     team: array<string, mixed>|null,
     *     shifts: array<int, array<string, mixed>>,
     *     shift: array<string, mixed>|null
     * }
     */
    public function context(
        Identity $user,
        int $seasonId,
        Capability $capability,
        ?int $teamId = null,
        ?int $shiftId = null,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $teams = $this->teams($user, $seasonId, $capability);

        if ($teams === []) {
            return ['teams' => [], 'team' => null, 'shifts' => [], 'shift' => null];
        }

        // A team named in a query string is matched against the permitted list
        // rather than checked separately, so there is no path where an id gets
        // as far as a query before anyone asks whether it was allowed.
        $team = null;
        if ($teamId !== null) {
            foreach ($teams as $candidate) {
                if ((int) $candidate['id'] === $teamId) {
                    $team = $candidate;
                    break;
                }
            }
        }
        $team ??= $teams[0];

        $shifts = $this->shifts((int) $team['id'], $seasonId, $now);

        $shift = null;
        if ($shiftId !== null) {
            foreach ($shifts as $candidate) {
                if ((int) $candidate['id'] === $shiftId) {
                    $shift = $candidate;
                    break;
                }
            }
        }
        $shift ??= self::pickDefault($shifts);

        return ['teams' => $teams, 'team' => $team, 'shifts' => $shifts, 'shift' => $shift];
    }

    /**
     * The shift to open on when none was named.
     *
     * Live now beats everything — that is the board he is standing on. Failing
     * that the next one, because an officer with no shift today is looking
     * ahead (spec 5.3); and failing that the most recent, so a screen opened
     * after the season's last shift still has something to show.
     *
     * @param array<int, array<string, mixed>> $shifts in start order
     * @return array<string, mixed>|null
     */
    private static function pickDefault(array $shifts): ?array
    {
        foreach ($shifts as $shift) {
            if ($shift['is_live'] === true) {
                return $shift;
            }
        }

        foreach ($shifts as $shift) {
            if ($shift['has_ended'] === false) {
                return $shift;
            }
        }

        return $shifts === [] ? null : $shifts[array_key_last($shifts)];
    }
}
