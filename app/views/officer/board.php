<?php
/**
 * View Unload / View Bump and Run (spec 6.9.7).
 *
 * Read-only and condensed, grouped by position group: position on the left,
 * whoever is standing there on the right, vacancies in muted italic. Built to
 * be scanned in a few seconds rather than read.
 *
 * @var Resm\App $app
 * @var string $phase
 * @var array<int, array<string, mixed>> $groups
 */

$self = 'officer/board/' . $phase;
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
?>
<h1>View <?= e(Resm\Officer\PhaseControl::label($phase)) ?></h1>

<?= officerHeader($app, get_defined_vars(), $self) ?>

<?php if ($user->can(Resm\Auth\Capability::AssignPositions, $teamId)): ?>
    <p>
        <a class="button button--quiet"
           href="<?= e($app->url('officer/assign/' . $phase)
               . Resm\Officer\OfficerMenu::query($teamId, $shiftId)) ?>">
            Change this board
        </a>
    </p>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
    <h2><?= e($group['label']) ?> <span class="muted"><?= e($group['filled']) ?>/<?= e($group['total']) ?></span></h2>
    <table class="checks board">
        <tbody>
            <?php foreach ($group['positions'] as $position): ?>
                <tr>
                    <th scope="row">
                        <?= e($position['label']) ?>
                        <?php if ($position['is_critical'] && $position['holders'] === []): ?>
                            <span class="badge badge--danger">VACANT</span>
                        <?php endif; ?>
                    </th>
                    <td>
                        <?php if ($position['holders'] === []): ?>
                            <em class="muted">vacant</em>
                        <?php else: ?>
                            <?php foreach ($position['holders'] as $i => $holder): ?>
                                <?= $i > 0 ? '<br>' : '' ?>
                                <?= e($holder['list_name']) ?>
                                <?php if ($holder['is_inherited']): ?>
                                    <span class="badge">CARRIED</span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>
