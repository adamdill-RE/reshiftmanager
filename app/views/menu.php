<?php
/**
 * Main Menu, spec 6.2: one vertical column of full-width tiles, at least 72px
 * tall, icon and label, in a fixed order. Tiles the user cannot access are not
 * rendered — and the handler behind each one checks again, because hiding a
 * tile is presentation, never authorisation (spec 10.5).
 *
 * @var Resm\App $app
 * @var Resm\Auth\Identity $user
 * @var array<int, array{url: string, label: string, icon: string, sub: string}> $tiles
 */
?>
<div class="identity">
    <span><strong><?= e($user->name()) ?></strong></span>
    <span><?= e($user->role->label()) ?></span>
</div>

<nav>
    <?php foreach ($tiles as $tile): ?>
        <a class="tile" href="<?= e($tile['url']) ?>">
            <?= icon($tile['icon']) ?>
            <span class="tile__text">
                <span><?= e($tile['label']) ?></span>
                <?php if ($tile['sub'] !== ''): ?>
                    <span class="tile__sub"><?= e($tile['sub']) ?></span>
                <?php endif; ?>
            </span>
        </a>
    <?php endforeach; ?>
</nav>
