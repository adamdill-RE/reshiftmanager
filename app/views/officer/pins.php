<?php
/**
 * Reset PINs (spec 6.9.11).
 *
 * A searchable roster with a Reset to 1234 action against each name. PINs are
 * never displayed, because they are never stored in a form anybody could
 * display — a reset is the only recovery there is.
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $people
 * @var string $search
 * @var string|null $error
 * @var string|null $notice
 */

$self = 'officer/pins';
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
?>
<h1>Reset PINs</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?= officerHeader($app, get_defined_vars(), $self) ?>

<p class="muted">
    A reset sets the PIN to 1234 and signs that account out everywhere. Nobody
    can be told their old PIN: it is not stored in a form anyone can read.
</p>

<form class="filters" method="get" action="<?= e($app->url($self)) ?>">
    <input type="hidden" name="team" value="<?= e($teamId) ?>">
    <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
    <label class="field">
        <span class="field__label">Search by last name</span>
        <input class="field__input" type="search" name="q" value="<?= e($search) ?>"
               autocomplete="off" enterkeyhint="search">
    </label>
    <button class="button" type="submit">Search</button>
</form>

<ul class="people">
    <?php foreach ($people as $person): ?>
        <?= (new Resm\View($app))->render('officer/person-row', [
            'app' => $app, 'person' => $person, 'screen' => 'pins', 'user' => $user,
            'teamId' => $teamId, 'shiftId' => $shiftId, 'phase' => $phase,
        ], layout: null) ?>
    <?php endforeach; ?>
</ul>
