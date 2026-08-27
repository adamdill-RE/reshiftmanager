<?php

declare(strict_types=1);

namespace Resm\Shift;

use DateTimeImmutable;
use DateTimeZone;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * Checking in and out (spec 6.4).
 *
 * Honour system: no QR code, no geofence. A committeeman standing on the
 * tarmac in gloves needs one tap, and a system that argues with him about
 * where he is standing is a system he works around.
 *
 * check_event is append-only. A mis-tap corrected a second later leaves both
 * rows and the newer one is the truth, which is what keeps the record honest
 * about what actually happened rather than only about how it ended up.
 */
final class Attendance
{
    public function __construct(
        private Database $db,
        private AuditLog $audit,
    ) {
    }

    /**
     * Record a check in or out.
     *
     * Takes an actor and a subject separately because an officer checking a
     * committeeman in from the roster screen (spec 6.9, phase 4) is the same
     * event recorded by someone else — the schema has carried recorded_by and
     * source for that since migration 001.
     *
     * $occurredAt is what makes an offline replay different from a live tap
     * (spec 10.3). Passing it says "this happened earlier, on a device" —
     * occurred_at becomes the device's clock at the tap, recorded_at stays the
     * moment the server heard about it, and source becomes offline_sync. It is
     * a separate parameter rather than an overridable $source so that no
     * caller can label a live write as a replay, or the reverse.
     *
     * @param array<string, mixed> $shift a candidate row from CurrentShift
     * @return array{ok: bool, error: ?string, at: ?DateTimeImmutable, vacated: int, claimed: ?DateTimeImmutable}
     */
    public function record(
        Identity $actor,
        array $shift,
        int $subjectId,
        string $type,
        ?DateTimeImmutable $now = null,
        ?DateTimeImmutable $occurredAt = null,
    ): array {
        if ($type !== 'in' && $type !== 'out') {
            return self::fail('That is not a check in or a check out.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $shiftId = (int) $shift['id'];
        $source = $occurredAt !== null
            ? 'offline_sync'
            : ($subjectId === $actor->id ? 'self' : 'officer');

        $claimed = $occurredAt;
        $happenedAt = $occurredAt === null ? $now : self::sane($occurredAt, $shift, $now);

        // Tapping the button twice should not write a second identical event.
        // The state is what it says it is, and the timestamp already shown is
        // the one that counts.
        $already = (string) ($shift['check_state'] ?? '') === $type;
        if ($already) {
            return [
                'ok' => true,
                'error' => null,
                'at' => isset($shift['checked_at'])
                    ? new DateTimeImmutable((string) $shift['checked_at'], new DateTimeZone('UTC'))
                    : null,
                'vacated' => 0,
                'claimed' => null,
            ];
        }

        $vacated = 0;

        $this->db->transaction(function (Database $db) use (
            $shiftId, $subjectId, $type, $actor, $source, $now, $happenedAt, &$vacated
        ): void {
            $db->execute(
                'INSERT INTO check_event (shift_id, user_id, type, occurred_at, recorded_at, recorded_by, source)
                 VALUES (:shift_id, :user_id, :type, :occurred_at, :recorded_at, :recorded_by, :source)',
                [
                    'shift_id' => $shiftId,
                    'user_id' => $subjectId,
                    'type' => $type,
                    'occurred_at' => $happenedAt->format('Y-m-d H:i:s'),
                    'recorded_at' => $now->format('Y-m-d H:i:s'),
                    'recorded_by' => $actor->id,
                    'source' => $source,
                ]
            );

            // Spec 6.4: checking out vacates the user's positions in BOTH
            // phases. That is what makes a dual-team handover self-healing —
            // the spot he is leaving falls open on his officer's board at the
            // moment he leaves, red and pinned if it is critical, without
            // anybody having to coordinate it (spec 5.5).
            if ($type === 'out') {
                $vacated = $db->execute(
                    'UPDATE assignment
                        SET is_current = 0, vacated_at = :vacated_at
                      WHERE shift_id = :shift_id AND user_id = :user_id AND is_current = 1',
                    [
                        // The position falls open when the server learns of it,
                        // not when the phone says he left. An officer reading
                        // the board needs to know when the spot became his
                        // problem.
                        'vacated_at' => $now->format('Y-m-d H:i:s'),
                        'shift_id' => $shiftId,
                        'user_id' => $subjectId,
                    ]
                );
            }

            // Every client polling this shift needs to see the board move.
            $db->execute(
                'UPDATE state_version SET version = version + 1 WHERE shift_id = :shift_id',
                ['shift_id' => $shiftId]
            );
        });

        $this->audit->record(
            $actor->id,
            $type === 'in' ? 'check_in' : 'check_out',
            'user',
            $subjectId,
            null,
            [
                'shift_id' => $shiftId,
                'source' => $source,
                'vacated' => $vacated,
                // What the device actually claimed, kept even when it was
                // clamped. The stored event stays inside the shift so reports
                // are not corrupted; the audit trail keeps the claim so a
                // handset four hours out is visible to whoever goes looking.
                'claimed_at' => $claimed?->format('c'),
                'occurred_at' => $happenedAt->format('c'),
            ],
            $shiftId
        );

        return [
            'ok' => true,
            'error' => null,
            'at' => $happenedAt,
            'vacated' => $vacated,
            'claimed' => $claimed,
        ];
    }

    /**
     * Keep a device's claimed time inside the bounds of reality.
     *
     * A queued event carries whatever the handset's clock said, and handsets
     * are wrong — spec's own note on these columns is that the gap between
     * occurred_at and recorded_at is what exposes one. Storing the raw claim
     * would put check-ins in the future, or before the shift existed, and
     * every count of who was on the tarmac would inherit it.
     *
     * So the stored event is clamped to the shift and to now, and the raw
     * claim goes to the audit log. Neither number is lost and neither lies.
     *
     * @param array<string, mixed> $shift
     */
    private static function sane(
        DateTimeImmutable $claimed,
        array $shift,
        DateTimeImmutable $now,
    ): DateTimeImmutable {
        // Nothing happened in the future, whatever the handset believes.
        if ($claimed > $now) {
            return $now;
        }

        if (isset($shift['starts_at'])) {
            $startsAt = new DateTimeImmutable((string) $shift['starts_at'], new DateTimeZone('UTC'));
            if ($claimed < $startsAt) {
                return $startsAt;
            }
        }

        return $claimed;
    }

    /**
     * This user's current assignment on a shift, per phase.
     *
     * @return array<string, array<string, mixed>> keyed by phase
     */
    public function assignments(int $shiftId, int $userId): array
    {
        $rows = $this->db->all(
            'SELECT a.phase, a.is_inherited, p.label AS position, p.definition, p.map_ref,
                    g.id AS group_id, g.label AS group_label, pp.is_critical
             FROM assignment a
             JOIN position p ON p.id = a.position_id
             JOIN position_group g ON g.id = p.group_id
             JOIN position_phase pp ON pp.position_id = p.id AND pp.phase = a.phase
             WHERE a.shift_id = :shift_id AND a.user_id = :user_id AND a.is_current = 1',
            ['shift_id' => $shiftId, 'user_id' => $userId]
        );

        $byPhase = [];
        foreach ($rows as $row) {
            $byPhase[(string) $row['phase']] = $row;
        }

        return $byPhase;
    }

    /**
     * Move someone through the three lunch states (spec 6.9.9).
     *
     * Going to lunch vacates the position, because a spot held by a man who is
     * eating is a spot the board says is covered and is not. Coming back does
     * NOT restore it: the officer places him again deliberately, which is also
     * what stops two people being put on one position by a return nobody saw.
     *
     * $occurredAt marks an offline replay, exactly as it does for a check
     * event (spec 10.3). Migration 006 gave lunch_event the recorded_at and
     * source columns to hold it: a lunch change replayed hours late is where a
     * wrong handset clock does real damage, because a man shown At Lunch is a
     * position the board reports as covered and is not.
     *
     * @param array<string, mixed> $shift the shift row, for clamping a claimed time
     * @return array{ok: bool, error: ?string, at: ?DateTimeImmutable, vacated: int, claimed: ?DateTimeImmutable}
     */
    public function setLunch(
        Identity $actor,
        int $shiftId,
        int $subjectId,
        string $state,
        ?DateTimeImmutable $now = null,
        ?DateTimeImmutable $occurredAt = null,
        array $shift = [],
    ): array {
        if (!in_array($state, ['not_yet', 'at_lunch', 'done'], true)) {
            return self::fail('That is not a lunch state.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $source = $occurredAt !== null
            ? 'offline_sync'
            : ($subjectId === $actor->id ? 'self' : 'officer');

        $claimed = $occurredAt;
        $happenedAt = $occurredAt === null ? $now : self::sane($occurredAt, $shift, $now);
        $vacated = 0;

        $this->db->transaction(function (Database $db) use (
            $shiftId, $subjectId, $state, $actor, $now, $happenedAt, $source, &$vacated
        ): void {
            $db->execute(
                'INSERT INTO lunch_event (shift_id, user_id, state, occurred_at, recorded_at, recorded_by, source)
                 VALUES (:shift_id, :user_id, :state, :occurred_at, :recorded_at, :recorded_by, :source)',
                [
                    'shift_id' => $shiftId,
                    'user_id' => $subjectId,
                    'state' => $state,
                    'occurred_at' => $happenedAt->format('Y-m-d H:i:s'),
                    'recorded_at' => $now->format('Y-m-d H:i:s'),
                    'recorded_by' => $actor->id,
                    'source' => $source,
                ]
            );

            if ($state === 'at_lunch') {
                $vacated = $db->execute(
                    'UPDATE assignment
                        SET is_current = 0, vacated_at = :vacated_at
                      WHERE shift_id = :shift_id AND user_id = :user_id AND is_current = 1',
                    [
                        'vacated_at' => $now->format('Y-m-d H:i:s'),
                        'shift_id' => $shiftId,
                        'user_id' => $subjectId,
                    ]
                );
            }

            $db->execute(
                'UPDATE state_version SET version = version + 1 WHERE shift_id = :shift_id',
                ['shift_id' => $shiftId]
            );
        });

        $this->audit->record(
            $actor->id,
            'lunch_' . $state,
            'user',
            $subjectId,
            null,
            [
                'shift_id' => $shiftId,
                'vacated' => $vacated,
                'source' => $source,
                'claimed_at' => $claimed?->format('c'),
                'occurred_at' => $happenedAt->format('c'),
            ],
            $shiftId
        );

        return [
            'ok' => true,
            'error' => null,
            'at' => $happenedAt,
            'vacated' => $vacated,
            'claimed' => $claimed,
        ];
    }

    /** The broadcast pinned to this shift, if any is live (spec 6.9.10). */
    public function broadcast(int $shiftId, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->db->one(
            'SELECT body, created_at FROM broadcast
              WHERE shift_id = :shift_id
                AND retired_at IS NULL
                AND (expires_at IS NULL OR expires_at > :now)
              ORDER BY created_at DESC LIMIT 1',
            ['shift_id' => $shiftId, 'now' => $now->format('Y-m-d H:i:s')]
        );
    }

    /** @return array{ok: false, error: string, at: null, vacated: 0, claimed: null} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'at' => null, 'vacated' => 0, 'claimed' => null];
    }
}
