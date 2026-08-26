<?php

declare(strict_types=1);

namespace Resm\Officer;

use DateTimeImmutable;
use DateTimeZone;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * Broadcast Message (spec 6.9.10).
 *
 * A short line pinned to the status widget of every committeeman on the shift:
 * "bump and run in 15 minutes", "Reed lane closed, use Employee", "buses
 * running 20 minutes behind". New in v2 — v1 stated communication as an
 * objective and provided no mechanism for it.
 *
 * Retiring is a timestamp rather than a delete. What an officer told the team
 * at 19:40 is part of the record of the evening, and a message that turned out
 * to be wrong is exactly the one somebody will want to look up.
 */
final class Broadcasts
{
    /** Matches broadcast.body, which is VARCHAR(280). */
    public const MAX_LENGTH = 280;

    public function __construct(
        private Database $db,
        private AuditLog $audit,
    ) {
    }

    /**
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function send(
        Identity $actor,
        int $shiftId,
        string $body,
        string $expiresInMinutes = '',
        ?DateTimeImmutable $now = null,
    ): array {
        $body = trim($body);

        if ($body === '') {
            return self::fail('A broadcast needs something to say.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            return self::fail('That is too long (' . self::MAX_LENGTH . ' characters at most).');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = null;

        if ($expiresInMinutes !== '') {
            if (!ctype_digit($expiresInMinutes) || (int) $expiresInMinutes < 1) {
                return self::fail('An expiry has to be a number of minutes.');
            }
            $expiresAt = $now->modify('+' . (int) $expiresInMinutes . ' minutes')->format('Y-m-d H:i:s');
        }

        $id = null;

        $this->db->transaction(function (Database $db) use ($shiftId, $body, $actor, $now, $expiresAt, &$id): void {
            // One live message at a time. A widget stacking three of them says
            // nothing at arm's length, and the newest is the one that counts.
            $db->execute(
                'UPDATE broadcast SET retired_at = :at WHERE shift_id = :shift_id AND retired_at IS NULL',
                ['at' => $now->format('Y-m-d H:i:s'), 'shift_id' => $shiftId]
            );

            $db->execute(
                'INSERT INTO broadcast (shift_id, body, created_by, created_at, expires_at)
                 VALUES (:shift_id, :body, :created_by, :created_at, :expires_at)',
                [
                    'shift_id' => $shiftId,
                    'body' => $body,
                    'created_by' => $actor->id,
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'expires_at' => $expiresAt,
                ]
            );
            $id = $db->lastInsertId();

            // Every widget on the shift is about to say something new.
            $db->execute(
                'UPDATE state_version SET version = version + 1 WHERE shift_id = :shift_id',
                ['shift_id' => $shiftId]
            );
        });

        $this->audit->record($actor->id, 'broadcast_send', 'broadcast', $id, null, [
            'shift_id' => $shiftId, 'expires_at' => $expiresAt,
        ], $shiftId);

        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /** Take the pinned message down (spec 6.9.10). */
    public function retire(Identity $actor, int $shiftId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $retired = 0;

        $this->db->transaction(function (Database $db) use ($shiftId, $now, &$retired): void {
            $retired = $db->execute(
                'UPDATE broadcast SET retired_at = :at WHERE shift_id = :shift_id AND retired_at IS NULL',
                ['at' => $now->format('Y-m-d H:i:s'), 'shift_id' => $shiftId]
            );

            if ($retired > 0) {
                $db->execute(
                    'UPDATE state_version SET version = version + 1 WHERE shift_id = :shift_id',
                    ['shift_id' => $shiftId]
                );
            }
        });

        if ($retired > 0) {
            $this->audit->record($actor->id, 'broadcast_retire', 'shift', $shiftId, null, null, $shiftId);
        }

        return ['ok' => true, 'error' => null, 'id' => null];
    }

    /** Every message sent on this shift, newest first. */
    public function history(int $shiftId): array
    {
        return $this->db->all(
            'SELECT b.id, b.body, b.created_at, b.expires_at, b.retired_at,
                    u.first_name, u.last_name
               FROM broadcast b
               LEFT JOIN `user` u ON u.id = b.created_by
              WHERE b.shift_id = :shift_id
              ORDER BY b.created_at DESC, b.id DESC',
            ['shift_id' => $shiftId]
        );
    }

    /** @return array{ok: false, error: string, id: null} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'id' => null];
    }
}
