<?php

declare(strict_types=1);

namespace Resm;

use Resm\Auth\Identity;
use Resm\Auth\Role;

/**
 * The Main Menu (spec 6.2), defined once.
 *
 * The tiles and the route guards read the same table, so a tile can never be
 * shown that its handler would refuse, and — the direction that matters — a
 * handler can never be reachable by a role the tile was hidden from. Hiding a
 * tile is presentation; the guard is the authorisation (spec 10.5).
 *
 * Order is fixed by the spec and is not a matter of preference: it is the
 * order these are reached for during a shift.
 */
final class Menu
{
    /**
     * @var array<string, array{
     *     label: string, icon: string, sub: string, role: Role,
     *     built: bool, summary: string, phase: string
     * }>
     */
    public const SECTIONS = [
        'admin' => [
            'label' => 'Admin Menu',
            'icon' => 'shield',
            'sub' => 'Seasons, teams, rosters, shifts',
            'role' => Role::Admin,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'officer' => [
            'label' => 'Officer Menu',
            'icon' => 'clipboard',
            'sub' => 'Assign, phase control, roster',
            'role' => Role::Officer,
            'built' => false,
            'summary' => 'The operational core: the two assign boards, the phase toggle, the '
                . 'coverage counter, copy from a previous shift, lunch management and broadcasts.',
            'phase' => 'Phase 4 of the build sequence (spec 11.1).',
        ],
        'check-in' => [
            'label' => 'Check In / Out',
            'icon' => 'check',
            'sub' => 'One tap, works offline',
            'role' => Role::Committeeman,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'my-shift' => [
            'label' => 'My Shift Status',
            'icon' => 'pin',
            'sub' => 'Where you are standing',
            'role' => Role::Committeeman,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'my-shifts' => [
            'label' => 'My Shifts',
            'icon' => 'calendar',
            'sub' => 'This season',
            'role' => Role::Committeeman,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'information' => [
            'label' => 'Rodeo Information',
            'icon' => 'info',
            'sub' => 'Coming soon',
            'role' => Role::Committeeman,
            'built' => false,
            'summary' => 'A placeholder in v1. The content structure and copy are still owed by '
                . 'Rodeo Express (spec 11.3).',
            'phase' => 'Content still to be supplied.',
        ],
        'tools' => [
            'label' => 'Tools',
            'icon' => 'gear',
            'sub' => 'Change PIN, sign out',
            'role' => Role::Committeeman,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
    ];

    /**
     * Tiles this user may see, in the spec's order.
     *
     * @return array<int, array{url: string, label: string, icon: string, sub: string}>
     */
    public static function tilesFor(App $app, Identity $user): array
    {
        $tiles = [];

        foreach (self::SECTIONS as $key => $section) {
            if (!self::visibleTo($user, $key)) {
                continue;
            }

            $tiles[] = [
                'url' => $app->url($key),
                'label' => $section['label'],
                'icon' => $section['icon'],
                'sub' => $section['sub'],
            ];
        }

        return $tiles;
    }

    public static function visibleTo(Identity $user, string $key): bool
    {
        $section = self::SECTIONS[$key] ?? null;
        if ($section === null || !$user->isActive) {
            return false;
        }

        return $user->role->atLeast($section['role']);
    }

    /**
     * @return array{label: string, icon: string, sub: string, role: Role, built: bool, summary: string, phase: string}|null
     */
    public static function section(string $key): ?array
    {
        return self::SECTIONS[$key] ?? null;
    }
}
