<?php

declare(strict_types=1);

namespace Resm\Admin;

use DateTimeImmutable;
use PDOException;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Database;

/**
 * Seasons (spec 6.10.1).
 *
 * A season wraps every piece of operational data — rosters, teams, shifts,
 * assignments, check-ins — so 2027 does not mix with 2026 and a finished year
 * archives cleanly rather than accumulating.
 *
 * Exactly one season is active at a time. That is an invariant, not a
 * convention: almost every other screen resolves "the current season" by
 * reading it, and two actives would make that question ambiguous everywhere at
 * once.
 */
final class Seasons
{
    public function __construct(
        private Database $db,
        private AuditLog $audit,
    ) {
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function all(): array
    {
        return $this->db->all(
            'SELECT s.id, s.name, s.start_date, s.end_date, s.is_active,
                    (SELECT COUNT(*) FROM team t WHERE t.season_id = s.id) AS team_count
             FROM season s
             ORDER BY s.start_date DESC, s.id DESC'
        );
    }

    /** @return array<string, mixed>|null */
    public function active(): ?array
    {
        return $this->db->one('SELECT id, name, start_date, end_date FROM season WHERE is_active = 1 LIMIT 1');
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function create(Identity $actor, string $name, string $startDate, string $endDate): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'A season needs a name.'];
        }
        if (mb_strlen($name) > 80) {
            return ['ok' => false, 'error' => 'That name is too long (80 characters at most).'];
        }

        $start = self::parseDate($startDate);
        $end = self::parseDate($endDate);
        if ($start === null || $end === null) {
            return ['ok' => false, 'error' => 'Both dates must be real dates, as YYYY-MM-DD.'];
        }
        if ($end < $start) {
            return ['ok' => false, 'error' => 'The season cannot end before it starts.'];
        }

        try {
            $this->db->execute(
                'INSERT INTO season (name, start_date, end_date, is_active, created_by)
                 VALUES (:name, :start_date, :end_date, 0, :created_by)',
                [
                    'name' => $name,
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end->format('Y-m-d'),
                    'created_by' => $actor->id,
                ]
            );
        } catch (PDOException $e) {
            // The unique key on name is the only constraint a user can trip.
            if (self::isDuplicate($e)) {
                return ['ok' => false, 'error' => "A season called \"{$name}\" already exists."];
            }
            throw $e;
        }

        $id = $this->db->lastInsertId();
        $this->audit->record($actor->id, 'season_create', 'season', $id, null, [
            'name' => $name,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Make one season the active one, and every other season not.
     *
     * Both statements are one transaction, because the moment between them has
     * no active season at all and a request landing there would find nothing.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function activate(Identity $actor, int $seasonId): array
    {
        $season = $this->db->one('SELECT id, name, is_active FROM season WHERE id = :id', ['id' => $seasonId]);
        if ($season === null) {
            return ['ok' => false, 'error' => 'That season no longer exists.'];
        }
        if ((int) $season['is_active'] === 1) {
            return ['ok' => true, 'error' => null];
        }

        $previous = $this->active();

        $this->db->transaction(static function (Database $db) use ($seasonId): void {
            $db->execute('UPDATE season SET is_active = 0 WHERE is_active = 1');
            $db->execute('UPDATE season SET is_active = 1 WHERE id = :id', ['id' => $seasonId]);
        });

        $this->audit->record(
            $actor->id,
            'season_activate',
            'season',
            $seasonId,
            $previous === null ? null : ['active' => $previous['name']],
            ['active' => $season['name']]
        );

        return ['ok' => true, 'error' => null];
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        // createFromFormat accepts 2026-02-31 and rolls it forward, so compare
        // the round trip rather than trusting it parsed.
        if ($date === false || ($errors !== false && ($errors['warning_count'] ?? 0) > 0)) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
