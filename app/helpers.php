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
