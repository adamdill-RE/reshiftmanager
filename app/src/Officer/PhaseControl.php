<?php

declare(strict_types=1);

namespace Resm\Officer;

use DateTimeImmutable;
use DateTimeZone;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * The phase toggle (spec 6.9.1, rules in 5.2).
 *
 * The toggle is never hard-locked for any shift type — weather does what it
 * wants and the officer on the ground needs the control. Forward is one tap.
 * Backward asks first, because reversing out of Bump and Run changes what
 * every committeeman on the shift sees the instant it lands.
 *
 * Assignments in each phase persist independently. Nothing here writes to the
 * board except the one case 5.2 names: a Weekend Night shift opens in Bump and
 * Run with an empty Unload board, and falling back to Unload runs the
 * carry-forward rule in reverse to pre-populate it. That fires only when the
 * Unload board is empty — a weeknight officer who deliberately vacated a spot
 * and flipped back would not thank us for putting the man back on it.
 */
final class PhaseControl
{
    public const PHASES = ['unload', 'bump_run'];

    public function __construct(
        private Database $db,
        private AuditLog $audit,
    ) {
    }

    public static function isPhase(string $phase): bool
    {
        return in_array($phase, self::PHASES, true);
    }

    public static function label(string $phase): string
    {
        return $phase === 'bump_run' ? 'Bump and Run' : 'Unload';
    }

    /** Backward is Bump and Run to Unload, and it is the one that asks first. */
    public static function isBackward(string $from, string $to): bool
    {
        return $from === 'bump_run' && $to === 'unload';
    }

    /**
     * @param array<string, mixed> $shift
     * @return array{ok: bool, error: ?string, confirm: bool, phase: ?string, seeded: int}
     */
    public function set(
        Identity $actor,
        array $shift,
        string $phase,
        bool $confirmed = false,
        ?DateTimeImmutable $now = null,
    ): array {
        if (!self::isPhase($phase)) {
            return self::fail('That is not a phase.');
        }

        $shiftId = (int) $shift['id'];
        $from = (string) $shift['current_phase'];

        if ($from === $phase) {
            return ['ok' => true, 'error' => null, 'confirm' => false, 'phase' => $phase, 'seeded' => 0];
        }

        // Spec 5.2: moving backward is deliberate or it does not happen.
        if (self::isBackward($from, $phase) && !$confirmed) {
            return ['ok' => false, 'error' => null, 'confirm' => true, 'phase' => $phase, 'seeded' => 0];
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seeded = 0;

        $this->db->transaction(function (Database $db) use ($shiftId, $phase, $now, $actor, &$seeded): void {
            $db->execute(
                'UPDATE shift SET current_phase = :phase, phase_changed_at = :at WHERE id = :id',
                ['phase' => $phase, 'at' => $now->format('Y-m-d H:i:s'), 'id' => $shiftId]
            );

            if ($phase === 'unload') {
                $seeded = $this->seedUnloadFromBumpRun($db, $shiftId, $actor, $now);
            }

            // Every committeeman's widget is about to say something different
            // (spec 6.9.1), and the Phase 5 polling layer reads this.
            $db->execute(
                'UPDATE state_version SET version = version + 1 WHERE shift_id = :shift_id',
                ['shift_id' => $shiftId]
            );
        });

        $this->audit->record(
            $actor->id,
            'phase_change',
            'shift',
            $shiftId,
            ['phase' => $from],
            ['phase' => $phase, 'seeded' => $seeded],
            $shiftId
        );

        return ['ok' => true, 'error' => null, 'confirm' => false, 'phase' => $phase, 'seeded' => $seeded];
    }

    /**
     * Carry-forward in reverse, for the Weekend Night fallback (spec 5.2).
     *
     * Only when the Unload board is completely empty. One INSERT ... SELECT
     * rather than a read followed by writes: the rows it copies are the ones
     * the database can see at that instant, inside the same transaction as the
     * phase change, so a simultaneous assignment cannot land between the two
     * halves and be missed.
     */
    private function seedUnloadFromBumpRun(
        Database $db,
        int $shiftId,
        Identity $actor,
        DateTimeImmutable $now,
    ): int {
        $existing = (int) $db->value(
            "SELECT COUNT(*) FROM assignment
              WHERE shift_id = :shift_id AND phase = 'unload' AND is_current = 1",
            ['shift_id' => $shiftId]
        );

        if ($existing > 0) {
            return 0;
        }

        return $db->execute(
            "INSERT INTO assignment
                 (shift_id, phase, position_id, user_id, assigned_by, assigned_at,
                  is_current, is_inherited, is_multi, source)
             SELECT a.shift_id, 'unload', a.position_id, a.user_id, :actor, :at,
                    1, 1, unloaded.multi_assign, 'carry_forward'
               FROM assignment a
               JOIN position_phase carried
                 ON carried.position_id = a.position_id AND carried.phase = 'bump_run'
               JOIN position_phase unloaded
                 ON unloaded.position_id = a.position_id AND unloaded.phase = 'unload'
              WHERE a.shift_id = :shift_id
                AND a.phase = 'bump_run'
                AND a.is_current = 1
                AND carried.carry_forward = 1
                AND unloaded.carry_forward = 1",
            [
                'actor' => $actor->id,
                'at' => $now->format('Y-m-d H:i:s'),
                'shift_id' => $shiftId,
            ]
        );
    }

    /** @return array{ok: false, error: string, confirm: false, phase: null, seeded: 0} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'confirm' => false, 'phase' => null, 'seeded' => 0];
    }
}
