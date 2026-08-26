<?php
/**
 * Broadcast Message (spec 6.9.10).
 *
 * A short line pinned to the status widget of every committeeman on the shift.
 * One live message at a time: a widget stacking three says nothing at arm's
 * length, and the newest is the one that counts.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $live
 * @var array<int, array<string, mixed>> $history
 * @var string|null $error
 * @var string|null $notice
 */

$self = 'officer/broadcast';
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
$clock = new Resm\ShiftClock($app->displayTimezone());
$utc = new DateTimeZone('UTC');
?>
<h1>Broadcast Message</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?= officerHeader($app, get_defined_vars(), $self) ?>

<?php if ($live !== null): ?>
    <div class="broadcast">
        <?= e((string) $live['body']) ?>
    </div>
    <form method="post" action="<?= e($app->url($self)) ?>">
        <?= Resm\Csrf::field() ?>
        <input type="hidden" name="team" value="<?= e($teamId) ?>">
        <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
        <input type="hidden" name="action" value="retire">
        <button class="button button--quiet" type="submit">Take it down</button>
    </form>
<?php else: ?>
    <p class="muted">Nothing is pinned to the widget right now.</p>
<?php endif; ?>

<h2>Send a message</h2>

<form method="post" action="<?= e($app->url($self)) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="team" value="<?= e($teamId) ?>">
    <input type="hidden" name="shift" value="<?= e($shiftId) ?>">

    <label class="field">
        <span class="field__label">Message</span>
        <textarea class="field__input" name="body" rows="3"
                  maxlength="<?= e(Resm\Officer\Broadcasts::MAX_LENGTH) ?>" required
                  placeholder="Bump and run in 15 minutes"></textarea>
        <span class="field__hint">
            Everybody on this shift sees it on their status strip. Sending a new
            one replaces whatever is up.
        </span>
    </label>

    <label class="field">
        <span class="field__label">Take it down after (minutes)</span>
        <input class="field__input" type="number" name="expires_in" min="1" max="600"
               inputmode="numeric" placeholder="Leave blank to keep it up">
    </label>

    <button class="button button--primary" type="submit">Pin it to every widget</button>
</form>

<?php if ($history !== []): ?>
    <h2>Earlier tonight</h2>
    <ul class="people">
        <?php foreach ($history as $message): ?>
            <li class="person">
                <div class="person__meta">
                    <?= e($clock->display(new DateTimeImmutable((string) $message['created_at'], $utc), 'H:i')) ?>
                    <?php if ($message['last_name'] !== null): ?>
                        &middot; <?= e((string) $message['first_name'] . ' ' . (string) $message['last_name']) ?>
                    <?php endif; ?>
                    <?php if ($message['retired_at'] !== null): ?>
                        &middot; <span class="badge">TAKEN DOWN</span>
                    <?php endif; ?>
                </div>
                <div><?= e((string) $message['body']) ?></div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
