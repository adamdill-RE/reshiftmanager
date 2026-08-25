<?php
/**
 * My Shifts, spec 6.6.
 *
 * Every shift in the season, newest first, with the times actually worked.
 * Past shifts collapse: during the season the useful half of this list is the
 * two or three still to come.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $season
 * @var array<int, array<string, mixed>> $upcoming
 * @var array<int, array<string, mixed>> $past
 * @var Resm\ShiftClock $clock
 */

$row = static function (array $shift) use ($clock): string {
    $worked = '';
    if ($shift['first_in'] !== null) {
        $in = new DateTimeImmutable((string) $shift['first_in'], new DateTimeZone('UTC'));
        $worked = 'In ' . $clock->display($in, 'H:i');
        if ($shift['last_out'] !== null) {
            $out = new DateTimeImmutable((string) $shift['last_out'], new DateTimeZone('UTC'));
            $worked .= ' &middot; out ' . $clock->display($out, 'H:i');
        } else {
            $worked .= ' &middot; <span class="badge badge--warn">NO CHECK OUT</span>';
        }
    }

    $starts = new DateTimeImmutable((string) $shift['starts_at'], new DateTimeZone('UTC'));
    $ends = new DateTimeImmutable((string) $shift['ends_at'], new DateTimeZone('UTC'));

    return sprintf(
        '<div class="card"><div class="card__value">%s</div>'
        . '<p class="muted card__note">%s &middot; %s &ndash; %s</p>%s</div>',
        e($clock->display($starts, 'D j M')),
        e((string) $shift['team_name']),
        e($clock->display($starts, 'H:i')),
        e($clock->display($ends, 'H:i')),
        $worked === '' ? '' : '<p class="card__note">' . $worked . '</p>'
    );
};
?>
<h1>My Shifts</h1>

<?php if ($season === null): ?>
    <p class="muted">There is no active season yet.</p>
    <?php return; ?>
<?php endif; ?>

<p class="muted"><?= e((string) $season['name']) ?></p>

<?php if ($upcoming === [] && $past === []): ?>
    <div class="notice">
        <span class="badge">NOTHING SCHEDULED</span>
        <p class="card__note">
            You are not on any shifts this season yet. Shifts arrive when an
            administrator creates them for a team you are on.
        </p>
    </div>
    <?php return; ?>
<?php endif; ?>

<h2><?= count($upcoming) ?> still to come</h2>

<?php if ($upcoming === []): ?>
    <p class="muted">Nothing left this season.</p>
<?php endif; ?>

<?php foreach ($upcoming as $shift): ?>
    <?= $row($shift) ?>
<?php endforeach; ?>

<?php if ($past !== []): ?>
    <hr class="divider">
    <details>
        <summary class="disclosure">Show past shifts (<?= count($past) ?>)</summary>
        <?php foreach ($past as $shift): ?>
            <?= $row($shift) ?>
        <?php endforeach; ?>
    </details>
<?php endif; ?>
