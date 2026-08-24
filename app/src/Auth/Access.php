<?php

declare(strict_types=1);

namespace Resm\Auth;

/**
 * The permission matrix, applied.
 *
 * Spec 10.5: role and team scope are enforced server-side on every request,
 * and hiding a menu tile is presentation, never authorisation. So every
 * handler that touches team data calls require() — the tile it came from
 * having been hidden is not evidence of anything.
 *
 * The one rule worth stating plainly: a team-scoped capability needs a team.
 * Asking "may this officer assign positions?" without naming which team is not
 * a question with an answer, and answering "yes" to it is how an officer ends
 * up editing another team's board. Passing null denies, for officers.
 */
final class Access
{
    public static function allows(Identity $user, Capability $capability, ?int $teamId = null): bool
    {
        // A deactivated account keeps its row for the audit trail but can do
        // nothing. Checked first so no later branch can grant anything.
        if (!$user->isActive) {
            return false;
        }

        return match ($capability->scope()) {
            // Anything a user does to their own record. An Admin has these too
            // — they check themselves in like everyone else.
            Scope::Own => true,

            Scope::Team => match ($user->role) {
                Role::Admin => true,
                Role::Officer => $teamId !== null && $user->onTeam($teamId),
                Role::Committeeman => false,
            },

            Scope::Everywhere => $user->role === Role::Admin,
        };
    }

    /** @throws AccessDenied */
    public static function require(Identity $user, Capability $capability, ?int $teamId = null): void
    {
        if (!self::allows($user, $capability, $teamId)) {
            throw new AccessDenied(sprintf(
                'user %d (%s) may not %s%s',
                $user->id,
                $user->role->value,
                $capability->value,
                $teamId === null ? '' : " on team {$teamId}"
            ));
        }
    }

    /**
     * Teams this user may act on for a team-scoped capability. Admins are not
     * limited to their assignments, so null means "every team" and the caller
     * must not filter.
     *
     * @return array<int, int>|null
     */
    public static function teamsFor(Identity $user, Capability $capability): ?array
    {
        if (!$user->isActive || $capability->scope() !== Scope::Team) {
            return [];
        }

        return match ($user->role) {
            Role::Admin => null,
            Role::Officer => $user->teamIds,
            Role::Committeeman => [],
        };
    }
}
