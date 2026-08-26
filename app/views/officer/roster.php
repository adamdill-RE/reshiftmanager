<?php
/**
 * View Roster (spec 6.9.3).
 *
 * The team, sorted Last Name First Name, with certified skills and equipment
 * certifications under each name, a tap-to-call number, and the buttons an
 * officer reaches for most: check in or out for a dead phone or a man who went
 * home sick, a PIN reset, and the walk-on form.
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $people
 * @var string $search
 * @var string|null $error
 * @var string|null $notice
 */

$self = 'officer/roster';
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
?>
<h1>View Roster</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?= officerHeader($app, get_defined_vars(), $self) ?>

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

<?php if ($user->can(Resm\Auth\Capability::AddWalkon, $teamId)): ?>
    <?php // Under twenty seconds, at 17:00, with a bus arriving (spec 6.9.3). ?>
    <details class="disclosure-block">
        <summary class="disclosure">Add a walk-on</summary>
        <form method="post" action="<?= e($app->url($self)) ?>">
            <?= Resm\Csrf::field() ?>
            <input type="hidden" name="team" value="<?= e($teamId) ?>">
            <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
            <input type="hidden" name="action" value="walkon">

            <label class="field">
                <span class="field__label">Last name</span>
                <input class="field__input" type="text" name="last_name" required autocomplete="family-name">
            </label>
            <label class="field">
                <span class="field__label">First name</span>
                <input class="field__input" type="text" name="first_name" required autocomplete="given-name">
            </label>
            <label class="field">
                <span class="field__label">Phone</span>
                <input class="field__input" type="tel" name="phone" autocomplete="tel" inputmode="tel">
            </label>
            <label class="field">
                <span class="field__label">Member ID (optional)</span>
                <input class="field__input" type="text" name="member_id" inputmode="numeric">
                <span class="field__hint">
                    Without one he cannot sign in until somebody fills it in.
                </span>
            </label>

            <button class="button button--primary" type="submit">Add to the roster</button>
        </form>
    </details>
<?php endif; ?>

<h2><?= count($people) ?> on the roster</h2>

<?php if ($people === []): ?>
    <p class="muted">Nobody matches. Clear the search to see the whole team.</p>
<?php else: ?>
    <ul class="people">
        <?php foreach ($people as $person): ?>
            <?= (new Resm\View($app))->render('officer/person-row', [
                'app' => $app, 'person' => $person, 'screen' => 'roster', 'user' => $user,
                'teamId' => $teamId, 'shiftId' => $shiftId, 'phase' => $phase, 'showSkills' => true,
            ], layout: null) ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
