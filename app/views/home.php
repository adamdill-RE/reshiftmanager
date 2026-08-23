<?php
/**
 * @var Resm\App $app
 * @var int $positions
 * @var int $groups
 * @var int $phaseRecords
 */
?>
<h1>Shift Management</h1>

<p class="muted">
    This is the foundation build. Sign-in, the status widget and the officer
    boards arrive with the phases that follow.
</p>

<div class="card">
    <div class="card__label">Position matrix</div>
    <div class="card__value"><?= e($positions) ?> positions</div>
    <p class="muted card__note">
        <?= e($groups) ?> groups &middot; <?= e($phaseRecords) ?> position-phase records
    </p>
</div>

<p class="footnote">
    Built for cold hands, wet screens and one bar of signal.
</p>
