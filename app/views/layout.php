<?php
/**
 * @var Resm\App $app
 * @var string $content
 * @var string $title
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <!-- The dark theme follows the device by default; Tools will pin it later. -->
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

    <?= $content ?>
</div>
</body>
</html>
