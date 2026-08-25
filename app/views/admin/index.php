<?php
/**
 * Admin Menu, spec 6.10.
 *
 * @var Resm\App $app
 * @var array<int, array{key: string, url: string, label: string, sub: string, built: bool}> $tiles
 * @var array<string, mixed>|null $season
 */
?>
<h1>Admin Menu</h1>

<div class="card">
    <div class="card__label">Active season</div>
    <?php if ($season === null): ?>
        <div class="card__value">None</div>
        <p class="muted card__note">
            Teams, shifts and rosters all hang off a season. Create one and
            activate it before anything else.
        </p>
    <?php else: ?>
        <div class="card__value"><?= e($season['name']) ?></div>
        <p class="muted card__note">
            <?= e($season['start_date']) ?> to <?= e($season['end_date']) ?>
        </p>
    <?php endif; ?>
</div>

<nav>
    <?php foreach ($tiles as $tile): ?>
        <a class="tile" href="<?= e($tile['url']) ?>">
            <?= icon('shield') ?>
            <span class="tile__text">
                <span><?= e($tile['label']) ?></span>
                <span class="tile__sub">
                    <?= e($tile['sub']) ?><?= $tile['built'] ? '' : ' · not built yet' ?>
                </span>
            </span>
        </a>
    <?php endforeach; ?>
</nav>
