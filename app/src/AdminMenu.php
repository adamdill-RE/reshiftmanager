<?php

declare(strict_types=1);

namespace Resm;

use Resm\Auth\Capability;
use Resm\Auth\Identity;

/**
 * The Admin Menu (spec 6.10), defined once, the same way the main menu is.
 *
 * Every section names the capability its handler requires, so the tile and the
 * guard cannot drift apart. All of these are Admin-only: the capabilities
 * below are season-wide, and Access grants those to no other role.
 */
final class AdminMenu
{
    /**
     * @var array<string, array{
     *     label: string, sub: string, capability: Capability,
     *     built: bool, summary: string, phase: string
     * }>
     */
    public const SECTIONS = [
        'seasons' => [
            'label' => 'Manage Seasons',
            'sub' => 'Wraps every year of operational data',
            'capability' => Capability::ManageSeasons,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'teams' => [
            'label' => 'Manage Teams',
            'sub' => 'Within the active season',
            'capability' => Capability::ManageTeams,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'committeemen' => [
            'label' => 'Create Committeeman',
            'sub' => 'One at a time, by hand',
            'capability' => Capability::CreateOfficerAdminUsers,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'officers' => [
            'label' => 'Create Officer / Admin',
            'sub' => 'With team assignment',
            'capability' => Capability::CreateOfficerAdminUsers,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'shifts' => [
            'label' => 'Create Shifts',
            'sub' => 'Per team, in bulk over a date range',
            'capability' => Capability::CreateShifts,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'import' => [
            'label' => 'Import Roster',
            'sub' => 'CSV, dry run first',
            'capability' => Capability::ImportExportRoster,
            'built' => true,
            'summary' => '',
            'phase' => '',
        ],
        'export' => [
            'label' => 'Export Roster',
            'sub' => 'CSV, per shift',
            'capability' => Capability::ImportExportRoster,
            'built' => false,
            'summary' => 'Names, Member IDs, check-in and check-out times, last assigned '
                . 'position in each phase, and certified skills.',
            'phase' => 'Phase 6 of the build sequence (spec 11.1).',
        ],
        'matrix' => [
            'label' => 'Position Matrix Editor',
            'sub' => '98 positions, 10 groups',
            'capability' => Capability::EditPositionMatrix,
            'built' => false,
            'summary' => 'Add, rename, reorder, retire and re-flag positions without a code '
                . 'change. Retiring one keeps the history that points at it.',
            'phase' => 'Phase 6 of the build sequence (spec 11.1).',
        ],
        'audit' => [
            'label' => 'Audit Log',
            'sub' => 'Who changed what, and when',
            'capability' => Capability::ViewAuditLog,
            'built' => false,
            'summary' => 'Every assignment change, phase flip, check event, PIN reset and '
                . 'import. Answers "who moved Johnson off Curve 2 and when".',
            'phase' => 'Phase 6 of the build sequence (spec 11.1).',
        ],
    ];

    /**
     * @return array<int, array{key: string, url: string, label: string, sub: string, built: bool}>
     */
    public static function tilesFor(App $app, Identity $user): array
    {
        $tiles = [];

        foreach (self::SECTIONS as $key => $section) {
            if (!$user->can($section['capability'])) {
                continue;
            }

            $tiles[] = [
                'key' => $key,
                'url' => $app->url('admin/' . $key),
                'label' => $section['label'],
                'sub' => $section['sub'],
                'built' => $section['built'],
            ];
        }

        return $tiles;
    }

    /**
     * @return array{label: string, sub: string, capability: Capability, built: bool, summary: string, phase: string}|null
     */
    public static function section(string $key): ?array
    {
        return self::SECTIONS[$key] ?? null;
    }
}
