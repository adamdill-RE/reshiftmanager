<?php

declare(strict_types=1);

namespace Resm\Admin;

use PDOException;
use Resm\AuditLog;
use Resm\Auth\Identity;
use Resm\Auth\Pin;
use Resm\Auth\Role;
use Resm\Csv;
use Resm\Database;
use Resm\PhoneNumber;

/**
 * Roster import (spec 6.10.3).
 *
 * The import never commits on upload. plan() reads the file and says what
 * would happen; commit() re-reads the SAME file and does it. Re-reading rather
 * than carrying a parsed structure between the two requests is deliberate:
 * there is then no way for the summary the Admin approved to describe one
 * thing while the write does another.
 *
 * Matching is on Member ID, never email — spouses share an address, so it is
 * not a key. Officer and Admin rows are reported and left alone: a roster
 * spreadsheet is not the place someone's role quietly changes.
 */
final class RosterImport
{
    /**
     * A fat-finger stop, not a business rule. upload_max_filesize is 2M on the
     * server, which is thousands of rows; a season roster is 150.
     */
    public const MAX_ROWS = 2000;

    /** Spec 6.10.3 column order, used when the file has no header row. */
    private const ORDER = ['last_name', 'first_name', 'member_id', 'phone', 'email', 'team'];

    /** @var array<string, string> normalised header text to column */
    private const ALIASES = [
        'lastname' => 'last_name', 'last' => 'last_name', 'surname' => 'last_name',
        'firstname' => 'first_name', 'first' => 'first_name', 'givenname' => 'first_name',
        'memberid' => 'member_id', 'member' => 'member_id', 'id' => 'member_id',
        'membernumber' => 'member_id', 'memberno' => 'member_id',
        'phone' => 'phone', 'phonenumber' => 'phone', 'mobile' => 'phone', 'cell' => 'phone',
        'email' => 'email', 'emailaddress' => 'email', 'mail' => 'email',
        'team' => 'team', 'teamname' => 'team',
    ];

