<?php

declare(strict_types=1);

/**
 * Generate the PWA icon set.
 *
 * The icons themselves are committed — the host has no build step and nothing
 * may require one to run (CLAUDE.md) — so this script is not part of any
 * pipeline. It exists so that the icons are reproducible rather than folklore,
 * and so that swapping the placeholder wordmark for the real thing is one edit
 * and one command.
 *
 * That swap is expected. Spec 11.5 #8 is still open: whether Rodeo Express has
 * a committee logo to use in place of the wordmark. Until it is answered these
 * are Ink lettering on Rodeo Orange — which is the palette's own rule, since
 * Rodeo Orange takes dark text and never white (spec 9.1).
 *
 *   php bin/gen-icons.php
 */

$out = dirname(__DIR__) . '/public/assets/icons';
if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}

const ORANGE = [0xEF, 0x76, 0x22];
const INK    = [0x2B, 0x20, 0x18];

$fonts = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
];
$font = null;
foreach ($fonts as $candidate) {
    if (is_file($candidate)) {
        $font = $candidate;
        break;
    }
}

if ($font === null) {
    fwrite(STDERR, "No bold sans TTF found; install fonts-dejavu-core and re-run.\n");
    exit(1);
}

/**
 * @param float $inset fraction of the edge to keep the mark clear of.
 *
 * A maskable icon is cropped to whatever shape the launcher likes — a circle
 * on most Android launchers — so the mark has to sit inside the safe zone or
 * the corners of the lettering get shaved off. Everything outside it is still
 * painted; it is bleed, not padding.
 */
function icon(string $path, int $size, string $font, float $inset): void
{
    $im = imagecreatetruecolor($size, $size);
    imagealphablending($im, true);

    $bg = imagecolorallocate($im, ...ORANGE);
    $fg = imagecolorallocate($im, ...INK);
    imagefilledrectangle($im, 0, 0, $size, $size, $bg);

    $text = 'RE';
    $safe = $size * (1 - 2 * $inset);

    // Measured rather than guessed: the point size that makes "RE" fill the
    // safe zone depends on the font that was actually found, and a hard-coded
    // ratio would clip on one and swim on another.
    $points = $safe;
    for ($i = 0; $i < 24; $i++) {
        $box = imagettfbbox($points, 0, $font, $text);
        $width = $box[2] - $box[0];
        $height = $box[1] - $box[7];
        $scale = min($safe / max($width, 1), $safe / max($height, 1));

        if (abs($scale - 1.0) < 0.01) {
            break;
        }
        $points *= $scale;
    }

    $box = imagettfbbox($points, 0, $font, $text);
    $width = $box[2] - $box[0];
    $height = $box[1] - $box[7];
    $x = (int) round(($size - $width) / 2 - $box[0]);
    $y = (int) round(($size - $height) / 2 - $box[7]);

    imagettftext($im, $points, 0, $x, $y, $fg, $font, $text);

    imagepng($im, $path);
    imagedestroy($im);
    chmod($path, 0644);

    printf("  %-28s %dx%d\n", basename($path), $size, $size);
}

echo "Writing icons to public/assets/icons/\n";

// The two the manifest names as "any": a launcher shows them as drawn, so the
// mark runs closer to the edge.
icon($out . '/icon-192.png', 192, $font, 0.18);
icon($out . '/icon-512.png', 512, $font, 0.18);

// Maskable. The 20% safe-zone inset is what the spec for the purpose calls for.
icon($out . '/icon-maskable-512.png', 512, $font, 0.28);

// iOS ignores the manifest for the home-screen icon and reads this instead,
// and it composites onto its own background, so it must be full-bleed.
icon($out . '/apple-touch-icon.png', 180, $font, 0.18);

// Named in the page head so the browser stops probing the DOCUMENT ROOT for
// /favicon.ico — which is not ours: the app is mounted at /resm/ and the root
// belongs to the domain (CLAUDE.md).
icon($out . '/favicon.png', 64, $font, 0.14);

echo "Done.\n";
