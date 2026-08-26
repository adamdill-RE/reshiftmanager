<?php

declare(strict_types=1);

use Resm\App;
use Resm\Pwa\Manifest;

/**
 * The PWA shell (spec 10.1).
 *
 * The manifest is the one document in the application that is all absolute
 * paths, which makes it the most likely place for the /resm/ mount point to
 * get written down by hand. These are here to make that fail loudly: a
 * hard-coded manifest does not throw, it just quietly stops being installable
 * on the day somebody moves the app.
 */

function manifestDoc(): array
{
    return Manifest::document(App::boot(dirname(__DIR__)));
}

test('the manifest is scoped to the mount point, not the domain root', function (): void {
    $m = manifestDoc();

    assertSame('/resm/', $m['scope']);
    assertSame('/resm/', $m['start_url']);

    // A scope of "/" would claim the whole domain, and an installed app would
    // then swallow every other page on it.
    assertTrue($m['scope'] !== '/', 'scope must not be the domain root');
});

test('every path in the manifest is built from the configured base path', function (): void {
    $app = App::boot(dirname(__DIR__));
    $base = $app->basePath();
    $m = Manifest::document($app);

    $paths = [$m['id'], $m['start_url'], $m['scope']];
    foreach ($m['icons'] as $icon) {
        $paths[] = $icon['src'];
    }

    foreach ($paths as $path) {
        assertTrue(
            str_starts_with($path, $base),
            "'{$path}' does not start with the configured base path '{$base}'"
        );
    }
});

test('the manifest follows the base path when the app is mounted elsewhere', function (): void {
    // The actual regression guard. Everything above still passes against a
    // manifest with '/resm/' typed into it; this does not.
    putenv('RESM_BASE_PATH=/somewhere-else/');
    try {
        $m = Manifest::document(App::boot(dirname(__DIR__)));

        assertSame('/somewhere-else/', $m['scope']);
        assertSame('/somewhere-else/', $m['start_url']);
        assertSame('/somewhere-else/', $m['id']);
        assertTrue(
            str_starts_with((string) $m['icons'][0]['src'], '/somewhere-else/'),
            'icon src: ' . $m['icons'][0]['src']
        );
    } finally {
        putenv('RESM_BASE_PATH');
    }
});

test('the manifest carries a maskable icon as well as plain ones', function (): void {
    $purposes = array_map(static fn (array $i): string => (string) $i['purpose'], manifestDoc()['icons']);

    assertTrue(in_array('any', $purposes, true), 'at least one "any" icon');

    // Without one Android drops the square icon into a white circle, which is
    // neither the brand nor legible at a glance on a home screen.
    assertTrue(in_array('maskable', $purposes, true), 'a maskable icon');
});

test('every icon the manifest names actually ships', function (): void {
    $root = dirname(__DIR__);

    foreach (manifestDoc()['icons'] as $icon) {
        // Strip the mount point back off to get at the file on disk.
        $relative = substr((string) $icon['src'], strlen('/resm/'));
        $file = $root . '/public/' . $relative;

        assertTrue(is_file($file), "manifest names {$icon['src']}, which is not in public/");

        $size = getimagesize($file);
        assertSame(
            $icon['sizes'],
            $size[0] . 'x' . $size[1],
            "{$relative} is not the size the manifest claims"
        );
    }
});

test('the icons a phone reads outside the manifest ship too', function (): void {
    $root = dirname(__DIR__) . '/public/assets/icons/';

    // iOS never reads the manifest for the home-screen icon, and the favicon
    // is what stops the browser probing the document root for /favicon.ico —
    // a path this app does not own (CLAUDE.md).
    assertTrue(is_file($root . 'apple-touch-icon.png'), 'apple-touch-icon.png');
    assertTrue(is_file($root . 'favicon.png'), 'favicon.png');
});
