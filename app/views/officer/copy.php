<?php
/**
 * Copy From Previous Shift (spec 6.9.6).
 *
 * Pick a prior shift, read the preview, confirm. The preview is the point of
 * the screen: an officer needs to know how many holes he is about to be left
 * with before he confirms, not after.
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $sources
 * @var array<string, mixed>|null $source
 * @var array{apply: array, missing: array, blocked: array}|null $preview
 * @var string $copyPhase
 * @var string|null $error
 */

$self = 'officer/copy';
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
$clock = new Resm\ShiftClock($app->displayTimezone());
$action = $app->url($self);
?>
<h1>Copy From Previous Shift</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?= officerHeader($app, get_defined_vars(), $self) ?>

<p class="muted">
    Copying the <strong><?= e(Resm\Officer\PhaseControl::label($copyPhase)) ?></strong> board.
    Only people who are checked in tonight are placed; everybody else's position
    is left open and listed below so you can fill it by hand.
</p>

<?php if ($sources === []): ?>
    <p class="muted">
        This team has no earlier shift with a
        <?= e(Resm\Officer\PhaseControl::label($copyPhase)) ?> board on it yet.
    </p>
    <?php return; ?>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="team" value="<?= e($teamId) ?>">
    <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
    <input type="hidden" name="phase" value="<?= e($copyPhase) ?>">

    <label class="field">
        <span class="field__label">Copy from</span>
        <select class="field__input" name="from_shift">
            <?php foreach ($sources as $option): ?>
                <option value="<?= e($option['id']) ?>"
                    <?= $source !== null && (int) $option['id'] === (int) $source['id'] ? 'selected' : '' ?>>
                    <?= e($clock->display($option['starts_at_utc'])) ?>
                    &middot; <?= e($option['placements']) ?> placed
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <button class="button" type="submit">Show me what this would do</button>
</form>

<?php if ($preview === null): ?>
    <?php return; ?>
<?php endif; ?>

<h2>What this would do</h2>

<div class="coverage" role="group" aria-label="Preview">
    <span class="coverage__item">
        <span class="coverage__figure"><?= count($preview['apply']) ?></span>
        <span class="coverage__label">applied</span>
    </span>
    <span class="coverage__item <?= $preview['missing'] === [] ? '' : 'coverage__item--short' ?>">
        <span class="coverage__figure"><?= count($preview['missing']) ?></span>
        <span class="coverage__label">not in</span>
    </span>
    <span class="coverage__item">
        <span class="coverage__figure"><?= count($preview['blocked']) ?></span>
        <span class="coverage__label">already set</span>
    </span>
</div>

<?php if ($preview['missing'] !== []): ?>
    <h2 class="pinned__heading">Left open — <?= count($preview['missing']) ?></h2>
    <p class="muted">These men are not checked in tonight, so their positions stay vacant.</p>
    <ul class="picks">
        <?php foreach ($preview['missing'] as $row): ?>
            <li>
                <span class="pick <?= (int) $row['is_critical'] === 1 ? 'pick--critical' : '' ?>">
                    <span class="pick__name"><?= e((string) $row['position']) ?></span>
                    <span class="pick__meta">
                        was <?= e((string) $row['list_name']) ?>
                        <?php if ((int) $row['is_critical'] === 1): ?>
                            &middot; <span class="badge badge--danger">CRITICAL</span>
                        <?php endif; ?>
                    </span>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($preview['blocked'] !== []): ?>
    <h2>Already set — <?= count($preview['blocked']) ?></h2>
    <p class="muted">
        Somebody is standing there tonight already, or that man is placed
        elsewhere. A copy adds to a board; it never overwrites one.
    </p>
<?php endif; ?>

<?php if ($preview['apply'] === []): ?>
    <p class="muted">There is nothing to copy across.</p>
<?php else: ?>
    <form method="post" action="<?= e($action) ?>">
        <?= Resm\Csrf::field() ?>
        <input type="hidden" name="team" value="<?= e($teamId) ?>">
        <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
        <input type="hidden" name="phase" value="<?= e($copyPhase) ?>">
        <input type="hidden" name="from_shift" value="<?= e($source['id']) ?>">
        <input type="hidden" name="confirm" value="1">

        <button class="button button--primary check-button" type="submit">
            PLACE <?= count($preview['apply']) ?> <?= count($preview['apply']) === 1 ? 'MAN' : 'MEN' ?>
        </button>
    </form>
<?php endif; ?>
