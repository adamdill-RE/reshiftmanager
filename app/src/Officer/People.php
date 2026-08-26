<?php

declare(strict_types=1);

namespace Resm\Officer;

use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Auth\Pin;
use Resm\Database;
use Resm\PhoneNumber;

/**
 * What an officer does to a person rather than to the board (spec 6.9.3,
 * 6.9.11, 7.3).
 *
 * Walk-ons, PIN resets and certified skills. Every one of them takes a team
 * and checks that the person being changed is actually on it, even though the
 * route has already checked that the officer may act on that team. The route
 * guards the team; this guards the target. Without the second check an officer
 * could post another team's user id at his own team's screen and reset a PIN
 * he has no business touching.
 */
final class People
{
    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private int $pinCost,
        private string $defaultPin,
    ) {
    }

    /**
     * Add someone who turned up without being on the roster (spec 6.9.3).
     *
     * Last name, first name, phone and an optional Member ID, in under twenty
     * seconds. The Member ID is optional because a walk-on on the tarmac may
     * not have one to hand; without it he cannot sign in until somebody fills
     * it in, which is the right trade at 17:00 with a bus arriving.
     *
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function addWalkon(
        Identity $actor,
        int $seasonId,
        int $teamId,
        string $lastName,
        string $firstName,
        string $phone = '',
        string $memberId = '',
    ): array {
        $lastName = trim($lastName);
        $firstName = trim($firstName);
        $phone = trim($phone);
        $memberId = trim($memberId);

        if ($lastName === '' || $firstName === '') {
            return self::fail('Both a first and last name are required.');
        }
        if (mb_strlen($lastName) > 80 || mb_strlen($firstName) > 80) {
            return self::fail('That name is too long (80 characters at most).');
        }
        if (mb_strlen($phone) > 40) {
            return self::fail('That phone number is too long.');
        }
        if (mb_strlen($memberId) > 32) {
            return self::fail('That Member ID is too long (32 characters at most).');
        }

        // Outside the transaction: bcrypt at cost 11 is deliberately slow and
        // there is no reason to hold row locks while it runs.
        $pinHash = Pin::hash($this->defaultPin, $this->pinCost);
        $userId = null;

        try {
            $this->db->transaction(function (Database $db) use (
                $lastName, $firstName, $phone, $memberId, $pinHash, $seasonId, $teamId, $actor, &$userId
            ): void {
                $db->execute(
                    'INSERT INTO `user`
                         (member_id, last_name, first_name, phone, phone_e164,
                          pin_hash, role, is_active, is_walkon, created_by)
                     VALUES
                         (:member_id, :last_name, :first_name, :phone, :phone_e164,
                          :pin_hash, :role, 1, 1, :created_by)',
                    [
                        'member_id' => $memberId === '' ? null : $memberId,
                        'last_name' => $lastName,
                        'first_name' => $firstName,
                        'phone' => $phone === '' ? null : $phone,
                        'phone_e164' => PhoneNumber::normalise($phone),
                        'pin_hash' => $pinHash,
                        'role' => 'committeeman',
                        'created_by' => $actor->id,
                    ]
                );
                $userId = $db->lastInsertId();

                $db->execute(
                    'INSERT INTO team_member (user_id, team_id, season_id, created_by)
                     VALUES (:u, :t, :s, :by)',
                    ['u' => $userId, 't' => $teamId, 's' => $seasonId, 'by' => $actor->id]
                );
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return self::fail("Member ID {$memberId} is already in use.");
            }
            throw $e;
        }

        $this->audit->record($actor->id, 'walkon_add', 'user', $userId, null, [
            'team_id' => $teamId, 'season_id' => $seasonId,
        ]);

        return ['ok' => true, 'error' => null, 'id' => $userId];
    }

    /**
     * Reset someone's PIN to the default (spec 6.9.11).
     *
     * Passwords are never displayed because they are never stored in
     * recoverable form. Every existing session for the account stops working,
     * the same as a self-service PIN change: a reset the owner did not ask for
     * is exactly when an open session elsewhere matters.
     *
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function resetPin(Identity $actor, int $teamId, int $seasonId, int $userId): array
    {
        if (!$this->onTeam($userId, $teamId, $seasonId)) {
            return self::fail('That person is not on this team.');
        }

        $hash = Pin::hash($this->defaultPin, $this->pinCost);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->db->transaction(static function (Database $db) use ($userId, $hash, $now): void {
            $db->execute(
                'UPDATE `user` SET pin_hash = :hash, pin_changed_at = :now WHERE id = :id',
                ['hash' => $hash, 'now' => $now, 'id' => $userId]
            );
            $db->execute(
                'UPDATE auth_token SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL',
                ['now' => $now, 'id' => $userId]
            );
        });

        $this->audit->record($actor->id, 'pin_reset', 'user', $userId, null, ['team_id' => $teamId]);

        return ['ok' => true, 'error' => null, 'id' => $userId];
    }

    /**
     * Set what an officer has signed a man off for (spec 7.3).
     *
     * Certified and preferred are independent facts about the same pair, so
     * this writes only the certified half and leaves a preference the man set
     * himself exactly where it was. Certified is granted_at being set, which
     * is why clearing one is an UPDATE to NULL rather than a DELETE — deleting
     * the row would take his preference with it.
     *
     * @param array<int, mixed> $codes skill codes to certify
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function setCertified(Identity $actor, int $teamId, int $seasonId, int $userId, array $codes): array
    {
        if (!$this->onTeam($userId, $teamId, $seasonId)) {
            return self::fail('That person is not on this team.');
        }

        return $this->writeSkills($actor, $userId, $codes, certified: true);
    }

    /**
     * Set what a man would rather do, which only he can say (spec 7.3).
     *
     * A training list nobody had to compile: a preference for something he is
     * not certified for is the useful half.
     *
     * @param array<int, mixed> $codes skill codes to prefer
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    public function setPreferred(Identity $actor, int $userId, array $codes): array
    {
        return $this->writeSkills($actor, $userId, $codes, certified: false);
    }

    /**
     * @param array<int, mixed> $codes
     * @return array{ok: bool, error: ?string, id: ?int}
     */
    private function writeSkills(Identity $actor, int $userId, array $codes, bool $certified): array
    {
        $wanted = [];
        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $wanted[$code] = true;
            }
        }

        // Only position skills can be preferred: Forklift and Golf Cart
        // correspond to no position, so preferring one would mean nothing (7.1).
        $skills = $this->db->all(
            $certified
                ? 'SELECT id, code FROM skill'
                : "SELECT id, code FROM skill WHERE kind = 'position'"
        );

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $granted = [];

        $this->db->transaction(function (Database $db) use (
            $skills, $wanted, $userId, $actor, $now, $certified, &$granted
        ): void {
            foreach ($skills as $skill) {
                $id = (int) $skill['id'];
                $on = isset($wanted[(string) $skill['code']]);

                if ($on) {
                    $granted[] = (string) $skill['code'];
                }

                // One statement either way. INSERT ... ON DUPLICATE KEY UPDATE
                // touches only this half of the row, so the other half — set by
                // the other party — survives untouched.
                //
                // VALUES() rather than the row alias MySQL 8.0.20 prefers:
                // MariaDB does not accept the alias form, and CI runs both
                // engines because the database host on a reseller plan is not
                // ours to control. The deprecation is a warning, not an error.
                if ($certified) {
                    $db->execute(
                        'INSERT INTO user_skill (user_id, skill_id, granted_at, granted_by)
                         VALUES (:user_id, :skill_id, :granted_at, :granted_by)
                         ON DUPLICATE KEY UPDATE granted_at = VALUES(granted_at), granted_by = VALUES(granted_by)',
                        [
                            'user_id' => $userId,
                            'skill_id' => $id,
                            'granted_at' => $on ? $now : null,
                            'granted_by' => $on ? $actor->id : null,
                        ]
                    );
                } else {
                    $db->execute(
                        'INSERT INTO user_skill (user_id, skill_id, granted_at, is_preferred, preferred_at)
                         VALUES (:user_id, :skill_id, NULL, :is_preferred, :preferred_at)
                         ON DUPLICATE KEY UPDATE is_preferred = VALUES(is_preferred),
                                                 preferred_at = VALUES(preferred_at)',
                        [
                            'user_id' => $userId,
                            'skill_id' => $id,
                            'is_preferred' => $on ? 1 : 0,
                            'preferred_at' => $on ? $now : null,
                        ]
                    );
                }
            }

            // A row that now says nothing about this person is not a fact.
            $db->execute(
                'DELETE FROM user_skill
                  WHERE user_id = :user_id AND granted_at IS NULL AND is_preferred = 0',
                ['user_id' => $userId]
            );
        });

        $this->audit->record(
            $actor->id,
            $certified ? 'skills_certified' : 'skills_preferred',
            'user',
            $userId,
            null,
            ['skills' => $granted]
        );

        return ['ok' => true, 'error' => null, 'id' => $userId];
    }

    /** Is this person actually on the team the officer is acting for? */
    private function onTeam(int $userId, int $teamId, int $seasonId): bool
    {
        return (int) $this->db->value(
            "SELECT COUNT(*)
               FROM team_member tm
               JOIN `user` u ON u.id = tm.user_id
              WHERE tm.user_id = :user_id
                AND tm.team_id = :team_id
                AND tm.season_id = :season_id
                AND u.is_active = 1
                AND u.role = 'committeeman'",
            ['user_id' => $userId, 'team_id' => $teamId, 'season_id' => $seasonId]
        ) > 0;
    }

    /** @return array{ok: false, error: string, id: null} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'id' => null];
    }
}
