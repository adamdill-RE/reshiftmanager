<?php

declare(strict_types=1);

namespace Resm\Poll;

use DateTimeImmutable;
use DateTimeZone;
use Resm\App;
use Resm\Auth\Identity;
use Throwable;
use Resm\Database;
use Resm\Shift\Window;

/**
 * The read side of the polling layer (spec 10.2).
 *
 * The write side has been in place since phase 3: every check, assignment,
 * lunch change, phase flip and broadcast bumps state_version for its shift.
 * This is what the clients ask about it.
 *
 * The whole design rests on the unchanged answer being nearly free, because
 * that is the answer almost every poll gets. Thirty phones at ten seconds is
 * three requests a second, and all but a handful of them are "no". So the
 * no-change path is one indexed lookup and a 304 with no body — and, more
 * easily missed, that one lookup also has to be the authorisation, or the
 * cheap path stops being cheap the moment it is made safe.
 *
 * Hence version(): the team check is folded into the same statement as the
 * version read rather than run before it. A shift that does not exist and a
 * shift this user may not see return the same null, so polling ids is not a
 * way to find out which shifts exist.
 */
final class State
{
    /**
     * What a rendered page hands its poller (spec 10.2).
     *
     * Every screen needs the same four things before poll.js can start: which
     * shift, the version it is being rendered at, when to stop, and how often
     * to ask. Resolved here so no screen has to remember, and returning null
     * — no signed-in user, no shift, a database that has gone away — simply
     * means the page does not poll. Like the status strip it decorates, this
     * must never take down the page it is attached to.
     *
     * @return array{shift: int, version: int, closes_at: ?string, foreground: int, background: int}|null
     */
    public static function forPage(App $app, ?int $shiftId): ?array
    {
        if ($shiftId === null || $shiftId <= 0) {
            return null;
        }

        try {
            $user = $app->user();
            if ($user === null) {
                return null;
            }

            $state = new self($app->db(), new Window($app->displayTimezone()));
            $version = $state->version($user, $shiftId);
            if ($version === null) {
                return null;
            }

            return [
                'shift' => $shiftId,
                'version' => $version,
                'closes_at' => $state->closesAt($shiftId)?->format('c'),
                'foreground' => $app->config->int('poll.foreground_seconds', 10),
                'background' => $app->config->int('poll.background_seconds', 60),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        private Database $db,
        private Window $window,
    ) {
    }

    /**
     * This shift's current version, or null if the user may not see it.
     *
     * Committeemen and officers both reach a shift through team_member — an
     * officer is a member of the teams he runs — so one join serves both. An
     * Admin is not limited to their assignments (spec 2.2), which is why the
     * two statements differ rather than one being parameterised: an Admin's
     * query has no membership to check, not a membership that always passes.
     */
    public function version(Identity $user, int $shiftId): ?int
    {
        if (!$user->isActive) {
            return null;
        }

        $row = $user->isAdmin()
            ? $this->db->one(
                'SELECT v.version FROM state_version v WHERE v.shift_id = :shift_id',
                ['shift_id' => $shiftId]
            )
            : $this->db->one(
                'SELECT v.version
                   FROM state_version v
                   JOIN shift s ON s.id = v.shift_id
                   JOIN team_member tm ON tm.team_id = s.team_id
                                      AND tm.season_id = s.season_id
                                      AND tm.user_id = :user_id
                  WHERE v.shift_id = :shift_id',
                ['user_id' => $user->id, 'shift_id' => $shiftId]
            );

        return $row === null ? null : (int) $row['version'];
    }

    /**
     * When this shift stops being reachable (spec 5.3).
     *
     * Sent with the page and with every changed answer, never asked for on its
     * own. Spec 10.2 makes pausing the client's job — "paused entirely when
     * offline or when the shift window is closed" — and a client that already
     * knows the closing time can park itself without a request. Checking it
     * server-side on every poll would have doubled the cost of the one path
     * that had to stay free.
     */
    public function closesAt(int $shiftId): ?DateTimeImmutable
    {
        $row = $this->db->one(
            'SELECT starts_at FROM shift WHERE id = :id',
            ['id' => $shiftId]
        );

        if ($row === null) {
            return null;
        }

        return $this->window->closesFor(
            new DateTimeImmutable((string) $row['starts_at'], new DateTimeZone('UTC'))
        );
    }
}
