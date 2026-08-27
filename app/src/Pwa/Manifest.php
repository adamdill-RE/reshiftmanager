<?php

declare(strict_types=1);

namespace Resm\Pwa;

use Resm\App;

/**
 * The web app manifest (spec 10.1).
 *
 * Built rather than shipped as a static file, because every path inside it is
 * absolute — start_url, scope, each icon — and CLAUDE.md allows no hard-coded
 * site-root path anywhere. A committed manifest would be the one file with
 * /resm/ written into it, and the one thing that broke silently if the app
 * were mounted elsewhere: nothing throws, the app simply stops being
 * installable and nobody finds out until someone tries.
 */
final class Manifest
{
    /** Everything the launcher is told about the app. */
    public const THEME_COLOUR = '#EF7622';

    /**
     * The splash screen, and the one place the dark theme wins outright rather
     * than following the device. The harm is asymmetric: a dark splash in
     * daylight is unremarkable, and a white one at 02:00 is exactly the night
     * vision spec 9.2 exists to protect. There is no per-theme manifest to
     * choose between them with.
     */
    public const SPLASH_COLOUR = '#14100D';

    /** @return array<string, mixed> */
    public static function document(App $app): array
    {
        $icon = static fn (string $file): string => $app->url('assets/icons/' . $file);

        return [
            // A stable identity, so a later change to start_url does not read
            // as a different app and leave two icons on somebody's home screen.
            'id' => $app->basePath(),
            'name' => 'Rodeo Express Shift Management',
            'short_name' => 'Rodeo Shifts',
            'description' => 'Check in, see where you are standing, and run the board.',
            'lang' => 'en',
            'dir' => 'ltr',

            'start_url' => $app->basePath(),
            'scope' => $app->basePath(),
            'display' => 'standalone',

            'theme_color' => self::THEME_COLOUR,
            'background_color' => self::SPLASH_COLOUR,

            'icons' => [
                ['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                // Cropped to the launcher's own shape, so it is drawn with a
                // safe zone and bleeds to the edge. Without a maskable icon
                // Android drops the "any" one into a white circle, which is
                // neither the brand nor legible.
                [
                    'src' => $icon('icon-maskable-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];
    }
}
