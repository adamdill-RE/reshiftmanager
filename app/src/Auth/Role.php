<?php

declare(strict_types=1);

namespace Resm\Auth;

/**
 * The three roles from spec 2.1.
 *
 * Officers and Admins may cover several teams, and a committeeman may belong
 * to more than one, so a role on its own never decides an officer's access —
 * the team scope in Access does the other half.
 */
enum Role: string
{
    case Committeeman = 'committeeman';
    case Officer = 'officer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Committeeman => 'Committeeman',
            self::Officer => 'Officer',
            self::Admin => 'Admin',
        };
    }

    /** Higher outranks lower. Used only for menu visibility, never for access. */
    public function rank(): int
    {
        return match ($this) {
            self::Committeeman => 1,
            self::Officer => 2,
            self::Admin => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
