<?php

declare(strict_types=1);

namespace Resm\Shift;

use DateTimeImmutable;
use DateTimeZone;
use Resm\Database;

/**
 * Which shift a user is on right now (spec 5.5).
 *
 * A committeeman on two teams can work both on one Saturday, and when those
 * shifts overlap there are two true answers to "what is my shift". The rule
 * Rodeo Express settled on, in order:
 *
 *   1. A shift that has already ended is never the current one, checked out
 *      or not. Without this a forgotten check-out pins the widget to a dead
 *      shift for the rest of the night.
 *   2. Otherwise prefer a shift he is checked into and has not left.
 *   3. If that leaves more than one, the earlier start wins AND he is told he
 *      is on two. Being in two places at once is surfaced, not resolved.
 *   4. If it leaves none, the one starting soonest.
 *
 * Every shift inside the 5.3 window is still returned as a candidate, ended
 * or not, because the window governs what he can reach — he has to be able to
 * check out of the shift he just finished — while these rules only decide
 * which one the screens describe by default.
 */
final class CurrentShift
{
    private Window $window;

    public function __construct(
        private Database $db,
        private DateTimeZone $local,
    ) {
        $this->window = new Window($local);
    }

    /**
     * @return array{
     *     current: array<string, mixed>|null,
     *     candidates: array<int, array<string, mixed>>,
     *     doubled: bool
     * }
     */
    public function forUser(int $userId, int $seasonId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $candidates = $this->candidates($userId, $seasonId, $now);

        if ($candidates === []) {
            return ['current' => null, 'candidates' => [], 'doubled' => false];
        }

        // Rule 1. An ended shift stays reachable but is never current.
        $live = array_values(array_filter(
            $candidates,
            static fn (array $s): bool => $s['ends_at_utc'] > $now
        ));

        if ($live === []) {
            return ['current' => null, 'candidates' => $candidates, 'doubled' => false];
        }

        // Rule 2. Where he actually is beats where he is scheduled to be.
        $here = array_values(array_filter($live, static fn (array $s): bool => $s['checked_in']));

        // Rules 3 and 4. Both resolve to the earliest start of whatever is
        // left; the difference is only whether being on two is worth saying.
        $pool = $here !== [] ? $here : $live;
        usort($pool, static fn (array $a, array $b): int => $a['starts_at_utc'] <=> $b['starts_at_utc']);

        return [
            'current' => $pool[0],
            'candidates' => $candidates,
            // Two live shifts he is checked into at once is the state the
            // officer warning exists to prevent, and the widget says so.
            'doubled' => count($here) > 1,
        ];
    }

    /**
     * Every shift this user could reach right now, in start order.
     *
     * One query, not one per shift: the database is on a different host
     * (docs/hosting.md) and this runs on every page load for every user on the
     * tarmac.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidates(int $userId, int $seasonId, DateTimeImmutable $now): array
    {
        [$from, $to] = Window::searchBounds($now);

        $rows = $this->db->all(
            "SELECT s.id, s.team_id, s.shift_type, s.starts_at, s.ends_at, s.current_phase,
                    t.name AS team_name,
                    (SELECT ce.type FROM check_event ce
                      WHERE ce.shift_id = s.id AND ce.user_id = :check_user
                      ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) AS check_state,
                    (SELECT ce.occurred_at FROM check_event ce
                      WHERE ce.shift_id = s.id AND ce.user_id = :time_user
                      ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) AS checked_at,
                    (SELECT le.state FROM lunch_event le
                      WHERE le.shift_id = s.id AND le.user_id = :lunch_user
                      ORDER BY le.occurred_at DESC, le.id DESC LIMIT 1) AS lunch_state
             FROM shift s
             JOIN team t ON t.id = s.team_id
             JOIN team_member tm ON tm.team_id = s.team_id AND tm.season_id = s.season_id
             WHERE tm.user_id = :member_user
               AND s.season_id = :season_id
               AND s.starts_at BETWEEN :from AND :to
             ORDER BY s.starts_at",
            [
                'check_user' => $userId,
                'time_user' => $userId,
                'lunch_user' => $userId,
                'member_user' => $userId,
                'season_id' => $seasonId,
                'from' => $from,
                'to' => $to,
            ]
        );

        $utc = new DateTimeZone('UTC');
        $candidates = [];

        foreach ($rows as $row) {
            $startsAt = new DateTimeImmutable((string) $row['starts_at'], $utc);
            if (!$this->window->contains($startsAt, $now)) {
                continue;
            }

            $row['starts_at_utc'] = $startsAt;
            $row['ends_at_utc'] = new DateTimeImmutable((string) $row['ends_at'], $utc);
            $row['checked_in'] = (string) ($row['check_state'] ?? '') === 'in';
            $row['lunch'] = (string) ($row['lunch_state'] ?? 'not_yet');
            $row['has_ended'] = $row['ends_at_utc'] <= $now;
            $candidates[] = $row;
        }

        return $candidates;
    }

    /**
     * One named shift, when the user picks it from the switcher.
     *
     * Resolved through the candidate list rather than by id alone, so a shift
     * he is not rostered on or that is outside the window cannot be reached by
     * editing a URL.
     *
     * @return array<string, mixed>|null
     */
    public function pick(int $userId, int $seasonId, int $shiftId, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->candidates($userId, $seasonId, $now) as $candidate) {
            if ((int) $candidate['id'] === $shiftId) {
                return $candidate;
            }
        }

        return null;
    }
}
