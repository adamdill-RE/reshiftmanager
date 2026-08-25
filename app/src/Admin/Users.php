<?php

declare(strict_types=1);

namespace Resm\Admin;

use PDOException;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Auth\Pin;
use Resm\Auth\Role;
use Resm\Database;
use Resm\PhoneNumber;

/**
 * Creating users by hand (spec 6.10.6 and 6.10.7).
 *
 * One service for both screens, because the fields are identical and only the
 * role differs. The route decides which roles it is willing to offer; this
 * refuses anything outside that set rather than trusting the form that
 * arrived.
 *
 * People are not season-scoped — the same committeeman comes back next year
 * with the same Member ID — but their team membership is. So the screens list
 * everyone holding the role and show team assignment against the season being
 * administered, which is also what stops an admin re-creating someone who is
 * already in the roster from a previous year.
 */
final class Users
{
    /** Longest search term worth sending to the database. */
    private const MAX_SEARCH = 60;

    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private int $pinCost,
        private string $defaultPin,
    ) {
    }

    /**
     * @param array<int, Role> $allowedRoles roles this screen may create
     * @param array<int, mixed> $teamIds
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function create(
        Identity $actor,
        int $seasonId,
        array $allowedRoles,
        string $memberId,
        string $lastName,
        string $firstName,
        string $roleValue,
        string $phone,
        string $email,
        array $teamIds,
    ): array {
        $memberId = trim($memberId);
        $lastName = trim($lastName);
        $firstName = trim($firstName);
        $phone = trim($phone);
        $email = trim($email);

        // The Member ID is the username. Without one there is no way to sign
        // in, so it is required here even though the column allows null — that
        // nullability exists for walk-ons added on the tarmac (spec 6.9.3),
        // who arrive through a different door.
        if ($memberId === '') {
            return self::fail('A Member ID is required — it is how this person signs in.');
        }
        if (mb_strlen($memberId) > 32) {
            return self::fail('That Member ID is too long (32 characters at most).');
        }
        if ($lastName === '' || $firstName === '') {
            return self::fail('Both a first and last name are required.');
        }
        if (mb_strlen($lastName) > 80 || mb_strlen($firstName) > 80) {
            return self::fail('That name is too long (80 characters at most).');
        }

        $role = Role::tryFrom($roleValue);
        if ($role === null || !in_array($role, $allowedRoles, true)) {
            return self::fail('That is not a role you can create here.');
        }

        if (mb_strlen($phone) > 40) {
            return self::fail('That phone number is too long (40 characters at most).');
        }
        if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return self::fail('That email address does not look right.');
        }

        // Teams are checked against the season rather than taken on trust: the
        // form is a list of checkboxes and nothing stops one naming a team in
        // another season, or none at all.
        $teamIds = $this->validTeamIds($seasonId, $teamIds);

        // Outside the transaction on purpose. bcrypt at cost 11 is deliberately
        // slow, and there is no reason to hold row locks while it runs.
        $pinHash = Pin::hash($this->defaultPin, $this->pinCost);

        $userId = null;

        try {
            $this->db->transaction(function (Database $db) use (
                $memberId, $lastName, $firstName, $role, $phone, $email,
                $pinHash, $teamIds, $seasonId, $actor, &$userId
            ): void {
                $db->execute(
                    'INSERT INTO `user`
                        (member_id, last_name, first_name, phone, phone_e164, email,
                         pin_hash, role, is_active, is_walkon, created_by)
                     VALUES
                        (:member_id, :last_name, :first_name, :phone, :phone_e164, :email,
                         :pin_hash, :role, 1, 0, :created_by)',
                    [
                        'member_id'  => $memberId,
                        'last_name'  => $lastName,
                        'first_name' => $firstName,
                        // Whatever was typed is kept for display, so the number
                        // reads the way its owner recognises it; the E.164 form
                        // is what tap-to-call uses (spec 6.10.3).
                        'phone'      => $phone === '' ? null : $phone,
                        'phone_e164' => PhoneNumber::normalise($phone),
                        'email'      => $email === '' ? null : $email,
                        'pin_hash'   => $pinHash,
                        'role'       => $role->value,
                        'created_by' => $actor->id,
                    ]
                );

                $userId = $db->lastInsertId();
                $this->writeTeams($db, $userId, $seasonId, $teamIds, $actor->id);
            });
        } catch (PDOException $e) {
            if (self::isDuplicate($e)) {
                return self::fail("Member ID {$memberId} is already in use.");
            }
            throw $e;
        }

        $this->audit->record($actor->id, 'user_create', 'user', $userId, null, [
            'member_id' => $memberId,
            'role'      => $role->value,
            'season_id' => $seasonId,
            'team_ids'  => $teamIds,
        ]);

        return ['ok' => true, 'error' => null, 'id' => $userId];
    }

    /**
     * Replace one person's team membership for a season.
     *
     * Only this season's rows are touched. A committeeman who covered Team B
     * in 2026 keeps that record when he moves to Team A in 2027, because the
     * 2026 shifts still point at it.
     *
     * @param array<int, mixed> $teamIds
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function setTeams(Identity $actor, int $seasonId, int $userId, array $teamIds): array
    {
        $user = $this->db->one('SELECT id, member_id FROM `user` WHERE id = :id', ['id' => $userId]);
        if ($user === null) {
            return self::fail('That person no longer exists.');
        }

        $before = $this->teamIdsFor($userId, $seasonId);
        $after = $this->validTeamIds($seasonId, $teamIds);

        sort($before);
        sort($after);
        if ($before === $after) {
            return ['ok' => true, 'error' => null, 'id' => $userId];
        }

        $this->db->transaction(function (Database $db) use ($userId, $seasonId, $after, $actor): void {
            $db->execute(
                'DELETE FROM team_member WHERE user_id = :user_id AND season_id = :season_id',
                ['user_id' => $userId, 'season_id' => $seasonId]
            );
            $this->writeTeams($db, $userId, $seasonId, $after, $actor->id);
        });

        $this->audit->record(
            $actor->id,
            'user_set_teams',
            'user',
            $userId,
            ['season_id' => $seasonId, 'team_ids' => $before],
            ['season_id' => $seasonId, 'team_ids' => $after]
        );

        return ['ok' => true, 'error' => null, 'id' => $userId];
    }

    /**
     * Deactivate or reactivate an account. Never a delete: check-in history,
     * assignments and the audit trail all point at the row.
     *
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function setActive(Identity $actor, int $userId, bool $active): array
    {
        $user = $this->db->one(
            'SELECT id, member_id, is_active FROM `user` WHERE id = :id',
            ['id' => $userId]
        );
        if ($user === null) {
            return self::fail('That person no longer exists.');
        }

        // An administrator who deactivates their own account has locked
        // themselves out of the screen that would undo it.
        if (!$active && (int) $user['id'] === $actor->id) {
            return self::fail('You cannot deactivate your own account.');
        }

        $this->db->execute(
            'UPDATE `user` SET is_active = :active WHERE id = :id',
            ['active' => $active ? 1 : 0, 'id' => $userId]
        );

        // A deactivated account must stop working now, not whenever its
        // 90-day cookie happens to lapse.
        if (!$active) {
            $this->db->execute(
                'UPDATE auth_token SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL',
                ['now' => gmdate('Y-m-d H:i:s'), 'id' => $userId]
            );
        }

        $this->audit->record(
            $actor->id,
            $active ? 'user_activate' : 'user_deactivate',
            'user',
            $userId,
            ['is_active' => (int) $user['is_active']],
            ['is_active' => $active ? 1 : 0]
        );

        return ['ok' => true, 'error' => null, 'id' => $userId];
    }

    /**
     * Everyone holding one of these roles, with the teams they cover in this
     * season.
     *
     * @param array<int, Role> $roles
     * @return array<int, array<string, mixed>>
     */
    public function withRoles(int $seasonId, array $roles, string $search = ''): array
    {
        if ($roles === []) {
            return [];
        }

        $params = ['season_id' => $seasonId];
        $names = [];
        foreach (array_values($roles) as $i => $role) {
            $names[] = ':role' . $i;
            $params['role' . $i] = $role->value;
        }

        $where = 'u.role IN (' . implode(', ', $names) . ')';

        $search = trim($search);
        if ($search !== '') {
            // LIKE reads _ and % as wildcards, so a search for "A_1" must not
            // quietly match "AB1". Escape them, and the escape character too.
            $params['search'] = '%' . str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                mb_substr($search, 0, self::MAX_SEARCH)
            ) . '%';

            // One haystack and one placeholder, not four: with emulated
            // prepares off, PDO binds each name to exactly one marker, so a
            // repeated :search is an HY093 error rather than four comparisons.
            $where .= " AND CONCAT_WS(' ', u.member_id, u.first_name, u.last_name,"
                . " CONCAT(u.last_name, ', ', u.first_name)) LIKE :search";
        }

        return $this->db->all(
            "SELECT u.id, u.member_id, u.last_name, u.first_name, u.role,
                    u.is_active, u.is_walkon, u.phone, u.email,
                    GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') AS team_names,
                    GROUP_CONCAT(t.id) AS team_ids
             FROM `user` u
             LEFT JOIN team_member tm ON tm.user_id = u.id AND tm.season_id = :season_id
             LEFT JOIN team t ON t.id = tm.team_id
             WHERE {$where}
             GROUP BY u.id
             ORDER BY u.is_active DESC, u.last_name, u.first_name",
            $params
        );
    }

    /**
     * The team ids on a listing row, as ints, for ticking checkboxes.
     *
     * @param array<string, mixed> $row
     * @return array<int, int>
     */
    public static function rowTeamIds(array $row): array
    {
        $raw = $row['team_ids'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return array_map('intval', explode(',', $raw));
    }

    /**
     * @param array<int, int> $teamIds
     */
    private function writeTeams(Database $db, int $userId, int $seasonId, array $teamIds, ?int $actorId): void
    {
        foreach ($teamIds as $teamId) {
            $db->execute(
                'INSERT INTO team_member (user_id, team_id, season_id, created_by)
                 VALUES (:user_id, :team_id, :season_id, :created_by)',
                [
                    'user_id'    => $userId,
                    'team_id'    => $teamId,
                    'season_id'  => $seasonId,
                    'created_by' => $actorId,
                ]
            );
        }
    }

    /** @return array<int, int> */
    private function teamIdsFor(int $userId, int $seasonId): array
    {
        $rows = $this->db->all(
            'SELECT team_id FROM team_member WHERE user_id = :user_id AND season_id = :season_id',
            ['user_id' => $userId, 'season_id' => $seasonId]
        );

        return array_map(static fn (array $r): int => (int) $r['team_id'], $rows);
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<int, int>
     */
    private function validTeamIds(int $seasonId, array $candidates): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $candidates))));
        if ($wanted === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT id FROM team WHERE season_id = :season_id AND is_active = 1',
            ['season_id' => $seasonId]
        );
        $inSeason = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        return array_values(array_intersect($wanted, $inSeason));
    }

    /** @return array{ok: false, error: string, id: null} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'id' => null];
    }

    private static function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
