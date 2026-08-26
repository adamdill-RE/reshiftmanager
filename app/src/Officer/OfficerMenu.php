<?php

declare(strict_types=1);

namespace Resm\Officer;

use Resm\App;
use Resm\Auth\Capability;
use Resm\Auth\Identity;

/**
 * The Officer Menu (spec 6.9), defined once, the way the main and admin menus
 * are.
 *
 * Every section names the capability its handler requires and the tiles are
 * built from the same table the guards read, so a tile can never be shown that
 * its handler would refuse. All of these are team-scoped, which is why
 * tilesFor takes a team: asking whether an officer may assign positions
 * without naming the team is not a question with an answer (Auth\Access).
 */
final class OfficerMenu
{
    /**
     * @var array<string, array{label: string, sub: string, capability: Capability, spec: string}>
     */
    public const SECTIONS = [
        'roster' => [
            'label' => 'View Roster',
            'sub' => 'Skills, phone, check in, PIN',
            'capability' => Capability::ViewTeamRoster,
            'spec' => '6.9.3',
        ],
        'assign/unload' => [
            'label' => 'Assign Unload',
            'sub' => 'Two taps per placement',
            'capability' => Capability::AssignPositions,
            'spec' => '6.9.4',
        ],
        'assign/bump_run' => [
            'label' => 'Assign Bump and Run',
            'sub' => 'Two taps per placement',
            'capability' => Capability::AssignPositions,
            'spec' => '6.9.4',
        ],
        'copy' => [
            'label' => 'Copy From Previous Shift',
            'sub' => 'A twenty-minute job in three',
            'capability' => Capability::CopyAssignments,
            'spec' => '6.9.6',
        ],
        'board/unload' => [
            'label' => 'View Unload',
            'sub' => 'Read-only board',
            'capability' => Capability::ViewTeamRoster,
            'spec' => '6.9.7',
        ],
        'board/bump_run' => [
            'label' => 'View Bump and Run',
            'sub' => 'Read-only board',
            'capability' => Capability::ViewTeamRoster,
            'spec' => '6.9.7',
        ],
        'checked-in' => [
            'label' => 'View Checked In',
            'sub' => 'Who is on the tarmac',
            'capability' => Capability::ViewTeamRoster,
            'spec' => '6.9.8',
        ],
        'absent' => [
            'label' => 'View Absent',
            'sub' => 'Who to ring',
            'capability' => Capability::ViewTeamRoster,
            'spec' => '6.9.8',
        ],
        'lunch' => [
            'label' => 'Lunch Management',
            'sub' => 'Three states, moved in bulk',
            'capability' => Capability::CheckOthersInOut,
            'spec' => '6.9.9',
        ],
        'broadcast' => [
            'label' => 'Broadcast Message',
            'sub' => 'Pinned to every widget',
            'capability' => Capability::SendBroadcast,
            'spec' => '6.9.10',
        ],
        'pins' => [
            'label' => 'Reset PINs',
            'sub' => 'Back to 1234',
            'capability' => Capability::ResetCommitteemanPin,
            'spec' => '6.9.11',
        ],
    ];

    /**
     * @return array<int, array{key: string, url: string, label: string, sub: string}>
     */
    public static function tilesFor(App $app, Identity $user, ?int $teamId, ?int $shiftId): array
    {
        $tiles = [];

        foreach (self::SECTIONS as $key => $section) {
            if (!$user->can($section['capability'], $teamId)) {
                continue;
            }

            $tiles[] = [
                'key' => $key,
                'url' => $app->url('officer/' . $key) . self::query($teamId, $shiftId),
                'label' => $section['label'],
                'sub' => $section['sub'],
            ];
        }

        return $tiles;
    }

    /**
     * The team and shift an officer is looking at, carried between screens.
     *
     * Explicit rather than remembered in the session: an Admin comparing two
     * teams has both open, and a board that silently followed the last tap
     * would show him the wrong one.
     */
    public static function query(?int $teamId, ?int $shiftId, string $extra = ''): string
    {
        $parts = [];
        if ($teamId !== null) {
            $parts['team'] = $teamId;
        }
        if ($shiftId !== null) {
            $parts['shift'] = $shiftId;
        }

        $query = http_build_query($parts);
        if ($extra !== '') {
            $query = $query === '' ? $extra : $query . '&' . $extra;
        }

        return $query === '' ? '' : '?' . $query;
    }

    /** @return array{label: string, sub: string, capability: Capability, spec: string}|null */
    public static function section(string $key): ?array
    {
        return self::SECTIONS[$key] ?? null;
    }
}
