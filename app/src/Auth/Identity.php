<?php

declare(strict_types=1);

namespace Resm\Auth;

/**
 * The signed-in user, as the rest of a request sees them.
 *
 * Deliberately not the full user row: no PIN hash, no phone, no email. Contact
 * details are personal data belonging to volunteers (spec 10.5) and are loaded
 * only by the screens permitted to show them.
 */
final class Identity
{
    /** @param array<int, int> $teamIds teams this user belongs to, this season */
    public function __construct(
        public readonly int $id,
        public readonly ?string $memberId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly Role $role,
        public readonly bool $isActive,
        public readonly int $tokenId,
        public readonly array $teamIds,
    ) {
    }

    public function name(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /** "Smith, Al" — the sort order officers read rosters in (spec 6.9.3). */
    public function listName(): string
    {
        return $this->lastName . ', ' . $this->firstName;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isOfficer(): bool
    {
        return $this->role === Role::Officer;
    }

    public function onTeam(int $teamId): bool
    {
        return in_array($teamId, $this->teamIds, true);
    }

    public function can(Capability $capability, ?int $teamId = null): bool
    {
        return Access::allows($this, $capability, $teamId);
    }
}
