<?php

declare(strict_types=1);

namespace Resm;

/**
 * The tarmac map viewer (spec 6.5, 11.4).
 *
 * The drawing itself is content Rodeo Express still owes and has the longest
 * lead time of anything outstanding — 98 positions have to be marked on a site
 * plan before any of it can be traced. So this is built around its absence:
 * the viewer works now, shows a plainly-labelled placeholder, and the real
 * file drops in without a code change.
 *
 * What is NOT here is a drawn tarmac. Inventing a layout would make the screen
 * look finished, and a committeeman who learns a made-up map is worse off than
 * one who knows he has not got a map — he would walk confidently to the wrong
 * place.
 *
 * THE CONTRACT, for whoever drops the real file in:
 *
 *   1. Save it at public/assets/map/tarmac.svg.
 *   2. Every position is an element whose id is exactly that position's
 *      `map_ref` value in the database.
 *   3. Set map_ref on each of the 98 rows to match. bin/gen-position-seed.php
 *      is where the position list is generated from.
 *
 * Nothing else. The viewer inlines the file, map.js marks the user's own
 * element and their group's, and app.css colours them.
 */
final class TarmacMap
{
    /** Relative to public/, so App::asset and the service worker both find it. */
    public const FILE = 'map/tarmac.svg';

    public static function path(App $app): string
    {
        return $app->publicPath('assets/' . self::FILE);
    }

    public static function isAvailable(App $app): bool
    {
        return self::read($app) !== null;
    }

    /**
     * The drawing, ready to inline, or null when there is nothing to show.
     *
     * Inlined rather than put in an <img> because the highlight has to reach
     * individual elements inside it, and an img is opaque to both CSS and
     * script.
     */
    public static function read(App $app): ?string
    {
        $file = self::path($app);
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $svg = (string) file_get_contents($file);
        if ($svg === '' || !str_contains($svg, '<svg')) {
            return null;
        }

        // Belt and braces. The page's CSP is script-src 'self', so an inline
        // script inside the SVG would not run anyway — but a drawing exported
        // from a CAD tool has no business carrying one, and refusing is a
        // clearer signal than silently serving something that was tampered
        // with (spec 10.5).
        if (stripos($svg, '<script') !== false || stripos($svg, 'javascript:') !== false) {
            error_log('[resm] tarmac.svg contains script and was not rendered');

            return null;
        }

        return $svg;
    }

    /**
     * An id safe to hand to querySelector and to a CSS selector.
     *
     * map_ref comes from our own database, but it is typed in by hand
     * alongside the drawing, and a stray quote or space in one row should mean
     * "no highlight" rather than a broken selector on every screen.
     */
    public static function ref(?string $mapRef): ?string
    {
        if ($mapRef === null || $mapRef === '') {
            return null;
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,59}$/', $mapRef) === 1 ? $mapRef : null;
    }

    /**
     * What Rodeo Express still owes, in the words of spec 11.4.
     *
     * On the screen rather than only in the document, because the person who
     * can supply it is far more likely to open the app than to open the spec —
     * and every time this placeholder is seen is a chance to ask for the thing
     * that would replace it.
     *
     * @return array<int, string>
     */
    public static function needed(): array
    {
        return [
            'A scaled site drawing of the tarmac — lanes, tent, gates, crosswalks, '
                . 'bridge and the named areas. A CAD export, a site plan, or a clean '
                . 'hand drawing on graph paper all work as a starting point.',
            'A marked location for each of the 98 positions. Print the position list, '
                . 'print the drawing, and mark each position on it by hand.',
            'Which way is north, and which direction guests arrive from — '
                . 'committeemen navigate by landmark, so it should be oriented the way '
                . 'people actually stand.',
            'Any labels that should appear on the map itself rather than only on tap.',
        ];
    }
}
