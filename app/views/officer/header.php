<?php
/**
 * The strip every officer screen carries: which team and shift is being
 * looked at, the phase toggle (spec 6.9.1) and the coverage counter (6.9.2).
 *
 * Rendered by each officer screen rather than by the layout, because it is
 * meaningless anywhere else and the layout already carries the committeeman's
 * status widget.
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $teams
 * @var array<string, mixed>|null $team
 * @var array<int, array<string, mixed>> $shifts
 * @var array<string, mixed>|null $shift
 * @var string $phase
 * @var array<string, mixed>|null $coverage
 * @var Resm\Auth\Identity $user
 * @var string $self this screen's own path, for the selector to post back to
 */

$clock = new Resm\ShiftClock($app->displayTimezone());
$self = $self ?? 'officer';
$teamId = $team === null ? null : (int) $team['id'];
$shiftId = $shift === null ? null : (int) $shift['id'];
?>

<?php if (count($teams) > 1 || count($shifts) > 1): ?>
    <form class="picker" method="get" action="<?= e($app->url($self)) ?>">
        <?php if (count($teams) > 1): ?>
            <label class="field">
                <span class="field__label">Team</span>
                <select class="field__input" name="team" data-autosubmit>
                    <?php foreach ($teams as $option): ?>
                        <option value="<?= e($option['id']) ?>"
                            <?= (int) $option['id'] === $teamId ? 'selected' : '' ?>>
                            <?= e($option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php else: ?>
            <input type="hidden" name="team" value="<?= e($teamId) ?>">
        <?php endif; ?>

        <?php if (count($shifts) > 1): ?>
            <label class="field">
                <span class="field__label">Shift</span>
                <select class="field__input" name="shift" data-autosubmit>
                    <?php foreach ($shifts as $option): ?>
                        <option value="<?= e($option['id']) ?>"
                            <?= (int) $option['id'] === $shiftId ? 'selected' : '' ?>>
                            <?= e($clock->display($option['starts_at_utc'])) ?>
                            <?= $option['is_live'] ? ' — now' : ($option['has_ended'] ? ' — ended' : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <button class="button picker__go" type="submit">Show</button>
    </form>
<?php endif; ?>

<?php if ($shift === null): ?>
    <div class="notice">
        <span class="badge">NO SHIFT</span>
        <p class="card__note">
            <?= e($team === null ? 'This team' : (string) $team['name']) ?> has no shift
            in the next two weeks. An administrator creates them under Create Shifts.
        </p>
    </div>
    <?php return; ?>
<?php endif; ?>

<p class="muted">
    <strong><?= e((string) $shift['team_name']) ?></strong> &middot;
    <?= e($clock->display($shift['starts_at_utc'])) ?>
    &ndash; <?= e($clock->display($shift['ends_at_utc'], 'H:i')) ?>
    <?php if ($shift['is_live']): ?>
        &middot; <span class="badge badge--ok">LIVE</span>
    <?php elseif ($shift['has_ended']): ?>
        &middot; <span class="badge badge--warn">ENDED</span>
    <?php endif; ?>
</p>

<?php
// Spec 6.9.1: forward is one tap; backward posts the same form and is answered
// with a confirmation page before anything changes.
$canToggle = $user->can(Resm\Auth\Capability::TogglePhase, $teamId);
?>
<?php if ($canToggle): ?>
    <form method="post" action="<?= e($app->url('officer/phase')) ?>">
        <?= Resm\Csrf::field() ?>
        <input type="hidden" name="team" value="<?= e($teamId) ?>">
        <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
        <input type="hidden" name="return" value="<?= e($self) ?>">

        <div class="phase" role="group" aria-label="Shift phase">
            <?php foreach (Resm\Officer\PhaseControl::PHASES as $option): ?>
                <?php $isOn = $option === (string) $shift['current_phase']; ?>
                <button class="phase__option <?= $isOn ? 'phase__option--on' : '' ?>"
                        type="submit" name="phase" value="<?= e($option) ?>"
                        <?= $isOn ? 'aria-current="true"' : '' ?>>
                    <?= e(Resm\Officer\PhaseControl::label($option)) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </form>
<?php else: ?>
    <div class="phase" role="group" aria-label="Shift phase">
        <?php foreach (Resm\Officer\PhaseControl::PHASES as $option): ?>
            <?php $isOn = $option === (string) $shift['current_phase']; ?>
            <span class="phase__option <?= $isOn ? 'phase__option--on' : '' ?>">
                <?= e(Resm\Officer\PhaseControl::label($option)) ?>
            </span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($coverage !== null): ?>
    <?php
    // Spec 6.9.2: each figure is tappable and filters the relevant list. The
    // critical figure reads red whenever anything critical is vacant, which on
    // a short night is the truth and not a fault — 37 critical positions in
    // Bump and Run against a shift that can run 25 people (spec 5.4, 8.1).
    $q = Resm\Officer\OfficerMenu::query($teamId, $shiftId);
    $assign = $app->url('officer/assign/' . $phase);
    ?>
    <div class="coverage" role="group" aria-label="Coverage">
        <a class="coverage__item" href="<?= e($app->url('officer/checked-in') . $q) ?>">
            <span class="coverage__figure"><?= e($coverage['checked_in']) ?></span>
            <span class="coverage__label">in</span>
        </a>
        <a class="coverage__item" href="<?= e($app->url('officer/absent') . $q) ?>">
            <span class="coverage__figure"><?= e($coverage['not_checked_in']) ?></span>
            <span class="coverage__label">out</span>
        </a>
        <a class="coverage__item" href="<?= e($assign . $q) ?>">
            <span class="coverage__figure"><?= e($coverage['assigned']) ?></span>
            <span class="coverage__label">assigned</span>
        </a>
        <a class="coverage__item" href="<?= e($assign . Resm\Officer\OfficerMenu::query($teamId, $shiftId, 'open=1')) ?>">
            <span class="coverage__figure"><?= e($coverage['open']) ?></span>
            <span class="coverage__label">open</span>
        </a>
        <a class="coverage__item <?= $coverage['critical_short'] ? 'coverage__item--short' : '' ?>"
           href="<?= e($assign . Resm\Officer\OfficerMenu::query($teamId, $shiftId, 'critical=1')) ?>">
            <span class="coverage__figure">
                <?= e($coverage['critical_filled']) ?>/<?= e($coverage['critical_total']) ?>
            </span>
            <span class="coverage__label">critical</span>
        </a>
    </div>
<?php endif; ?>
