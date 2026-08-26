<?php
/**
 * Switching back to Unload asks first (spec 5.2).
 *
 * A page rather than a JavaScript dialog: it survives a reload, it works with
 * scripting off, and the wording is the spec's own — an officer needs to know
 * that every committeeman sees this the instant it lands.
 *
 * @var Resm\App $app
 * @var array<string, mixed> $shift
 * @var array<string, mixed>|null $team
 * @var string $target the phase being switched to
 */
?>
<h1>Switch back to Unload?</h1>

<div class="notice">
    <p class="card__note">
        All committeemen will immediately see their Unload assignment.
    </p>
</div>

<p class="muted">
    Assignments in each phase are kept separately, so the Bump and Run board
    will be exactly as you left it when you come back to it.
</p>

<form method="post" action="<?= e($app->url('officer/phase')) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="team" value="<?= e($team === null ? '' : $team['id']) ?>">
    <input type="hidden" name="shift" value="<?= e($shift['id']) ?>">
    <input type="hidden" name="phase" value="<?= e($target) ?>">
    <input type="hidden" name="confirm" value="1">

    <button class="button button--primary check-button" type="submit">
        SWITCH TO UNLOAD
    </button>
</form>

<p>
    <a class="button button--quiet"
       href="<?= e($app->url('officer') . Resm\Officer\OfficerMenu::query(
           $team === null ? null : (int) $team['id'],
           (int) $shift['id']
       )) ?>">Keep Bump and Run</a>
</p>
