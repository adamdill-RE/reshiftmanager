<?php

declare(strict_types=1);

namespace Resm\Pwa;

use Resm\App;

/**
 * What the service worker caches, and the version it caches it under
 * (spec 10.3).
 *
 * The list is discovered from disk rather than written out by hand, because a
 * hand-written list is a list that goes out of date silently: the missing file
 * is not an error, it is simply a screen that stops working offline, and the
 * only person who finds out is standing on a tarmac with no signal.
 *
 * The version is the load-bearing part. .htaccess puts a one-year expiry on
 * css, js and images, and App::asset stamps every URL with the file's mtime —
 * so an asset URL is already immutable and safe to cache forever. Layering a
 * service worker on top of that is where a stale shell surviving a deploy
 * comes from, and the answer is that the version is derived FROM those same
 * mtimes. Change any asset and every cache name changes with it; the old
 * caches are deleted on activate and there is nothing left to serve stale.
 */
final class Shell
{
    /**
     * Pages worth having offline.
     *
     * Deliberately short. These are cached as rendered HTML, which is
     * per-user, so each one is a thing that has to be cleared on sign-out —
     * and a list that grows without that being thought about is how one
     * volunteer's shift ends up on screen for the next man to use the phone.
     *
     * Spec 6.5: My Shift Status renders with zero connectivity. Spec 6.4:
     * check in and out works fully offline. And the map, which spec 10.3 names
     * directly — it is the screen most likely to be wanted in the one place
     * there is no signal, by the man least likely to know his way around.
     */
    public const PAGES = ['my-shift', 'check-in', 'map'];

    /**
     * Every shipped asset, as the versioned URL the pages actually request.
     *
     * @return array<int, string>
     */
    public static function assets(App $app): array
    {
        $root = $app->root . '/public/assets';
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if (!$file->isFile()) {
                continue;
            }

            // Relative to public/assets, so it can go back through
            // App::asset and come out with the same ?v= stamp the page used.
            // A precached URL that differs from the requested one by a query
            // string is a cache that never hits.
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }

        sort($files);

        return array_map(static fn (string $path): string => $app->asset($path), $files);
    }

    /**
     * A short stamp over everything cached, for naming the caches.
     *
     * Built from the asset URLs — which carry their own mtimes — plus this
     * worker's own source, so editing the caching strategy invalidates the
     * caches it wrote under the old one.
     */
    public static function version(App $app): string
    {
        $material = implode("\n", self::assets($app));

        $worker = $app->root . '/public/sw.js';
        if (is_file($worker)) {
            $material .= "\n" . filemtime($worker);
        }

        return substr(hash('sha256', $material), 0, 12);
    }

    /**
     * The document the worker fetches on install.
     *
     * @return array<string, mixed>
     */
    public static function document(App $app): array
    {
        return [
            'version' => self::version($app),
            'base' => $app->basePath(),
            'assets' => self::assets($app),
            'pages' => array_map(static fn (string $p): string => $app->url($p), self::PAGES),
            // The polling endpoint is named so the worker can be certain never
            // to cache it. An answer to "is my copy current" served from a
            // cache is the one answer that cannot be true (spec 6.3).
            'never' => [$app->url('api/'), $app->url('login'), $app->url('logout'),
                        $app->url('setup'), $app->url('status')],
        ];
    }
}
