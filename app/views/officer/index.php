<?php
/**
 * Officer Menu, spec 6.9 — the operational core.
 *
 * @var Resm\App $app
 * @var array<int, array{key: string, url: string, label: string, sub: string}> $tiles
 * @var array<string, mixed>|null $shift
 * @var string|null $error
 * @var string|null $notice
 */

$self = 'officer';
?>
<h1>Officer Menu</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?= officerHeader($app, get_defined_vars(), $self) ?>

<nav>
    <?php foreach ($tiles as $tile): ?>
        <a class="tile" href="<?= e($tile['url']) ?>">
            <?= icon('clipboard') ?>
            <span class="tile__text">
                <span><?= e($tile['label']) ?></span>
                <span class="tile__sub"><?= e($tile['sub']) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</nav>
