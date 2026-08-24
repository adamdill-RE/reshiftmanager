<?php

declare(strict_types=1);

/**
 * Template helpers. Required by app/bootstrap.php, since the autoloader only
 * knows how to find classes.
 */

if (!function_exists('e')) {
    /**
     * Escape a value for HTML output.
     *
     * Every value rendered into a template goes through this — names, position
     * labels, broadcast text, all of it. Spec 10.5 states it as a rule and the
     * short name is deliberate: a long one invites skipping.
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('icon')) {
    /**
     * Inline SVG for a menu tile.
     *
     * Inline rather than an icon font or sprite file: no extra request on a
     * saturated cell network, no flash of missing glyph, and it inherits
     * currentColor so it follows the theme. Unknown names return nothing
     * rather than a broken image.
     */
    function icon(string $name): string
    {
        $paths = [
            'shield'   => '<path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z"/>',
            'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>',
            'check'    => '<circle cx="12" cy="12" r="8"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
            'pin'      => '<path d="M12 21s6-5.3 6-10a6 6 0 1 0-12 0c0 4.7 6 10 6 10z"/><circle cx="12" cy="11" r="2.2"/>',
            'calendar' => '<rect x="4" y="6" width="16" height="15" rx="2"/><path d="M4 11h16M9 3v4M15 3v4"/>',
            'info'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/>',
            'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M18.4 5.6l-1.8 1.8M7.4 16.6l-1.8 1.8"/>',
        ];

        if (!isset($paths[$name])) {
            return '';
        }

        return '<svg class="tile__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $paths[$name]
            . '</svg>';
    }
}
