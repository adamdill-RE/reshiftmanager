<?php

declare(strict_types=1);

namespace Resm\Admin;

use PDOException;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * Teams (spec 6.10.2).
 *
 * Teams live inside a season, so every operation here takes the season it
 * belongs to rather than assuming one. Names are unique within a season, not
 * globally: "Team A" recurs every year and each year's is a different team
 * with its own roster and its own shifts.
 *
 * Teams are deactivated, never deleted. Shifts, assignments and check-in
 * history point at them, and a season is worth nothing as a record if last
 * year's team can vanish from under it.
 */
final class Teams
{
    public function __construct(
        private Database $db,
        private AuditLog $audit,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function forSeason(int $seasonId): array
    {
        return $this->db->all(
            'SELECT t.id, t.name, t.is_active,
                    (SELECT COUNT(*) FROM team_member tm WHERE tm.team_id = t.id) AS member_count,
                    (SELECT COUNT(*) FROM shift sh WHERE sh.team_id = t.id) AS shift_count
             FROM team t
             WHERE t.season_id = :season_id
             ORDER BY t.is_active DESC, t.name',
            ['season_id' => $seasonId]
        );
    }

    /** @return array{ok: bool, error: ?string} */
    public function create(Identity $actor, int $seasonId, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'A team needs a name.'];
        }
        if (mb_strlen($name) > 80) {
            return ['ok' => false, 'error' => 'That name is too long (80 characters at most).'];
        }

        try {
            $this->db->execute(
                'INSERT INTO team (season_id, name, is_active, created_by)
                 VALUES (:season_id, :name, 1, :created_by)',
                ['season_id' => $seasonId, 'name' => $name, 'created_by' => $actor->id]
            );
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return ['ok' => false, 'error' => "This season already has a team called \"{$name}\"."];
            }
            throw $e;
        }

        $id = $this->db->lastInsertId();
        $this->audit->record($actor->id, 'team_create', 'team', $id, null, [
            'season_id' => $seasonId,
            'name' => $name,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok: bool, error: ?string} */
    public function rename(Identity $actor, int $teamId, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'A team needs a name.'];
        }

        $team = $this->db->one('SELECT id, name FROM team WHERE id = :id', ['id' => $teamId]);
        if ($team === null) {
            return ['ok' => false, 'error' => 'That team no longer exists.'];
        }
        if ((string) $team['name'] === $name) {
            return ['ok' => true, 'error' => null];
        }

        try {
            $this->db->execute('UPDATE team SET name = :name WHERE id = :id', ['name' => $name, 'id' => $teamId]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return ['ok' => false, 'error' => "This season already has a team called \"{$name}\"."];
            }
            throw $e;
        }

        $this->audit->record(
            $actor->id,
            'team_rename',
            'team',
            $teamId,
            ['name' => $team['name']],
            ['name' => $name]
        );

        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok: bool, error: ?string} */
    public function setActive(Identity $actor, int $teamId, bool $active): array
    {
        $team = $this->db->one('SELECT id, name, is_active FROM team WHERE id = :id', ['id' => $teamId]);
        if ($team === null) {
            return ['ok' => false, 'error' => 'That team no longer exists.'];
        }

        $this->db->execute(
            'UPDATE team SET is_active = :active WHERE id = :id',
            ['active' => $active ? 1 : 0, 'id' => $teamId]
        );

        $this->audit->record(
            $actor->id,
            $active ? 'team_activate' : 'team_deactivate',
            'team',
            $teamId,
            ['is_active' => (int) $team['is_active']],
            ['is_active' => $active ? 1 : 0]
        );

        return ['ok' => true, 'error' => null];
    }
}
