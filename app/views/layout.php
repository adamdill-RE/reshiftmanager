<?php
/**
 * @var Resm\App $app
 * @var string $content
 * @var string $title
 * @var array<int, string> $scripts asset paths, deferred
 * @var array{url: string, label: string}|null $back
 */

$scripts = $scripts ?? [];
$back = $back ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <!-- The dark theme follows the device until Tools can pin it (spec 9.2). -->
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#EF7622">

    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e($app->asset('css/app.css')) ?>">
</head>
<body>
<div class="page">
    <header class="masthead">
        <span class="masthead__name">Rodeo Express</span>
        <span class="masthead__sub">Shift Management</span>
    </header>

    <?php
    // Spec 6.3: the strip is on every screen, so it is rendered here rather
    // than by each page remembering to. It builds to null whenever there is
    // nothing to say, and never throws — a decoration must not take down the
    // page it decorates.
    $widget = Resm\Shift\Widget::forRequest($app);
    if ($widget !== null) {
        echo (new Resm\View($app))->render('widget', $widget, layout: null);
    }
    ?>

    <?php if ($back !== null): ?>
        <p><a class="button button--quiet" href="<?= e($back['url']) ?>">&larr; <?= e($back['label']) ?></a></p>
    <?php endif; ?>

    <?= $content ?>
</div>

<?php
if ($widget !== null && !in_array('js/freshness.js', $scripts, true)) {
    $scripts[] = 'js/freshness.js';
}
?>
<?php foreach ($scripts as $script): ?>
    <script src="<?= e($app->asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
