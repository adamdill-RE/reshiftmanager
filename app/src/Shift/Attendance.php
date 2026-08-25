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
     * @param array<string, mixed> $shift a candidate row from CurrentShift
     * @return array{ok: bool, error: ?string, at: ?DateTimeImmutable, vacated: int}
     */
    public function record(
        Identity $actor,
        array $shift,
        int $subjectId,
        string $type,
        ?DateTimeImmutable $now = null,
    ): array {
        if ($type !== 'in' && $type !== 'out') {
            return self::fail('That is not a check in or a check out.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $shiftId = (int) $shift['id'];
        $source = $subjectId === $actor->id ? 'self' : 'officer';

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
            ];
        }

        $vacated = 0;

        $this->db->transaction(function (Database $db) use (
            $shiftId, $subjectId, $type, $actor, $source, $now, &$vacated
        ): void {
            $db->execute(
                'INSERT INTO check_event (shift_id, user_id, type, occurred_at, recorded_at, recorded_by, source)
                 VALUES (:shift_id, :user_id, :type, :occurred_at, :recorded_at, :recorded_by, :source)',
                [
                    'shift_id' => $shiftId,
                    'user_id' => $subjectId,
                    'type' => $type,
                    'occurred_at' => $now->format('Y-m-d H:i:s'),
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
            ['shift_id' => $shiftId, 'source' => $source, 'vacated' => $vacated],
            $shiftId
        );

        return ['ok' => true, 'error' => null, 'at' => $now, 'vacated' => $vacated];
    }

    /**
     * This user's current assignment on a shift, per phase.
     *
     * @return array<string, array<string, mixed>> keyed by phase
     */
    public function assignments(int $shiftId, int $userId): array
    {
        $rows = $this->db->all(
            'SELECT a.phase, a.is_inherited, p.label AS position, p.definition, g.label AS group_label,
                    pp.is_critical
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

    /** @return array{ok: false, error: string, at: null, vacated: 0} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'at' => null, 'vacated' => 0];
    }
}
