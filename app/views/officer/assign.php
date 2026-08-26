<?php
/**
 * Assign Unload / Assign Bump and Run (spec 6.9.4).
 *
 * Two taps per placement and no drag and drop. Both modes reach the same two
 * sheets: position-first taps a vacancy and picks a name, roster-first taps a
 * name and picks a vacancy. Best for filling holes and best for clearing a
 * list of sixty people respectively, which is why both exist.
 *
 * @var Resm\App $app
 * @var array<string, mixed> $shift
 * @var string $phase
 * @var string $mode
 * @var array<int, array<string, mixed>> $groups
 * @var array<int, array<string, mixed>> $criticalVacancies
 * @var array<int, array<string, mixed>> $available
 * @var array<int, array<string, mixed>> $chips
 * @var array{search: string, skill: string} $filters
 * @var string|null $error
 * @var string|null $notice
 */

$self = 'officer/assign/' . $phase;
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
$q = Resm\Officer\OfficerMenu::query($teamId, $shiftId);
$link = static fn (string $extra): string
    => $app->url('officer/assign/' . $phase) . Resm\Officer\OfficerMenu::query($teamId, $shiftId, $extra);
?>
<h1>Assign <?= e(Resm\Officer\PhaseControl::label($phase)) ?></h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?= officerHeader($app, get_defined_vars(), $self) ?>

<?php if ($phase !== (string) $shift['current_phase']): ?>
    <p class="notice card__note">
        You are setting up the <?= e(Resm\Officer\PhaseControl::label($phase)) ?> board.
        The shift is running <?= e(Resm\Officer\PhaseControl::label((string) $shift['current_phase'])) ?>,
        so nobody sees these positions yet.
    </p>
<?php endif; ?>

<div class="modes" role="group" aria-label="Assign mode">
    <a class="modes__option <?= $mode === 'position' ? 'modes__option--on' : '' ?>"
       href="<?= e($link('mode=position')) ?>">By position</a>
    <a class="modes__option <?= $mode === 'roster' ? 'modes__option--on' : '' ?>"
       href="<?= e($link('mode=roster')) ?>">By person</a>
</div>

<?php if ($mode === 'roster'): ?>
    <?php // Best for clearing a list of sixty people at the start of a shift. ?>
    <h2><?= count($available) ?> waiting for a position</h2>

    <?= (new Resm\View($app))->render('officer/filters', [
        'app' => $app, 'chips' => $chips, 'filters' => $filters,
        'action' => $app->url($self), 'teamId' => $teamId, 'shiftId' => $shiftId, 'mode' => $mode,
    ], layout: null) ?>

    <?php if ($available === []): ?>
        <p class="muted">Nobody is checked in and waiting. Everyone who has arrived is placed.</p>
    <?php else: ?>
        <ul class="picks">
            <?php foreach ($available as $person): ?>
                <li>
                    <a class="pick" href="<?= e($link('person=' . (int) $person['id'] . '&mode=roster')) ?>">
                        <span class="pick__name"><?= e($person['list_name']) ?></span>
                        <?= (new Resm\View($app))->render('officer/person-chips', [
                            'app' => $app, 'person' => $person,
                        ], layout: null) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php else: ?>
    <?php // Best for filling holes. Vacant critical positions come first, in red. ?>
    <?php if ($criticalVacancies !== []): ?>
        <h2 class="pinned__heading"><?= count($criticalVacancies) ?> critical position<?= count($criticalVacancies) === 1 ? '' : 's' ?> vacant</h2>
        <ul class="picks">
            <?php foreach ($criticalVacancies as $position): ?>
                <li>
                    <a class="pick pick--critical" href="<?= e($link('position=' . (int) $position['id'])) ?>">
                        <span class="pick__name"><?= e($position['label']) ?></span>
                        <span class="pick__meta">
                            <?= e($position['group_label']) ?>
                            <?php if ($position['is_radio']): ?> &middot; radio<?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php foreach ($groups as $group): ?>
        <?php // Collapsible, so a 95-position board stays navigable on a phone. ?>
        <details class="group" <?= $group['filled'] < $group['total'] ? 'open' : '' ?>>
            <summary class="disclosure">
                <?= e($group['label']) ?>
                <span class="muted"><?= e($group['filled']) ?>/<?= e($group['total']) ?></span>
            </summary>
            <ul class="picks">
                <?php foreach ($group['positions'] as $position): ?>
                    <li>
                        <a class="pick <?= $position['holders'] === [] && $position['is_critical'] ? 'pick--critical' : '' ?>"
                           href="<?= e($link('position=' . (int) $position['id'])) ?>">
                            <span class="pick__name"><?= e($position['label']) ?></span>
                            <span class="pick__meta">
                                <?php if ($position['holders'] === []): ?>
                                    <em class="muted">vacant</em>
                                <?php else: ?>
                                    <?php foreach ($position['holders'] as $holder): ?>
                                        <span class="pick__holder">
                                            <?= e($holder['list_name']) ?><?php if ($holder['is_inherited']): ?>
                                                <span class="badge">CARRIED</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($position['skill_label'] !== null): ?>
                                    &middot; <?= e($position['skill_label']) ?>
                                <?php endif; ?>
                                <?php if ($position['is_radio']): ?> &middot; radio<?php endif; ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endforeach; ?>
<?php endif; ?>
