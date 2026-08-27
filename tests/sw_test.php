<?php

declare(strict_types=1);

use Resm\App;
use Resm\Pwa\Shell;

/**
 * The service worker's shell (spec 10.3).
 *
 * Two of the failures this guards against are silent by nature. A worker
 * served from the wrong directory installs perfectly and controls nothing; a
 * worker inheriting the one-year asset expiry works for a day and then pins a
 * stale shell on every phone until somebody clears their browser. Neither
 * throws, and neither shows up in a screenshot.
 */

function shellApp(): App
{
    return App::boot(dirname(__DIR__));
}

// ---------------------------------------------------------------------------
// Where the worker lives, which is what its scope is
// ---------------------------------------------------------------------------

test('the worker sits in public/, not under assets/', function (): void {
    $root = dirname(__DIR__) . '/public';

    // A worker's default scope is the directory it is served from. At
    // public/assets/js/sw.js it would control /resm/assets/js/ — it would
    // register without complaint and intercept nothing the app ever requests.
    assertTrue(is_file($root . '/sw.js'), 'public/sw.js must exist');
    assertTrue(!is_file($root . '/assets/js/sw.js'), 'a worker under assets/ could not control the app');
});

test('the worker is not part of the shell it caches', function (): void {
    foreach (Shell::assets(shellApp()) as $url) {
        assertTrue(
            !str_contains($url, 'sw.js'),
            "the worker must not precache itself: {$url}"
        );
    }
});

test('sw.js is exempt from the one-year expiry the other scripts get', function (): void {
    $htaccess = (string) file_get_contents(dirname(__DIR__) . '/public/.htaccess');

    // The rule it must escape.
    assertTrue(
        str_contains($htaccess, 'ExpiresByType application/javascript "access plus 1 year"'),
        'the assets rule is still there'
    );

    // A year-long cache on the file that decides what everything else caches
    // is a stale shell nobody can replace. The ?v= stamp in the registration
    // URL cannot save it: the browser would serve the cached bytes for that
    // URL too.
    $exempt = preg_match('/<Files\s+"sw\.js">(.*?)<\/Files>/s', $htaccess, $m) === 1;
    assertTrue($exempt, 'public/.htaccess must carry a <Files "sw.js"> block');
    assertTrue(str_contains($m[1], 'ExpiresActive Off'), 'the block must switch the expiry off');
    assertTrue(str_contains($m[1], 'no-cache'), 'the block must set no-cache');
});

// ---------------------------------------------------------------------------
// What it caches
// ---------------------------------------------------------------------------

test('every shipped asset is precached, at the URL the pages ask for', function (): void {
    $app = shellApp();
    $assets = Shell::assets($app);

    $onDisk = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($app->root . '/public/assets', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file->isFile()) {
            $onDisk[] = $file->getPathname();
        }
    }

    assertCount(count($onDisk), $assets, 'one precache entry per shipped file');

    foreach ($assets as $url) {
        // The ?v= stamp is not decoration. The pages request the stamped URL,
        // and a cache keyed on the unstamped one never hits — it would look
        // installed and fetch everything from the network anyway.
        assertTrue(str_contains($url, '?v='), "unstamped asset: {$url}");
        assertTrue(str_starts_with($url, $app->basePath()), "outside the mount point: {$url}");
    }
});

test('the polling endpoint is never cacheable', function (): void {
    $app = shellApp();
    $never = Shell::document($app)['never'];

    // The endpoint answers "is my copy current". A cached answer to that is
    // the one answer that cannot be true, and is exactly the stale screen
    // looking live that spec 6.3 exists to prevent.
    assertTrue(in_array($app->url('api/'), $never, true), 'api/ must be on the never list');

    // Signing in and out must reach the server too — a cached login page is
    // one that never lets go.
    assertTrue(in_array($app->url('login'), $never, true), 'login');
    assertTrue(in_array($app->url('logout'), $never, true), 'logout');
});

test('the cached pages are the ones the spec asks to work offline', function (): void {
    // Spec 6.5 caches My Shift Status entirely; 6.4 has check in and out
    // working fully offline; 10.3 names the tarmac map. Each entry is HTML
    // rendered for one person, so this list is also the list that has to be
    // cleared on sign-out — it is meant to stay short and to be noticed when
    // it grows.
    assertSame(['my-shift', 'check-in', 'map'], Shell::PAGES);
});

// ---------------------------------------------------------------------------
// The deploy story
// ---------------------------------------------------------------------------

test('the version changes when an asset changes', function (): void {
    $app = shellApp();
    $before = Shell::version($app);

    $file = $app->root . '/public/assets/css/app.css';
    $was = filemtime($file);
    try {
        touch($file, $was + 3600);
        clearstatcache(true, $file);

        // Files reach this host by copy, so nothing regenerates a version
        // number on deploy. It is derived from the assets' own mtimes instead,
        // which is what makes the worker reinstall and delete the caches the
        // last one wrote.
        assertTrue($before !== Shell::version($app), 'a changed asset must change the version');
    } finally {
        touch($file, $was);
        clearstatcache(true, $file);
    }
});

test('the version is stable when nothing has changed', function (): void {
    // The other half of it. A version that moved on its own would reinstall
    // the worker and throw away a working offline copy on every request.
    $app = shellApp();
    assertSame(Shell::version($app), Shell::version($app));
});

test('the shell follows the base path when the app is mounted elsewhere', function (): void {
    putenv('RESM_BASE_PATH=/somewhere-else/');
    try {
        $doc = Shell::document(App::boot(dirname(__DIR__)));

        assertSame('/somewhere-else/', $doc['base']);
        assertTrue(str_starts_with((string) $doc['pages'][0], '/somewhere-else/'), (string) $doc['pages'][0]);
        assertTrue(str_starts_with((string) $doc['assets'][0], '/somewhere-else/'), (string) $doc['assets'][0]);
        assertTrue(in_array('/somewhere-else/api/', $doc['never'], true), 'the never list moves too');
    } finally {
        putenv('RESM_BASE_PATH');
    }
});

test('the shell stays inside the payload budget', function (): void {
    $app = shellApp();
    $bytes = 0;

    foreach (Shell::assets($app) as $url) {
        $path = $app->root . '/public/' . substr(strtok($url, '?'), strlen($app->basePath()));
        $bytes += (int) filesize($path);
    }

    // Spec 10.6: application shell under 150 KB gzipped. This is the raw
    // total, so it is the loose end of that budget — but the worker precaches
    // all of it in one addAll on whatever signal a phone has in a car park,
    // and this is what notices the day somebody drops a photograph in.
    assertTrue($bytes < 400 * 1024, 'shell is ' . round($bytes / 1024) . 'KB raw');
});
