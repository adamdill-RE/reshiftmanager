<?php
/**
 * @var Resm\App $app
 * @var string $content
 * @var string $title
 * @var array<int, string> $scripts asset paths, deferred
 * @var array{url: string, label: string}|null $back
 * @var int|null $pollShift the shift this screen is about, if any
 */

$scripts = $scripts ?? [];
$back = $back ?? null;
$pollShift = $pollShift ?? null;

// Spec 6.3: the strip is on every screen, so it is rendered here rather than
// by each page remembering to. It builds to null whenever there is nothing to
// say, and never throws — a decoration must not take down the page it
// decorates.
$widget = Resm\Shift\Widget::forRequest($app);

// Which shift this page polls. An officer screen names its own — it is looking
// at a team's board, not necessarily one he is checked into — and everything
// else follows the strip. Null means the page does not poll (spec 10.2).
$poll = Resm\Poll\State::forPage(
    $app,
    $pollShift ?? ($widget === null ? null : (int) $widget['shift']['id'])
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <!-- The theme follows the device until Tools pins it (spec 9.2). theme.js
         applies a pinned choice before paint; this is the honest default for
         the moment before it runs and for a browser without JavaScript. -->
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#EF7622">

    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e($app->asset('css/app.css')) ?>">

    <?php
    // Installable to the home screen (spec 10.1). The manifest is a route
    // rather than a file because every path inside it is absolute and nothing
    // may hard-code the mount point (CLAUDE.md).
    ?>
    <link rel="manifest" href="<?= e($app->url('manifest.webmanifest')) ?>">

    <?php
    // iOS does not read the manifest for the home-screen icon; this is the one
    // it uses. Naming the favicon explicitly matters for a different reason:
    // without it the browser probes the DOCUMENT ROOT for /favicon.ico, which
    // is not ours to answer — the app is mounted at /resm/ and the root belongs
    // to the domain.
    ?>
    <link rel="apple-touch-icon" href="<?= e($app->asset('icons/apple-touch-icon.png')) ?>">
    <link rel="icon" type="image/png" href="<?= e($app->asset('icons/favicon.png')) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Rodeo Shifts">

    <?php
    // Ahead of the stylesheet's paint rather than deferred with everything
    // else: a pinned dark theme applied after first paint is a white flash in
    // the face of someone standing on a dark tarmac at 02:00 (spec 9.2).
    ?>
    <script src="<?= e($app->asset('js/theme.js')) ?>"></script>
</head>
<body data-base="<?= e($app->basePath()) ?>"
    <?php if ($poll !== null): ?>
        data-poll-shift="<?= e($poll['shift']) ?>"
        data-poll-version="<?= e($poll['version']) ?>"
        data-poll-foreground="<?= e($poll['foreground']) ?>"
        data-poll-background="<?= e($poll['background']) ?>"
        <?php if ($poll['closes_at'] !== null): ?>
            data-poll-closes="<?= e($poll['closes_at']) ?>"
        <?php endif; ?>
    <?php endif; ?>>
<div class="page">
    <header class="masthead">
        <span class="masthead__name">Rodeo Express</span>
        <span class="masthead__sub">Shift Management</span>
    </header>

    <?php
    // Wrapped, and wrapped even when empty. The strip is two sibling nodes —
    // the strip itself and the broadcast pinned beneath it (spec 6.3) — and
    // the polling layer replaces both together. A container that only exists
    // when there is something in it is one the poller cannot fill.
    ?>
    <div id="status-strip" data-strip>
        <?php
        if ($widget !== null) {
            echo (new Resm\View($app))->render('widget', $widget, layout: null);
        }
        ?>
    </div>

    <?php if ($back !== null): ?>
        <p><a class="button button--quiet" href="<?= e($back['url']) ?>">&larr; <?= e($back['label']) ?></a></p>
    <?php endif; ?>

    <?= $content ?>
</div>

<?php
// poll.js is the transport and carries no opinion about any screen; freshness.js
// is the strip's subscriber. Loading the transport on a page with nothing to
// poll costs a parse and does nothing, so it is loaded only where there is.
if ($poll !== null && !in_array('js/poll.js', $scripts, true)) {
    array_unshift($scripts, 'js/poll.js');
}
if ($poll !== null && !in_array('js/freshness.js', $scripts, true)) {
    $scripts[] = 'js/freshness.js';
}
?>
<?php foreach ($scripts as $script): ?>
    <script src="<?= e($app->asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
