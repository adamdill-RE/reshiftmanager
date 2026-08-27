<?php

declare(strict_types=1);

namespace Resm\Shift;

use DateTimeImmutable;
use DateTimeZone;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * Replaying what a phone recorded while it had no signal (spec 10.3).
 *
 * One queued event per call. The queue is a handful of taps, not a batch job,
 * and one event per request means each one succeeds or fails on its own —
 * there is no half-applied batch to reason about and no ordering to preserve
 * beyond the order the client sends them in.
 *
 * Two decisions here are worth stating plainly, because both look like
 * mistakes until the tarmac case is in mind.
 *
 * The window does not guard a replay. Spec 5.3 closes a shift at 04:00 the day
 * after it starts, and a weekend night shift ends at 02:00 — so a phone that
 * stays in a pocket until breakfast reconnects to a closed window. Guarding a
 * replay with it would silently drop the check-OUT of every man whose handset
 * did not find signal on the way to the car park, and leave him showing on the
 * tarmac. The window governs what he can do NOW; this is a record of what he
 * already did, and what guards it is the roster.
 *
 * A collision is a success. The client deletes a queued item only when the
 * server has confirmed it, so an answer lost on the way back is one it is
 * required to send again. The unique indexes from migration 007 make the
 * second arrival land on the first, and Attendance reports that as done rather
 * than as an error — the alternative is a queue that can never drain. It is
 * handled there rather than here because the same collision happens between
 * two LIVE writes, and one answer to it beats two.
 */
final class Replay
{
    /**
     * How far back a replay may reach.
     *
     * Wide enough for a handset that spent the weekend switched off, narrow
     * enough that a queue nobody has drained since last month cannot rewrite
     * a season's attendance. The events inside it are still only ever this
     * user's own, on shifts he was actually rostered on.
     */
    private const REPLAY_DAYS = 7;

    public function __construct(
        private Database $db,
        private Attendance $attendance,
    ) {
    }

    /**
     * Apply one queued event.
     *
     * @param array<string, mixed> $item kind, shift, type|state, at
     * @return array{ok: bool, error: ?string, status: int, retry: bool, at: ?string, claimed: ?string}
     */
    public function apply(
        Identity $user,
        int $seasonId,
        array $item,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $kind = (string) ($item['kind'] ?? '');
        if ($kind !== 'check' && $kind !== 'lunch') {
            return self::rejected('That is not something this queue carries.');
        }

        $occurredAt = self::parseTime((string) ($item['at'] ?? ''));
        if ($occurredAt === null) {
            // A timestamp this server cannot read will not become readable on
            // the next attempt, so the client is told to stop rather than to
            // retry a thing that cannot work.
            return self::rejected('That timestamp could not be read.');
        }

        $shift = $this->rostered($user->id, $seasonId, (int) ($item['shift'] ?? 0), $now);
        if ($shift === null) {
            return self::rejected('That is not a shift of yours to record against.');
        }

        $result = $kind === 'check'
            ? $this->attendance->record(
                $user,
                $shift,
                $user->id,
                (string) ($item['type'] ?? ''),
                $now,
                $occurredAt,
            )
            : $this->attendance->setLunch(
                $user,
                (int) $shift['id'],
                $user->id,
                (string) ($item['state'] ?? ''),
                $now,
                $occurredAt,
                $shift,
            );

        if (!$result['ok']) {
            return self::rejected((string) $result['error']);
        }

        return [
            'ok' => true,
            'error' => null,
            'status' => 200,
            'retry' => false,
            // Attendance answers with a null time on its no-op path — a
            // replayed check-out for a man already checked out writes nothing
            // and has no row to report. The event still happened when the
            // device said it did, so that is the honest answer to give rather
            // than a success carrying no timestamp at all.
            'at' => ($result['at'] ?? null)?->format('c') ?? $occurredAt->format('c'),
            'claimed' => $occurredAt->format('c'),
        ];
    }

    /**
     * A shift this user is on the roster for, recently enough to be replaying.
     *
     * The same membership join the polling endpoint authorises with, and for
     * the same reason: it is the one that cannot be got past by putting
     * another team's shift id in the request. Deliberately NOT the 5.3 window
     * — see the note at the top of this class.
     *
     * @return array<string, mixed>|null
     */
    private function rostered(int $userId, int $seasonId, int $shiftId, DateTimeImmutable $now): ?array
    {
        if ($shiftId <= 0) {
            return null;
        }

        return $this->db->one(
            'SELECT s.id, s.team_id, s.season_id, s.shift_type, s.starts_at, s.ends_at, s.current_phase,
                    t.name AS team_name,
                    (SELECT ce.type FROM check_event ce
                      WHERE ce.shift_id = s.id AND ce.user_id = :check_user
                      ORDER BY ce.occurred_at DESC, ce.id DESC LIMIT 1) AS check_state
               FROM shift s
               JOIN team t ON t.id = s.team_id
               JOIN team_member tm ON tm.team_id = s.team_id
                                  AND tm.season_id = s.season_id
                                  AND tm.user_id = :member_user
              WHERE s.id = :shift_id
                AND s.season_id = :season_id
                AND s.starts_at >= :earliest',
            [
                'check_user' => $userId,
                'member_user' => $userId,
                'shift_id' => $shiftId,
                'season_id' => $seasonId,
                'earliest' => $now->modify('-' . self::REPLAY_DAYS . ' days')->format('Y-m-d H:i:s'),
            ]
        );
    }

    /** Strict ISO 8601 from the client, normalised to UTC. */
    private static function parseTime(string $raw): ?DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }

        // Anything without an offset would be read in the server's timezone,
        // which is not the one the phone was in. The client sends Z.
        if (!preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $raw)) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Refused, and refusing again would refuse the same way.
     *
     * retry is false on purpose for every case here: a queue that keeps
     * resending something the server will never accept is a queue that never
     * empties, and a "1 pending" badge that never clears is one people learn
     * to ignore — which is worse than the event being lost, because it hides
     * the next one that matters.
     *
     * @return array{ok: false, error: string, status: int, retry: false, at: null, claimed: null}
     */
    private static function rejected(string $message): array
    {
        return [
            'ok' => false, 'error' => $message, 'status' => 422,
            'retry' => false, 'at' => null, 'claimed' => null,
        ];
    }
}
