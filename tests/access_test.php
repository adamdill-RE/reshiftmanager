<?php

declare(strict_types=1);

use Resm\Auth\Access;
use Resm\Auth\Capability;
use Resm\Auth\Identity;
use Resm\Auth\Role;
use Resm\Auth\Scope;

/**
 * The permission matrix from spec 2.2, transcribed a second time.
 *
 * Deliberately written out rather than derived from Capability::scope(): a
 * test that asks the code what it thinks and then agrees with it proves
 * nothing. Each row below is read off the specification table, so a change to
 * the matrix has to be made in both places on purpose.
 *
 * Columns: committeeman, officer on their own team, officer on someone else's
 * team, admin.
 */
const MATRIX = [
    // capability                          => [cttm,  own,   other, admin]
    'check_self_in_out'                    => [true,  true,  true,  true],
    'view_own_assignment'                  => [true,  true,  true,  true],
    'toggle_own_lunch'                     => [true,  true,  true,  true],
    'change_own_pin'                       => [true,  true,  true,  true],

    'view_team_roster'                     => [false, true,  false, true],
    'edit_certified_skills'                => [false, true,  false, true],
    'check_others_in_out'                  => [false, true,  false, true],
    'assign_positions'                     => [false, true,  false, true],
    'toggle_phase'                         => [false, true,  false, true],
    'copy_assignments'                     => [false, true,  false, true],
    'reset_committeeman_pin'               => [false, true,  false, true],
    'send_broadcast'                       => [false, true,  false, true],
    'add_walkon'                           => [false, true,  false, true],

    'manage_seasons'                       => [false, false, false, true],
    'manage_teams'                         => [false, false, false, true],
    'import_export_roster'                 => [false, false, false, true],
    'create_shifts'                        => [false, false, false, true],
    'create_officer_admin_users'           => [false, false, false, true],
    'edit_position_matrix'                 => [false, false, false, true],
    'view_audit_log'                       => [false, false, false, true],
];

function identity(Role $role, array $teamIds = [7], bool $active = true): Identity
{
    return new Identity(
        id: 1,
        memberId: '1001',
        firstName: 'Al',
        lastName: 'Smith',
        role: $role,
        isActive: $active,
        tokenId: 1,
        teamIds: $teamIds,
    );
}

test('every capability in the spec matrix is represented', function (): void {
    $defined = array_map(static fn (Capability $c): string => $c->value, Capability::cases());
    sort($defined);
    $expected = array_keys(MATRIX);
    sort($expected);

    assertSame($expected, $defined, 'Capability enum vs the spec 2.2 matrix');
});

test('the matrix holds for every capability and role', function (): void {
    $committeeman = identity(Role::Committeeman, []);
    $officer = identity(Role::Officer, [7]);
    $admin = identity(Role::Admin, []);

    foreach (MATRIX as $value => [$asCommitteeman, $onOwnTeam, $onOtherTeam, $asAdmin]) {
        $capability = Capability::from($value);

        assertSame($asCommitteeman, Access::allows($committeeman, $capability, 7), "committeeman / {$value}");
        assertSame($onOwnTeam, Access::allows($officer, $capability, 7), "officer on own team / {$value}");
        assertSame($onOtherTeam, Access::allows($officer, $capability, 99), "officer on another team / {$value}");
        assertSame($asAdmin, Access::allows($admin, $capability, 7), "admin / {$value}");
    }
});

test('an officer is denied a team capability when no team is named', function (): void {
    // Asking "may this officer assign positions?" without saying which team is
    // not a question with an answer. Denying is the only safe default.
    $officer = identity(Role::Officer, [7]);

    assertSame(false, Access::allows($officer, Capability::AssignPositions, null));
    assertSame(true, Access::allows($officer, Capability::AssignPositions, 7));
});

test('an admin is not restricted by team assignment', function (): void {
    // Spec 2.1: Admin covers all teams, always, with no shift-window limit.
    $admin = identity(Role::Admin, []);

    assertSame(true, Access::allows($admin, Capability::AssignPositions, 99));
    assertSame(true, Access::allows($admin, Capability::AssignPositions, null));
});

test('a deactivated account can do nothing at all', function (): void {
    foreach ([Role::Committeeman, Role::Officer, Role::Admin] as $role) {
        $user = identity($role, [7], active: false);
        foreach (Capability::cases() as $capability) {
            assertSame(false, Access::allows($user, $capability, 7), "{$role->value} / {$capability->value}");
        }
    }
});

test('require throws rather than returning false', function (): void {
    $committeeman = identity(Role::Committeeman, []);

    assertThrows(
        Resm\Auth\AccessDenied::class,
        static fn () => Access::require($committeeman, Capability::ViewTeamRoster, 7)
    );

    // And stays quiet when permitted.
    Access::require($committeeman, Capability::ChangeOwnPin);
});

test('teamsFor scopes an officer and leaves an admin unfiltered', function (): void {
    assertSame([7, 9], Access::teamsFor(identity(Role::Officer, [7, 9]), Capability::ViewTeamRoster));
    assertSame(null, Access::teamsFor(identity(Role::Admin), Capability::ViewTeamRoster), 'admin means every team');
    assertSame([], Access::teamsFor(identity(Role::Committeeman, []), Capability::ViewTeamRoster));

    // Not a team-scoped capability, so there is no team list to speak of.
    assertSame([], Access::teamsFor(identity(Role::Officer, [7]), Capability::ChangeOwnPin));
});

test('scopes line up with the minimum role each capability needs', function (): void {
    assertSame(Role::Committeeman, Capability::ChangeOwnPin->minimumRole());
    assertSame(Role::Officer, Capability::AssignPositions->minimumRole());
    assertSame(Role::Admin, Capability::ViewAuditLog->minimumRole());
    assertSame(Scope::Team, Capability::SendBroadcast->scope());
});

test('roles rank for menu visibility only', function (): void {
    assertTrue(Role::Admin->atLeast(Role::Officer));
    assertTrue(Role::Officer->atLeast(Role::Officer));
    assertTrue(!Role::Committeeman->atLeast(Role::Officer));
});
