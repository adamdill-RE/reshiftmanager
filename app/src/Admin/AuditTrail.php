<?php

declare(strict_types=1);

namespace Resm\Admin;

use Resm\Database;

/**
 * The read side of the audit log (spec 6.10.9). Resm\AuditLog writes it; this
 * class only ever SELECTs. The log is append-only and is evidence — there is
 * deliberately no method here, and no route anywhere, that could change a row.
 *
 * Retention (spec 11.5 #7) bounds every query to the five-year window. It is
 * a bound on what is shown, never a delete: rows older than the window are
 * still in the table, waiting for whoever decides to archive them.
 *
 * The screen answers "who moved Johnson off Curve 2 and when", so the query
 * resolves ids to names — the actor, and the person a row is about — rather
 * than sending an administrator off to look them up.
 */
final class AuditTrail
{
    /** Small pages: this is read on a phone, standing up. */
    public const PAGE = 50;

    public function __construct(
        private Database $db,
        private int $retentionYears,
    ) {
    }

    /**
     * One page of entries, newest first, oldest ids past the cursor.
     *
     * @param array{shift: ?int, actor: ?int, action: ?string, before: ?int} $filters
     * @return array{entries: array<int, array<string, mixed>>, more: bool}
     */
    public function entries(array $filters): array
    {
        $where = ['l.occurred_at >= :floor'];
        $params = ['floor' => $this->floor()];

        if ($filters['shift'] !== null) {
            $where[] = 'l.shift_id = :shift';
            $params['shift'] = $filters['shift'];
        }
        if ($filters['actor'] !== null) {
            $where[] = 'l.actor_id = :actor';
            $params['actor'] = $filters['actor'];
        }
        if ($filters['action'] !== null) {
            $where[] = 'l.action = :action';
            $params['action'] = $filters['action'];
        }
        if ($filters['before'] !== null) {
            $where[] = 'l.id < :before';
            $params['before'] = $filters['before'];
        }

        // One more than a page, so "Older" only shows when older exists.
        $rows = $this->db->all(
            'SELECT l.id, l.action, l.entity, l.entity_id, l.shift_id,
                    l.before_json, l.after_json, l.occurred_at,
                    actor.last_name AS actor_last, actor.first_name AS actor_first,
                    subject.last_name AS subject_last, subject.first_name AS subject_first,
                    t.name AS team_name, sh.starts_at AS shift_starts_at
               FROM audit_log l
               LEFT JOIN `user` actor ON actor.id = l.actor_id
               LEFT JOIN `user` subject
                 ON l.entity = \'user\' AND subject.id = l.entity_id
               LEFT JOIN shift sh ON sh.id = l.shift_id
               LEFT JOIN team t ON t.id = sh.team_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY l.id DESC
              LIMIT ' . (self::PAGE + 1),
            $params
        );

        $more = count($rows) > self::PAGE;

        return ['entries' => array_slice($rows, 0, self::PAGE), 'more' => $more];
    }

    /**
     * Every action that appears inside the window, for the filter dropdown.
     * From the data rather than a hand-kept list, so a new call site's action
     * is filterable the day it first writes.
     *
     * @return array<int, string>
     */
    public function actions(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['action'],
            $this->db->all(
                'SELECT DISTINCT action FROM audit_log
                  WHERE occurred_at >= :floor ORDER BY action',
                ['floor' => $this->floor()]
            )
        );
    }

    /**
     * Everyone who has acted inside the window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function actors(): array
    {
        return $this->db->all(
            'SELECT u.id, u.last_name, u.first_name
               FROM `user` u
              WHERE u.id IN (SELECT DISTINCT actor_id FROM audit_log
                              WHERE occurred_at >= :floor AND actor_id IS NOT NULL)
              ORDER BY u.last_name, u.first_name',
            ['floor' => $this->floor()]
        );
    }

    /**
     * Every shift a row points at inside the window, labelled well enough to
     * tell two Saturdays apart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function shifts(): array
    {
        return $this->db->all(
            'SELECT sh.id, sh.starts_at, sh.shift_type, t.name AS team_name
               FROM shift sh
               JOIN team t ON t.id = sh.team_id
              WHERE sh.id IN (SELECT DISTINCT shift_id FROM audit_log
                               WHERE occurred_at >= :floor AND shift_id IS NOT NULL)
              ORDER BY sh.starts_at DESC, t.name',
            ['floor' => $this->floor()]
        );
    }

    /** The oldest instant the screen ranges over, UTC. In PHP, not SQL — four
     *  queries share it per page and none of them needs a fifth round trip
     *  just to learn the date. */
    private function floor(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d years', $this->retentionYears))
            ->format('Y-m-d H:i:s');
    }
}
