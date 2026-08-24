<?php

declare(strict_types=1);

namespace Resm;

/**
 * Append-only audit trail (spec 6.10.9).
 *
 * Written on every assignment, phase flip, check event, PIN reset and import.
 * The question it exists to answer is "who moved Johnson off Curve 2 and
 * when", which means the actor and the before state matter as much as the
 * after — a row saying only what the value became is not an audit.
 */
final class AuditLog
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?int $actorId,
        string $action,
        string $entity,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?int $shiftId = null,
    ): void {
        $this->db->execute(
            'INSERT INTO audit_log (actor_id, action, entity, entity_id, shift_id, before_json, after_json, occurred_at)
             VALUES (:actor_id, :action, :entity, :entity_id, :shift_id, :before_json, :after_json, :occurred_at)',
            [
                'actor_id'    => $actorId,
                'action'      => $action,
                'entity'      => $entity,
                'entity_id'   => $entityId,
                'shift_id'    => $shiftId,
                'before_json' => $before === null ? null : self::encode($before),
                'after_json'  => $after === null ? null : self::encode($after),
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
