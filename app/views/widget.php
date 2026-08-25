<?php
/**
 * The persistent status strip (spec 6.3).
 *
 * The single most-viewed element in the product, and it has to be legible at
 * arm's length in sunlight, so it says few things in large type rather than
 * many things in small.
 *
 * Rendered only once a user has checked in or out. Before that there is no
 * status to report and a strip saying nothing is a strip people learn to
 * ignore.
 *
 * @var Resm\App $app
 * @var array<string, mixed> $shift
 * @var array<string, mixed>|null $assignment current phase, may be null
 * @var array<string, mixed>|null $broadcast
 * @var bool $doubled on two live shifts at once
 * @var string $renderedAt ISO 8601, for the freshness counter
 */

$in = (bool) $shift['checked_in'];
$phase = (string) $shift['current_phase'];
?>
<div class="widget <?= $in ? 'widget--in' : 'widget--out' ?>" role="status">
    <div class="widget__row">
        <span class="widget__state"><?= $in ? 'CHECKED IN' : 'CHECKED OUT' ?></span>
        <span class="widget__team"><?= e((string) $shift['team_name']) ?></span>
    </div>

    <div class="widget__assignment">
        <?php if ($assignment !== null): ?>
            <?= e((string) $assignment['position']) ?>
            <?php if ((int) $assignment['is_critical'] === 1): ?>
                <span class="badge badge--danger">CRITICAL</span>
            <?php endif; ?>
        <?php elseif ($in): ?>
            <span class="muted">No position yet</span>
        <?php else: ?>
            <span class="muted">Not on the board</span>
        <?php endif; ?>
    </div>

    <div class="widget__row widget__meta">
        <span><?= $phase === 'bump_run' ? 'Bump and Run' : 'Unload' ?></span>
        <?php if ((string) $shift['lunch'] === 'at_lunch'): ?>
            <span class="badge badge--warn">AT LUNCH</span>
        <?php elseif ((string) $shift['lunch'] === 'done'): ?>
            <span class="badge">LUNCH DONE</span>
        <?php endif; ?>
        <span class="widget__fresh" data-rendered-at="<?= e($renderedAt) ?>">just now</span>
    </div>

    <?php if ($doubled): ?>
        <p class="widget__note">
            You are checked into two shifts at once. Check out of the one you
            have finished.
        </p>
    <?php endif; ?>
</div>

<?php if ($broadcast !== null): ?>
    <p class="broadcast"><?= e((string) $broadcast['body']) ?></p>
<?php endif; ?>
