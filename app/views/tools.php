<?php
/**
 * Tools, spec 6.7.
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

<?php
// Spec 9.2 and 9.3. The whole section ships hidden and display.js reveals it,
// because both controls are stored on the device and neither does anything
// without JavaScript — and an inert Dark button is worse than no Dark button.
// The stylesheet has carried :root[data-theme] and :root[data-text] since
// phase 1; this is what finally sets them.
?>
<section data-display hidden>
    <h2>Display</h2>

    <h3 id="theme-label">Theme</h3>
    <p class="muted">
        Night shifts end at 02:00. A white screen on a dark tarmac costs you
        your night vision, so this follows your phone unless you pin it.
    </p>

    <div class="choice" role="group" aria-labelledby="theme-label">
        <button class="choice__option" type="button" data-theme-choice="auto">Auto</button>
        <button class="choice__option" type="button" data-theme-choice="light">Light</button>
        <button class="choice__option" type="button" data-theme-choice="dark">Dark</button>
    </div>

    <h3 id="text-label">Large Text</h3>
    <p class="muted">Everything about a quarter larger.</p>

    <div class="choice" role="group" aria-labelledby="text-label">
        <button class="choice__option" type="button" data-text-choice="normal">Normal</button>
        <button class="choice__option" type="button" data-text-choice="large">Large</button>
    </div>

    <p class="field__hint" data-display-warning hidden>
        This phone will not remember the setting — it is set to block site
        data. It will hold until you close the app.
    </p>
</section>

<hr class="divider">

<h2>Install this app on your phone</h2>

<p class="muted">
    Installed, it opens from the home screen without the browser bars, and it
    keeps working on the tarmac when the signal does not.
</p>

<?php
// Every platform's instructions are rendered, and install.js hides the ones
// that do not apply. That way round on purpose: without JavaScript a man in a
// hangar sees all three sets and can follow the one he recognises, which is a
// far better failure than a blank section or instructions for the wrong phone.
?>
<div class="install" data-install>
    <p class="install__state" data-install-done hidden>
        <span class="badge badge--ok">INSTALLED</span>
        You are running the installed app.
    </p>

    <p data-install-prompt hidden>
        <button class="button button--primary" type="button" data-install-go>Install now</button>
    </p>

    <section class="install__how" data-platform="ios">
        <h3>iPhone or iPad</h3>
        <ol class="install__steps">
            <li>Open this page in <strong>Safari</strong>. Chrome on an iPhone cannot install it.</li>
            <li>Tap the Share button — the square with an arrow out of the top.</li>
            <li>Scroll down and tap <strong>Add to Home Screen</strong>.</li>
            <li>Tap <strong>Add</strong>.</li>
        </ol>
    </section>

    <section class="install__how" data-platform="android">
        <h3>Android</h3>
        <ol class="install__steps">
            <li>Open this page in <strong>Chrome</strong>.</li>
            <li>Tap the three dots at the top right.</li>
            <li>Tap <strong>Install app</strong>, or <strong>Add to Home screen</strong>.</li>
            <li>Tap <strong>Install</strong>.</li>
        </ol>
    </section>

    <section class="install__how" data-platform="desktop">
        <h3>Computer</h3>
        <ol class="install__steps">
            <li>In Chrome or Edge, look for the install icon at the right of the address bar.</li>
            <li>Click it, then click <strong>Install</strong>.</li>
        </ol>
    </section>
</div>

<hr class="divider">

<form method="post" action="<?= e($app->url('logout')) ?>">
    <?= Resm\Csrf::field() ?>
    <button class="button button--quiet button--primary" type="submit">Sign out</button>
</form>
