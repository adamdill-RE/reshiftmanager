<?php
/**
 * Check In / Out, spec 6.4.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $shift the shift being acted on
 * @var array<int, array<string, mixed>> $candidates every shift in the window
 * @var Resm\ShiftClock $clock
 * @var string|null $confirmed the timestamp just recorded, local
 * @var int $vacated positions freed by a check-out
 * @var string|null $error
 */

$url = $app->url('check-in');
?>
<h1>Check In / Out</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($confirmed !== null): ?>
    <p class="alert alert--ok" role="status">
        Recorded at <?= e($confirmed) ?>.
        <?php if ($vacated > 0): ?>
            Your position<?= $vacated === 1 ? ' is' : 's are' ?> now open for someone else.
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php if ($shift === null): ?>
    <div class="notice">
        <span class="badge">NO SHIFT TODAY</span>
        <p class="card__note">
            You have no shift you can check into right now. A shift opens at
            midnight on its date and closes at 04:00 the following morning.
        </p>
    </div>
    <p><a class="button button--quiet" href="<?= e($app->url('my-shifts')) ?>">See my shifts</a></p>
    <?php return; ?>
<?php endif; ?>

<?php $in = (bool) $shift['checked_in']; ?>

<p class="muted">
    <strong><?= e((string) $shift['team_name']) ?></strong> &middot;
    <?= e($clock->display($shift['starts_at_utc'])) ?>
    &ndash; <?= e($clock->display($shift['ends_at_utc'], 'H:i')) ?>
    <?php if ($shift['has_ended']): ?>
        &middot; <span class="badge badge--warn">FINISHED</span>
    <?php endif; ?>
</p>

<?php
// data-offline is what offline.js diverts on, and it is an opt-IN: only the
// three writes spec 10.3 names may be recorded late. An officer's assignment
// carries no such attribute and so cannot be queued by accident (10.3, 10.4).
?>
<form method="post" action="<?= e($url) ?>" data-offline="check">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="shift_id" value="<?= e($shift['id']) ?>">
    <input type="hidden" name="type" value="<?= $in ? 'out' : 'in' ?>">

    <button class="button button--primary check-button" type="submit">
        <?= $in ? 'CHECK OUT' : 'CHECK IN' ?>
    </button>

    <p class="offline-note" data-offline-note hidden></p>
</form>

<p class="field__hint">
    <?php if ($in): ?>
        Checking out frees your position in both phases so an officer can fill it.
    <?php else: ?>
        Honour system &mdash; no code to scan. Your time is recorded when you tap.
    <?php endif; ?>
</p>

<?php if (count($candidates) > 1): ?>
    <hr class="divider">
    <h2>Your other shift<?= count($candidates) > 2 ? 's' : '' ?> today</h2>
    <p class="field__hint">
        You are on more than one team today. Check out of the one you have
        finished before checking into the next.
    </p>

    <?php foreach ($candidates as $other): ?>
        <?php if ((int) $other['id'] === (int) $shift['id']) { continue; } ?>
        <div class="card">
            <div class="card__value"><?= e((string) $other['team_name']) ?></div>
            <p class="muted card__note">
                <?= e($clock->display($other['starts_at_utc'])) ?>
                &ndash; <?= e($clock->display($other['ends_at_utc'], 'H:i')) ?>
                &middot; <?= $other['checked_in'] ? 'checked in' : 'not checked in' ?>
                <?php if ($other['has_ended']): ?>&middot; finished<?php endif; ?>
            </p>
            <form method="get" action="<?= e($url) ?>">
                <input type="hidden" name="shift" value="<?= e($other['id']) ?>">
                <button class="button button--quiet" type="submit">
                    Switch to <?= e((string) $other['team_name']) ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
