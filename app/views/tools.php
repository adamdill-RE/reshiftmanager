<?php
/**
 * Tools, spec 6.7. Large Text, theme and the install instructions arrive with
 * the phases that own them; changing a PIN belongs to the credential model and
 * is here now.
 *
 * @var Resm\App $app
 * @var Resm\Auth\Identity $user
 * @var string|null $error
 * @var string|null $notice
 */
?>
<h1>Tools</h1>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<h2>Preferred Skills</h2>

<p class="muted">
    Tell your officers what you would rather do. It shows beside your name when
    they are filling the board.
</p>

<p>
    <a class="button button--quiet" href="<?= e($app->url('tools/skills')) ?>">Set my preferred skills</a>
</p>

<hr class="divider">

<h2>Change my PIN</h2>

<form method="post" action="<?= e($app->url('tools/pin')) ?>">
    <?= Resm\Csrf::field() ?>

    <div class="field">
        <label class="field__label" for="current_pin">Current PIN</label>
        <input class="field__input" id="current_pin" name="current_pin" type="password"
               inputmode="numeric" pattern="[0-9]*" maxlength="4"
               autocomplete="current-password" required>
    </div>

    <div class="field">
        <label class="field__label" for="new_pin">New PIN</label>
        <input class="field__input" id="new_pin" name="new_pin" type="password"
               inputmode="numeric" pattern="[0-9]*" maxlength="4"
               autocomplete="new-password" required>
        <p class="field__hint">Four digits.</p>
    </div>

    <div class="field">
        <label class="field__label" for="confirm_pin">New PIN again</label>
        <input class="field__input" id="confirm_pin" name="confirm_pin" type="password"
               inputmode="numeric" pattern="[0-9]*" maxlength="4"
               autocomplete="new-password" required>
    </div>

    <button class="button button--primary" type="submit">Change PIN</button>

    <p class="field__hint">
        This signs you out everywhere else and keeps this phone signed in.
    </p>
</form>

<hr class="divider">

<form method="post" action="<?= e($app->url('logout')) ?>">
    <?= Resm\Csrf::field() ?>
    <button class="button button--quiet button--primary" type="submit">Sign out</button>
</form>
