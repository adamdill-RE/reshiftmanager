<?php

declare(strict_types=1);

namespace Resm\Auth;

use Resm\Database;

/**
 * Login rate limiting (spec 3.2): ten failed attempts from one address within
 * fifteen minutes trigger a sixty-second lockout.
 *
 * Deliberately loose, and the spec says why: an officer locked out at 17:00
 * with a shift starting is a worse outcome than a brute-force attempt against
 * an internal shift tool. Sixty seconds is enough to stop a script and short
 * enough that a man with cold hands who mistyped his PIN ten times is back in
 * before he has finished swearing.
 */
final class RateLimiter
{
    public function __construct(
        private Database $db,
        private int $threshold,
        private int $windowSeconds,
        private int $lockoutSeconds,
    ) {
    }

    /**
     * Seconds still to wait, or 0 when not locked out.
     *
     * Counted per address, not per account, because the attack this stops is
     * one machine walking through Member IDs. A consequence worth knowing: a
     * committee sharing one wifi connection shares the counter, which is part
     * of why the lockout is sixty seconds and not fifteen minutes.
     */
    public function lockedFor(?string $ip): int
    {
        // No usable address means no basis to limit on. Failing open is the
        // deliberate choice here; the alternative locks out everyone when a
        // proxy stops passing REMOTE_ADDR.
        if ($ip === null) {
            return 0;
        }

        $row = $this->db->one(
            'SELECT COUNT(*) AS failures, MAX(occurred_at) AS last_failure
             FROM login_attempt
             WHERE ip = :ip AND succeeded = 0 AND occurred_at >= :since',
            ['ip' => $ip, 'since' => gmdate('Y-m-d H:i:s', time() - $this->windowSeconds)]
        );

        if ($row === null || (int) $row['failures'] < $this->threshold) {
            return 0;
        }

        $last = strtotime((string) $row['last_failure'] . ' UTC');
        if ($last === false) {
            return 0;
        }

        return max(0, $this->lockoutSeconds - (time() - $last));
    }

    public function record(?string $ip, ?string $memberId, bool $succeeded): void
    {
        if ($ip === null) {
            return;
        }

        $this->db->execute(
            'INSERT INTO login_attempt (ip, member_id, succeeded, occurred_at)
             VALUES (:ip, :member_id, :succeeded, :occurred_at)',
            [
                'ip'          => $ip,
                'member_id'   => $memberId,
                'succeeded'   => $succeeded ? 1 : 0,
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        // No cron on this host, so the table is trimmed occasionally on the way
        // past rather than growing without limit. One login in fifty pays for
        // it, and only ever deletes rows far outside the window.
        if (random_int(1, 50) === 1) {
            $this->prune();
        }
    }

    public function prune(int $keepSeconds = 86400): void
    {
        $this->db->execute(
            'DELETE FROM login_attempt WHERE occurred_at < :cutoff',
            ['cutoff' => gmdate('Y-m-d H:i:s', time() - $keepSeconds)]
        );
    }
}
