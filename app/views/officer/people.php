<?php
/**
 * View Checked In / View Absent (spec 6.9.8).
 *
 * Checked In is who is on the tarmac; Absent is who never turned up, with a
 * tap-to-call button against each — chasing them down is what the screen is
 * for.
 *
 * @var Resm\App $app
 * @var string $screen
 * @var array<int, array<string, mixed>> $people
 * @var string|null $error
 * @var string|null $notice
 */

$self = 'officer/' . $screen;
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
?>
<h1><?= e(Resm\Officer\OfficerMenu::SECTIONS[$screen]['label']) ?></h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?= officerHeader($app, get_defined_vars(), $self) ?>

<h2><?= count($people) ?> <?= $screen === 'absent' ? 'not checked in' : 'on the tarmac' ?></h2>

<?php if ($people === []): ?>
    <p class="muted">
        <?= $screen === 'absent'
            ? 'Everybody on the roster has checked in at some point tonight.'
            : 'Nobody is checked in right now.' ?>
    </p>
<?php else: ?>
    <ul class="people">
        <?php foreach ($people as $person): ?>
            <?= (new Resm\View($app))->render('officer/person-row', [
                'app' => $app, 'person' => $person, 'screen' => $screen, 'user' => $user,
                'teamId' => $teamId, 'shiftId' => $shiftId, 'phase' => $phase,
            ], layout: null) ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
