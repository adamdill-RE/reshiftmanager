<?php
/**
 * Position-first, second tap (spec 6.9.4): a vacancy was tapped, and this is
 * the sheet of people who could stand on it.
 *
 * Everyone checked in and unplaced is offered. The skills beside each name are
 * information, not a gate — nothing is filtered out for lacking one and
 * nothing asks "assign anyway?" (7.4).
 *
 * @var Resm\App $app
 * @var array<string, mixed> $position
 * @var array<int, array<string, mixed>> $available
 * @var array<int, array<string, mixed>> $chips
 * @var array{search: string, skill: string} $filters
 * @var string $phase
 * @var string $mode
 */

$self = 'officer/assign/' . $phase;
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
$action = $app->url($self);
$backToBoard = $action . Resm\Officer\OfficerMenu::query($teamId, $shiftId, 'mode=' . rawurlencode($mode));
?>
<h1><?= e($position['label']) ?></h1>

<p class="muted">
    <?= e($position['group_label']) ?>
    <?php if ($position['is_critical']): ?>
        &middot; <span class="badge badge--danger">CRITICAL</span>
    <?php endif; ?>
    <?php if ($position['is_radio']): ?> &middot; radio<?php endif; ?>
    <?php if ($position['skill_label'] !== null): ?>
        &middot; calls for <?= e($position['skill_label']) ?>
    <?php endif; ?>
    <?php if ($position['multi_assign']): ?> &middot; takes more than one<?php endif; ?>
</p>

<?php if ($position['holders'] !== []): ?>
    <h2>Standing here now</h2>
    <ul class="picks">
        <?php foreach ($position['holders'] as $holder): ?>
            <li>
                <form class="pick pick--filled" method="post" action="<?= e($action) ?>">
                    <?= Resm\Csrf::field() ?>
                    <input type="hidden" name="team" value="<?= e($teamId) ?>">
                    <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
                    <input type="hidden" name="mode" value="<?= e($mode) ?>">
                    <input type="hidden" name="action" value="vacate">
                    <input type="hidden" name="position_id" value="<?= e($position['id']) ?>">
                    <input type="hidden" name="user_id" value="<?= e($holder['user_id']) ?>">
                    <span class="pick__name">
                        <?= e($holder['list_name']) ?>
                        <?php if ($holder['is_inherited']): ?><span class="badge">CARRIED</span><?php endif; ?>
                    </span>
                    <button class="button button--quiet" type="submit">Take off</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($position['holders'] !== [] && !$position['multi_assign']): ?>
    <p class="muted">
        Only the Unload group holds more than one person. Take this one off
        first, or put the man you want on a different position.
    </p>
<?php else: ?>
    <h2>Put someone here</h2>

    <?= (new Resm\View($app))->render('officer/filters', [
        'app' => $app, 'chips' => $chips, 'filters' => $filters,
        'action' => $action, 'teamId' => $teamId, 'shiftId' => $shiftId, 'mode' => $mode,
    ], layout: null) ?>

    <?php if ($available === []): ?>
        <p class="muted">Nobody checked in is waiting for a position.</p>
    <?php else: ?>
        <ul class="picks">
            <?php foreach ($available as $person): ?>
                <li>
                    <form method="post" action="<?= e($action) ?>">
                        <?= Resm\Csrf::field() ?>
                        <input type="hidden" name="team" value="<?= e($teamId) ?>">
                        <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
                        <input type="hidden" name="mode" value="<?= e($mode) ?>">
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="position_id" value="<?= e($position['id']) ?>">
                        <input type="hidden" name="user_id" value="<?= e($person['id']) ?>">
                        <button class="pick pick--button" type="submit">
                            <span class="pick__name"><?= e($person['list_name']) ?></span>
                            <?= (new Resm\View($app))->render('officer/person-chips', [
                                'app' => $app, 'person' => $person,
                            ], layout: null) ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

<p><a class="button button--quiet" href="<?= e($backToBoard) ?>">Back to the board</a></p>
