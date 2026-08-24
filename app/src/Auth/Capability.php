<?php

declare(strict_types=1);

namespace Resm\Auth;

/**
 * Every capability in the permission matrix, spec 2.2, plus season management
 * from spec 6.10.1 which the matrix implies but does not list.
 *
 * The matrix is transcribed once, here, so there is a single place to check it
 * against the spec — rather than the same rule being re-decided in each screen
 * that happens to need it.
 */
enum Capability: string
{
    // Every authenticated user, over themselves.
    case CheckSelfInOut = 'check_self_in_out';
    case ViewOwnAssignment = 'view_own_assignment';
    case ToggleOwnLunch = 'toggle_own_lunch';
    case ChangeOwnPin = 'change_own_pin';

    // Officers over their own teams; Admins over all teams.
    case ViewTeamRoster = 'view_team_roster';
    case EditCertifiedSkills = 'edit_certified_skills';
    case CheckOthersInOut = 'check_others_in_out';
    case AssignPositions = 'assign_positions';
    case TogglePhase = 'toggle_phase';
    case CopyAssignments = 'copy_assignments';
    case ResetCommitteemanPin = 'reset_committeeman_pin';
    case SendBroadcast = 'send_broadcast';
    case AddWalkon = 'add_walkon';

    // Admins only.
    case ManageSeasons = 'manage_seasons';
    case ManageTeams = 'manage_teams';
    case ImportExportRoster = 'import_export_roster';
    case CreateShifts = 'create_shifts';
    case CreateOfficerAdminUsers = 'create_officer_admin_users';
    case EditPositionMatrix = 'edit_position_matrix';
    case ViewAuditLog = 'view_audit_log';

    public function scope(): Scope
    {
        return match ($this) {
            self::CheckSelfInOut,
            self::ViewOwnAssignment,
            self::ToggleOwnLunch,
            self::ChangeOwnPin => Scope::Own,

            self::ViewTeamRoster,
            self::EditCertifiedSkills,
            self::CheckOthersInOut,
            self::AssignPositions,
            self::TogglePhase,
            self::CopyAssignments,
            self::ResetCommitteemanPin,
            self::SendBroadcast,
            self::AddWalkon => Scope::Team,

            self::ManageSeasons,
            self::ManageTeams,
            self::ImportExportRoster,
            self::CreateShifts,
            self::CreateOfficerAdminUsers,
            self::EditPositionMatrix,
            self::ViewAuditLog => Scope::Everywhere,
        };
    }

    /** The lowest role that can ever hold this, before team scope is applied. */
    public function minimumRole(): Role
    {
        return match ($this->scope()) {
            Scope::Own => Role::Committeeman,
            Scope::Team => Role::Officer,
            Scope::Everywhere => Role::Admin,
        };
    }
}
