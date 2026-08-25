<?php
/**
 * My Shift Status, spec 6.5 — the committeeman's home base during a shift.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $shift
 * @var array<int, array<string, mixed>> $candidates
 * @var array<string, mixed>|null $assignment
 * @var array<int, array<string, mixed>> $mates
 * @var array<int, array<string, mixed>> $officers
 * @var Resm\ShiftClock $clock
 * @var string|null $error
 * @var string|null $notice
 */

$url = $app->url('my-shift');

/** A tap-to-call link, or plain text when the number could not be trusted. */
$call = static function (array $person) use ($app): string {
    $e164 = $person['phone_e164'] ?? null;
    $shown = (string) ($person['phone'] ?? '');

    if (!is_string($e164) || $e164 === '') {
        return $shown === '' ? '' : '<span class="muted">' . e($shown) . '</span>';
    }

    return sprintf(
        '<a class="button button--quiet" href="tel:%s">Call %s</a>',
        e($e164),
        e($shown === '' ? $e164 : $shown)
    );
};
?>
<h1>My Shift Status</h1>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($shift === null): ?>
    <div class="notice">
        <span class="badge">NO SHIFT RIGHT NOW</span>
        <p class="card__note">
            This screen fills in once you are on a shift. A shift opens at
            midnight on its date and closes at 04:00 the following morning.
        </p>
    </div>
    <p><a class="button button--quiet" href="<?= e($app->url('my-shifts')) ?>">See my shifts</a></p>
    <?php return; ?>
<?php endif; ?>

<p class="muted">
    <strong><?= e((string) $shift['team_name']) ?></strong> &middot;
    <?= e($clock->display($shift['starts_at_utc'])) ?>
    &ndash; <?= e($clock->display($shift['ends_at_utc'], 'H:i')) ?>
</p>

<!-- Spec 6.5: the toggle is here so there is no need to navigate away. -->
<form method="post" action="<?= e($url) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="action" value="check">
    <input type="hidden" name="shift_id" value="<?= e($shift['id']) ?>">
    <input type="hidden" name="type" value="<?= $shift['checked_in'] ? 'out' : 'in' ?>">
    <button class="button button--primary check-button" type="submit">
        <?= $shift['checked_in'] ? 'CHECK OUT' : 'CHECK IN' ?>
    </button>
</form>

<h2>Your position</h2>

<?php if ($assignment === null): ?>
    <div class="card">
        <div class="card__value muted">
            <?= $shift['checked_in'] ? 'Not placed yet' : 'Check in first' ?>
        </div>
        <p class="card__note">
            <?= $shift['checked_in']
                ? 'An officer will put you somewhere shortly.'
                : 'You appear on the assign board once you are checked in.' ?>
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="assignment"><?= e((string) $assignment['position']) ?></div>
        <p class="muted card__note">
            <?= e((string) $assignment['group_label']) ?>
            &middot; <?= (string) $shift['current_phase'] === 'bump_run' ? 'Bump and Run' : 'Unload' ?>
            <?php if ((int) $assignment['is_critical'] === 1): ?>
                &middot; <span class="badge badge--danger">CRITICAL</span>
            <?php endif; ?>
            <?php if ((int) $assignment['is_inherited'] === 1): ?>
                &middot; <span class="badge">CARRIED FROM UNLOAD</span>
            <?php endif; ?>
        </p>

        <details>
            <summary class="disclosure">What's this?</summary>
            <p class="card__note">
                <?php if (($assignment['definition'] ?? null) !== null && $assignment['definition'] !== ''): ?>
                    <?= e((string) $assignment['definition']) ?>
                <?php else: ?>
                    <span class="muted">
                        No description yet for this position. Ask your officer,
                        and Rodeo Express is still writing these up.
                    </span>
                <?php endif; ?>
            </p>
        </details>
    </div>
<?php endif; ?>

<h2>Lunch</h2>

<form method="post" action="<?= e($url) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="action" value="lunch">
    <input type="hidden" name="shift_id" value="<?= e($shift['id']) ?>">

    <div class="check-grid">
        <?php foreach (['not_yet' => 'Not yet', 'at_lunch' => 'At lunch', 'done' => 'Done'] as $state => $label): ?>
            <button class="button <?= (string) $shift['lunch'] === $state ? 'button--primary' : 'button--quiet' ?>"
                    type="submit" name="state" value="<?= e($state) ?>"
                    <?= (string) $shift['lunch'] === $state ? 'aria-current="true"' : '' ?>>
                <?= e($label) ?>
            </button>
        <?php endforeach; ?>
    </div>
</form>
<p class="field__hint">
    Going to lunch frees your position so it does not read as covered while
    you are eating. You will be placed again when you are back.
</p>

<?php if ($assignment !== null): ?>
    <h2><?= count($mates) ?> other<?= count($mates) === 1 ? '' : 's' ?> in <?= e((string) $assignment['group_label']) ?></h2>

    <?php if ($mates === []): ?>
        <p class="muted">Nobody else is placed in your group right now.</p>
    <?php endif; ?>

    <?php foreach ($mates as $mate): ?>
        <div class="card">
            <div class="card__value">
                <?= e($mate['last_name'] . ', ' . $mate['first_name']) ?>
                <?php if ((int) $mate['is_critical'] === 1): ?>
                    <span class="badge badge--danger">CRITICAL</span>
                <?php endif; ?>
            </div>
            <p class="muted card__note"><?= e((string) $mate['position']) ?></p>
            <?= $call($mate) ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<h2>Officers on this shift</h2>

<?php if ($officers === []): ?>
    <p class="muted">Nobody is listed for <?= e((string) $shift['team_name']) ?> yet.</p>
<?php endif; ?>

<?php foreach ($officers as $officer): ?>
    <div class="card">
        <div class="card__value">
            <?= e($officer['last_name'] . ', ' . $officer['first_name']) ?>
            <span class="badge"><?= e(Resm\Auth\Role::from((string) $officer['role'])->label()) ?></span>
        </div>
        <?= $call($officer) ?>
    </div>
<?php endforeach; ?>

<hr class="divider">

<p>
    <a class="button button--quiet" href="<?= e($app->url('my-shifts')) ?>">My Shifts</a>
</p>

<?php if (count($candidates) > 1): ?>
    <h2>Your other shift<?= count($candidates) > 2 ? 's' : '' ?> today</h2>
    <?php foreach ($candidates as $other): ?>
        <?php if ((int) $other['id'] === (int) $shift['id']) { continue; } ?>
        <form method="get" action="<?= e($url) ?>" class="stack">
            <input type="hidden" name="shift" value="<?= e($other['id']) ?>">
            <button class="button button--quiet" type="submit">
                Switch to <?= e((string) $other['team_name']) ?>
                <?php if ($other['has_ended']): ?>(finished)<?php endif; ?>
            </button>
        </form>
    <?php endforeach; ?>
<?php endif; ?>
