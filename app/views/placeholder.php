<?php
/**
 * A screen the build sequence has not reached yet.
 *
 * Rendered instead of a dead link, and still behind the same server-side role
 * check the real screen will use — so the Officer and Admin routes are already
 * enforcing scope before they have anything to show.
 *
 * @var Resm\App $app
 * @var string $heading
 * @var string $summary
 * @var string $phase
 */
?>
<h1><?= e($heading) ?></h1>

<div class="notice">
    <span class="badge badge--warn">NOT BUILT YET</span>
    <span><?= e($phase) ?></span>
</div>

<p class="muted"><?= e($summary) ?></p>