    /** @var array<string, int> lowercased team name to id, filled by teamsInSeason() */
    private array $teamIds = [];

    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private int $pinCost,
        private string $defaultPin,
    ) {
    }

    /**
     * What this file would do, without doing any of it.
     *
     * @return array{
     *     ok: bool, error: ?string,
     *     rows: array<int, array<string, mixed>>,
     *     counts: array<string, int>,
     *     warnings: array<int, string>,
     *     teams: array<int, string>
     * }
     */
    public function plan(string $content, int $seasonId): array
    {
        $teams = $this->teamsInSeason($seasonId);
        $parsed = Csv::rows($content);

        if ($parsed === []) {
            return self::planFail('That file has no rows in it.', $teams);
        }
        if (count($parsed) > self::MAX_ROWS) {
            return self::planFail(
                sprintf('That file has %d rows; the limit is %d.', count($parsed), self::MAX_ROWS),
                $teams
            );
        }

        $map = self::headerMap($parsed[0]['cells']);
        if ($map !== null) {
            array_shift($parsed);
            if ($parsed === []) {
                return self::planFail('That file has a header row and nothing else.', $teams);
            }
        }

        $warnings = [];
        $rows = [];
        $seen = [];

        foreach ($parsed as $raw) {
            $row = self::readRow($raw['line'], $raw['cells'], $map);

            $problem = self::validate($row, $teams);
            if ($problem !== null) {
                $row['action'] = 'error';
                $row['reason'] = $problem;
                $rows[] = $row;
                continue;
            }

            $key = $row['member_id'];

            // Spec 6.10.3: a Member ID repeated inside one file resolves to the
            // last row, with a warning. The earlier row is dropped from the
            // plan entirely so the summary counts it once.
            if (isset($seen[$key])) {
                $warnings[] = sprintf(
                    'Member ID %s appears on lines %d and %d. Line %d wins.',
                    $key,
                    $rows[$seen[$key]]['line'],
                    $row['line'],
                    $row['line']
                );
                unset($rows[$seen[$key]]);
            }

            $rows[] = $row;
            $seen[$key] = array_key_last($rows);
        }

        $rows = array_values($rows);
        $this->classify($rows, $teams);

        return [
            'ok' => true,
            'error' => null,
            'rows' => $rows,
            'counts' => self::tally($rows),
            'warnings' => $warnings,
            'teams' => array_values($teams),
        ];
    }

    /**
     * Apply a plan built from the same content.
     *
     * @return array{ok: bool, error: ?string, counts: array<string, int>}
     */
    public function commit(Identity $actor, string $content, int $seasonId): array
    {
        $plan = $this->plan($content, $seasonId);
        if (!$plan['ok']) {
            return ['ok' => false, 'error' => $plan['error'], 'counts' => self::tally([])];
        }

        /*
         * One hash for every account this import creates, computed once.
         *
         * bcrypt at cost 11 measures ~130ms, and a first import is 150 people:
         * hashing per row is 20 seconds against a 30-second max_execution_time
         * (docs/hosting.md), which times out halfway and leaves half a roster.
         *
         * Sharing the hash is safe here in a way it would never be for real
         * passwords. Every one of these accounts is being created with the
         * same value — 1234, the published default from spec 3.1 — so a
         * distinct salt per row protects nothing: an attacker who knows the
         * default already knows every one of these PINs without computing
         * anything. The salt starts mattering the moment a PIN stops being the
         * default, and changing one calls password_hash again and gets a fresh
         * one.
         */
        $newPinHash = Pin::hash($this->defaultPin, $this->pinCost);

        $counts = ['new' => 0, 'update' => 0, 'reactivate' => 0, 'skip' => 0, 'error' => 0];

        try {
            $this->db->transaction(function (Database $db) use (
                $plan, $newPinHash, $seasonId, $actor, &$counts
            ): void {
                foreach ($plan['rows'] as $row) {
                    match ($row['action']) {
                        'new' => $this->insertRow($db, $row, $newPinHash, $seasonId, $actor, $counts),
                        'update', 'reactivate' => $this->updateRow($db, $row, $seasonId, $actor, $counts),
                        default => $counts[$row['action']]++,
                    };
                }
            });
        } catch (PDOException $e) {
            // A Member ID that appeared between the dry run and the confirm.
            // Rare, and the whole transaction is already rolled back.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return [
                    'ok' => false,
                    'error' => 'Someone was created with one of these Member IDs while you were reviewing. '
                        . 'Run the dry run again.',
                    'counts' => self::tally([]),
                ];
            }
            throw $e;
        }

        $this->audit->record($actor->id, 'roster_import', 'season', $seasonId, null, $counts);

        return ['ok' => true, 'error' => null, 'counts' => $counts];
    }

    /**
     * The error report the Admin downloads (spec 6.10.3).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function errorReport(array $rows): string
    {
        $out = Csv::line(['Line', 'Member_ID', 'Lastname', 'Firstname', 'Team', 'Problem']);

        foreach ($rows as $row) {
            if ($row['action'] !== 'error' && $row['action'] !== 'skip') {
                continue;
            }

            $out .= Csv::line([
                $row['line'],
                $row['member_id'],
                $row['last_name'],
                $row['first_name'],
                $row['team'],
                (string) ($row['reason'] ?? ''),
            ]);
        }

        return $out;
    }

    // -----------------------------------------------------------------------

    /**
     * Column positions from a header row, or null when the file has none.
     *
     * @param array<int, string> $cells
     * @return array<string, int>|null
     */
    private static function headerMap(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $index => $cell) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $cell));
            if ($key !== '' && isset(self::ALIASES[$key])) {
                $map[self::ALIASES[$key]] = $index;
            }
        }

        // One recognised word could be a surname. Two is a header.
        return count($map) >= 2 ? $map : null;
    }

    /**
     * @param array<int, string> $cells
     * @param array<string, int>|null $map
     * @return array<string, mixed>
     */
    private static function readRow(int $line, array $cells, ?array $map): array
    {
        $at = static function (string $column) use ($cells, $map): string {
            $index = $map === null
                ? array_search($column, self::ORDER, true)
                : ($map[$column] ?? null);

            return is_int($index) ? ($cells[$index] ?? '') : '';
        };

        $phone = $at('phone');

        return [
            'line' => $line,
            'member_id' => $at('member_id'),
            'last_name' => $at('last_name'),
            'first_name' => $at('first_name'),
            'phone' => $phone,
            'phone_e164' => PhoneNumber::normalise($phone),
            'email' => $at('email'),
            'team' => $at('team'),
            'action' => 'new',
            'reason' => null,
            'user_id' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $teams lowercased name to name
     */
    private static function validate(array $row, array $teams): ?string
    {
        if ($row['member_id'] === '') {
            return 'No Member ID. It is how this person signs in.';
        }
        if (mb_strlen((string) $row['member_id']) > 32) {
            return 'Member ID is longer than 32 characters.';
        }
        if ($row['last_name'] === '' || $row['first_name'] === '') {
            return 'Both a first and last name are required.';
        }
        if (mb_strlen((string) $row['last_name']) > 80 || mb_strlen((string) $row['first_name']) > 80) {
            return 'A name is longer than 80 characters.';
        }
        if (mb_strlen((string) $row['phone']) > 40) {
            return 'Phone number is longer than 40 characters.';
        }
        if ($row['email'] !== '') {
            if (mb_strlen((string) $row['email']) > 190) {
                return 'Email address is longer than 190 characters.';
            }
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
                return 'That email address does not look right.';
            }
        }
        if ($row['team'] !== '' && !isset($teams[strtolower((string) $row['team'])])) {
            return sprintf(
                'No active team called "%s" in this season. Known teams: %s.',
                $row['team'],
                $teams === [] ? 'none yet' : implode(', ', array_values($teams))
            );
        }

        return null;
    }

    /**
     * Decide new / update / reactivate / skip against what is already there.
     *
     * One query for the whole file rather than one per row: a 150-row import
     * would otherwise be 150 round trips to a database on a different host.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $teams
     */
    private function classify(array &$rows, array $teams): void
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row['action'] !== 'error') {
                $ids[] = (string) $row['member_id'];
            }
        }
        if ($ids === []) {
            return;
        }

        $names = [];
        $params = [];
        foreach (array_values(array_unique($ids)) as $i => $id) {
            $names[] = ':m' . $i;
            $params['m' . $i] = $id;
        }

        $existing = [];
        foreach (
            $this->db->all(
                'SELECT id, member_id, role, is_active FROM `user` WHERE member_id IN (' . implode(', ', $names) . ')',
                $params
            ) as $found
        ) {
            $existing[(string) $found['member_id']] = $found;
        }

        foreach ($rows as &$row) {
            if ($row['action'] === 'error') {
                continue;
            }

            $match = $existing[(string) $row['member_id']] ?? null;
            if ($match === null) {
                $row['action'] = 'new';
                continue;
            }

            $row['user_id'] = (int) $match['id'];

            // Spec 6.10.3: Officers and Admins are never modified by import.
            if (Role::from((string) $match['role']) !== Role::Committeeman) {
                $row['action'] = 'skip';
                $row['reason'] = sprintf(
                    'Already an %s. Import never changes an Officer or Admin.',
                    (string) $match['role']
                );
                continue;
            }

            // Being on this year's roster is what makes someone active again.
            // Counted separately so the dry run shows it rather than doing it
            // quietly.
            $row['action'] = (int) $match['is_active'] === 1 ? 'update' : 'reactivate';
        }
        unset($row);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $counts
     */
    private function insertRow(
        Database $db,
        array $row,
        string $pinHash,
        int $seasonId,
        Identity $actor,
        array &$counts,
    ): void {
        $db->execute(
            'INSERT INTO `user`
                (member_id, last_name, first_name, phone, phone_e164, email,
                 pin_hash, role, is_active, is_walkon, created_by)
             VALUES
                (:member_id, :last_name, :first_name, :phone, :phone_e164, :email,
                 :pin_hash, :role, 1, 0, :created_by)',
            [
                'member_id' => $row['member_id'],
                'last_name' => $row['last_name'],
                'first_name' => $row['first_name'],
                'phone' => $row['phone'] === '' ? null : $row['phone'],
                'phone_e164' => $row['phone_e164'],
                'email' => $row['email'] === '' ? null : $row['email'],
                'pin_hash' => $pinHash,
                'role' => Role::Committeeman->value,
                'created_by' => $actor->id,
            ]
        );

        $this->assignTeam($db, $db->lastInsertId(), $row, $seasonId, $actor);
        $counts['new']++;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $counts
     */
    private function updateRow(
        Database $db,
        array $row,
        int $seasonId,
        Identity $actor,
        array &$counts,
    ): void {
        $userId = (int) $row['user_id'];

        $db->execute(
            'UPDATE `user`
                SET last_name = :last_name, first_name = :first_name,
                    phone = :phone, phone_e164 = :phone_e164, email = :email,
                    is_active = 1
             WHERE id = :id',
            [
                'last_name' => $row['last_name'],
                'first_name' => $row['first_name'],
                'phone' => $row['phone'] === '' ? null : $row['phone'],
                'phone_e164' => $row['phone_e164'],
                'email' => $row['email'] === '' ? null : $row['email'],
                'id' => $userId,
            ]
        );

        $this->assignTeam($db, $userId, $row, $seasonId, $actor);
        $counts[$row['action']]++;
    }

    /**
     * Put this person on the named team for this season.
     *
     * Additive: a blank Team column leaves existing membership alone rather
     * than clearing it, because a roster that omits the column is not a
     * statement that nobody has a team.
     *
     * @param array<string, mixed> $row
     */
    private function assignTeam(
        Database $db,
        int $userId,
        array $row,
        int $seasonId,
        Identity $actor,
    ): void {
        if ($row['team'] === '') {
            return;
        }

        $teamId = $this->teamIds[strtolower((string) $row['team'])] ?? null;
        if ($teamId === null) {
            return;
        }

        $db->execute(
            // Re-importing the same roster puts people on the teams they are
            // already on. ON DUPLICATE KEY with a self-assignment absorbs that
            // collision and nothing else — INSERT IGNORE would also swallow a
            // broken foreign key and leave the person quietly teamless.
            'INSERT INTO team_member (user_id, team_id, season_id, created_by)
             VALUES (:user_id, :team_id, :season_id, :created_by)
             ON DUPLICATE KEY UPDATE user_id = user_id',
            [
                'user_id' => $userId,
                'team_id' => $teamId,
                'season_id' => $seasonId,
                'created_by' => $actor->id,
            ]
        );
    }

    /**
     * Active teams in the season, lowercased name to display name. The ids are
     * kept alongside for the writer.
     *
     * @return array<string, string>
     */
    private function teamsInSeason(int $seasonId): array
    {
        $names = [];
        $this->teamIds = [];

        foreach (
            $this->db->all(
                'SELECT id, name FROM team WHERE season_id = :season_id AND is_active = 1 ORDER BY name',
                ['season_id' => $seasonId]
            ) as $team
        ) {
            $key = strtolower((string) $team['name']);
            $names[$key] = (string) $team['name'];
            $this->teamIds[$key] = (int) $team['id'];
        }

        return $names;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function tally(array $rows): array
    {
        $counts = ['new' => 0, 'update' => 0, 'reactivate' => 0, 'skip' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<string, string> $teams
     * @return array{ok: false, error: string, rows: array<int, mixed>, counts: array<string, int>, warnings: array<int, string>, teams: array<int, string>}
     */
    private static function planFail(string $message, array $teams): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'rows' => [],
            'counts' => self::tally([]),
            'warnings' => [],
            'teams' => array_values($teams),
        ];
    }
}
