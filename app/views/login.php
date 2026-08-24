<?php
/**
 * Spec 6.1 and 3.2. Two fields, a large keypad, and nothing else — this is
 * read in the dark, in the rain, by someone who has just parked a bus.
 *
 * @var Resm\App $app
 * @var string|null $error
 * @var string $memberId
 * @var bool $remember
 */
?>
<h1>Sign in</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="<?= e($app->url('login')) ?>" data-keypad>
    <?= Resm\Csrf::field() ?>

    <div class="field">
        <label class="field__label" for="member_id">Member ID</label>
        <input class="field__input" id="member_id" name="member_id" type="text"
               inputmode="numeric" autocomplete="username" autocapitalize="off"
               autocorrect="off" spellcheck="false" required
               value="<?= e($memberId) ?>">
    </div>

    <div class="field">
        <span class="field__label" id="pin-label">PIN</span>

        <!-- Filled by the keypad. Without JavaScript this stays a plain
             number field and the form still works. -->
        <div class="pin-dots" data-pin-dots hidden aria-live="polite">
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
        </div>

        <div data-pin-fallback>
            <input class="field__input" id="pin" name="pin" type="password"
                   inputmode="numeric" pattern="[0-9]*" maxlength="4"
                   autocomplete="current-password" required
                   aria-labelledby="pin-label">
        </div>

        <div class="keypad" data-keypad-buttons hidden>
            <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit): ?>
                <button class="keypad__key" type="button" data-key="<?= $digit ?>"><?= $digit ?></button>
            <?php endforeach; ?>
            <button class="keypad__key keypad__key--wide" type="button" data-key="clear">Clear</button>
            <button class="keypad__key" type="button" data-key="0">0</button>
            <button class="keypad__key keypad__key--wide" type="button" data-key="back">Delete</button>
        </div>
    </div>

    <!-- Default on, so a committeeman signs in once at the start of the
         season rather than once a shift (spec 3.2). -->
    <label class="check">
        <input type="checkbox" name="remember" value="1" <?= $remember ? 'checked' : '' ?>>
        <span>Keep me signed in</span>
    </label>

    <button class="button button--primary" type="submit">Sign in</button>
</form>

<hr class="divider">

<p class="muted">
    <strong>Forgot your PIN?</strong> See any officer. They can reset it to
    1234 in two taps — there is no self-service reset, and nobody can look your
    PIN up, because it is never stored in a form anyone can read.
</p>
