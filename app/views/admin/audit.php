<?php
/**
 * Audit Log, spec 6.10.9. Read-only by construction: the only form here is a
 * GET, and no POST route for this screen exists.
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $entries
 * @var bool $more
 * @var array<int, string> $actions
 * @var array<int, array<string, mixed>> $actors
 * @var array<int, array<string, mixed>> $shifts
 * @var array{shift: ?int, actor: ?int, action: ?string, before: ?int} $filters
 * @var int $retentionYears
 */

use Resm\ShiftType;

$clock = static function (string $utc) use ($app): DateTimeImmutable {
    return $app->forDisplay(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
};

$name = static function (?string $last, ?string $first, string $fallback): string {
    return $last === null || $last === '' ? $fallback : $last . ', ' . $first;
};

/**
 * before/after JSON as "key: was → is" lines. Generic on purpose: every call
 * site's payload renders without this screen knowing about it, and a new
 * action added later is legible the day it first writes.
 *
 * @return array<int, string>
 */
$detail = static function (?string $beforeJson, ?string $afterJson): array {
    $before = $beforeJson === null ? [] : (array) json_decode($beforeJson, true);
    $after = $afterJson === null ? [] : (array) json_decode($afterJson, true);

    $flat = static fn (mixed $v): string => is_scalar($v) || $v === null
        ? (string) json_encode($v, JSON_UNESCAPED_SLASHES)
        : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $lines = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
        $was = array_key_exists($key, $before) ? $flat($before[$key]) : null;
        $is = array_key_exists($key, $after) ? $flat($after[$key]) : null;

        $lines[] = match (true) {
            $was === null => $key . ': ' . $is,
            $is === null => $key . ': was ' . $was,
            default => $key . ': ' . $was . ' → ' . $is,
        };
    }

    return $lines;
};

$query = static function (array $overrides) use ($app, $filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn (mixed $v): bool => $v !== null && $v !== ''
    );

    return $app->url('admin/audit' . ($params === [] ? '' : '?' . http_build_query($params)));
};
?>
<h1>Audit Log</h1>

<p class="muted">
    Every assignment change, phase flip, check event, PIN reset and import —
    the last <?= (int) $retentionYears ?> years of it, newest first. Read-only:
    this is the record, and nothing here can change it.
</p>

<form method="get" action="<?= e($app->url('admin/audit')) ?>">
    <div class="field">
        <label class="field__label" for="f-shift">Shift</label>
        <select class="field__input" id="f-shift" name="shift">
            <option value="">Any shift</option>
            <?php foreach ($shifts as $option): ?>
                <option value="<?= (int) $option['id'] ?>"
                    <?= $filters['shift'] === (int) $option['id'] ? 'selected' : '' ?>>
                    <?= e($clock((string) $option['starts_at'])->format('D M j, Y')) ?> ·
                    <?= e(ShiftType::from((string) $option['shift_type'])->label()) ?> ·
                    <?= e($option['team_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="field__label" for="f-actor">Actor</label>
        <select class="field__input" id="f-actor" name="actor">
            <option value="">Anyone</option>
            <?php foreach ($actors as $option): ?>
                <option value="<?= (int) $option['id'] ?>"
                    <?= $filters['actor'] === (int) $option['id'] ? 'selected' : '' ?>>
                    <?= e($option['last_name']) ?>, <?= e($option['first_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="field__label" for="f-action">Action</label>
        <select class="field__input" id="f-action" name="action">
            <option value="">Any action</option>
            <?php foreach ($actions as $option): ?>
                <option value="<?= e($option) ?>" <?= $filters['action'] === $option ? 'selected' : '' ?>>
                    <?= e($option) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <button class="button button--primary" type="submit">Filter</button>
    <?php if ($filters['shift'] !== null || $filters['actor'] !== null || $filters['action'] !== null): ?>
        <a class="button button--quiet" href="<?= e($app->url('admin/audit')) ?>">Clear</a>
    <?php endif; ?>
</form>

<hr class="divider">

<?php if ($entries === []): ?>
    <p class="muted">Nothing matches. Loosen a filter, or nothing has happened yet.</p>
<?php endif; ?>

<?php foreach ($entries as $entry): ?>
    <?php
        $after = $entry['after_json'] === null ? [] : (array) json_decode((string) $entry['after_json'], true);
        $offline = ($after['source'] ?? null) === 'offline_sync';
    ?>
    <div class="card">
        <div class="card__label">
            <?= e($clock((string) $entry['occurred_at'])->format('D M j, Y H:i:s')) ?>
            <?php if ($offline): ?>
                · <span class="badge badge--warn">OFFLINE REPLAY</span>
            <?php endif; ?>
        </div>
        <div class="card__value"><?= e($entry['action']) ?></div>
        <p class="card__note">
            <?= e($name($entry['actor_last'], $entry['actor_first'], 'System')) ?>
            <?php if ($entry['entity'] === 'user' && $entry['subject_last'] !== null): ?>
                · about <?= e($entry['subject_last']) ?>, <?= e($entry['subject_first']) ?>
            <?php elseif ($entry['entity_id'] !== null): ?>
                · <?= e($entry['entity']) ?> #<?= (int) $entry['entity_id'] ?>
            <?php else: ?>
                · <?= e($entry['entity']) ?>
            <?php endif; ?>
            <?php if ($entry['shift_id'] !== null && $entry['team_name'] !== null): ?>
                · <?= e($entry['team_name']) ?>,
                <?= e($clock((string) $entry['shift_starts_at'])->format('M j')) ?>
            <?php endif; ?>
        </p>
        <?php $lines = $detail($entry['before_json'], $entry['after_json']); ?>
        <?php if ($lines !== []): ?>
            <p class="muted card__note audit-detail">
                <?php foreach ($lines as $line): ?>
                    <?= e($line) ?><br>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($more && $entries !== []): ?>
    <p>
        <a class="button button--quiet"
           href="<?= e($query(['before' => (int) end($entries)['id']])) ?>">
            Older entries
        </a>
    </p>
<?php endif; ?>
