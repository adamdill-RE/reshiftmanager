<?php

declare(strict_types=1);

namespace Resm\Shift;

use Resm\App;
use Resm\AuditLog;
use Throwable;

/**
 * The data behind the persistent status strip (spec 6.3).
 *
 * Its own class rather than a helper each screen remembers to call: the strip
 * is meant to be on every screen, and "on every screen except the one someone
 * forgot" is how it stops being trusted.
 *
 * Nothing here may take a page down. The strip decorates a screen; it is not
 * the screen. A failure to build it — no active season, a database that has
 * gone away mid-shift, a user with nothing on today — renders no strip and
 * leaves the page underneath working, which matters most for /status, the one
 * page whose job is to be reachable when the database is not.
 *
 * The polling layer (spec 10.2) re-renders this same strip from the same data,
 * which is why forShift exists beside forRequest. A second renderer in
 * JavaScript would drift from this one, and the screen it drifted on would be
 * the one claiming to be live.
 */
final class Widget
{
    /** @return array<string, mixed>|null */
    public static function forRequest(App $app): ?array
    {
        try {
            return self::build($app, null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The strip for one named shift, for a client that is polling it.
     *
     * Resolved through the user's own candidate list exactly as forRequest is,
     * so naming a shift in a query string reaches nothing that resolving it
     * from the session would not have.
     *
     * @return array<string, mixed>|null
     */
    public static function forShift(App $app, int $shiftId): ?array
    {
        try {
            return self::build($app, $shiftId);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function build(App $app, ?int $shiftId): ?array
    {
        $user = $app->user();
        if ($user === null) {
            return null;
        }

        $season = $app->db()->one('SELECT id FROM season WHERE is_active = 1 LIMIT 1');
        if ($season === null) {
            return null;
        }

        $resolved = (new CurrentShift($app->db(), $app->displayTimezone()))
            ->forUser($user->id, (int) $season['id']);

        $shift = $resolved['current'];

        // A named shift wins over the resolved default, but only if it is one
        // of his — the candidate list is the guard, same as everywhere else.
        if ($shiftId !== null) {
            $shift = null;
            foreach ($resolved['candidates'] as $candidate) {
                if ((int) $candidate['id'] === $shiftId) {
                    $shift = $candidate;
                    break;
                }
            }
        }

        // Spec 6.3: the strip appears once a user has checked in or out. Before
        // that there is no status to report, and a strip that says nothing is
        // one people learn to look past.
        if ($shift === null || ($shift['check_state'] ?? null) === null) {
            return null;
        }

        $attendance = new Attendance($app->db(), new AuditLog($app->db()));
        $assignments = $attendance->assignments((int) $shift['id'], $user->id);

        return [
            'shift' => $shift,
            'assignment' => $assignments[(string) $shift['current_phase']] ?? null,
            'broadcast' => $attendance->broadcast((int) $shift['id']),
            'doubled' => $resolved['doubled'],
            'renderedAt' => $app->now()->format('c'),
        ];
    }
}
