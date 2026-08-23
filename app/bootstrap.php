<?php

declare(strict_types=1);

/**
 * The one entry point into application code. public/index.php and every
 * bin/*.php script come through here and receive a booted Resm\App.
 *
 * On the server this file is at /home/reshiftmanager/resm-app/app/bootstrap.php
 * — outside public_html, and so not web-reachable by any URL. Locally it is at
 * <repo>/app/bootstrap.php. Both resolve RESM_ROOT to the directory holding
 * app/, config/ and db/.
 */

// Production is PHP 8.2.33. Fail loudly rather than half-running on something
// older that lacks readonly properties or enums.
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Rodeo Express requires PHP 8.1 or later; this server runs ' . PHP_VERSION . '.');
}

define('RESM_ROOT', dirname(__DIR__));

// Store and compare in UTC everywhere. Display conversion to America/Chicago
// happens at the edge, via App::forDisplay().
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// No Composer on this host by design, so the autoloader is ours: Resm\Foo\Bar
// maps to app/src/Foo/Bar.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Resm\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = RESM_ROOT . '/app/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require RESM_ROOT . '/app/helpers.php';

$app = \Resm\App::boot(RESM_ROOT);

// display_errors is off on the server and log_errors on, writing to
// /home/reshiftmanager/logs/php.error.log. Keep it that way in production and
// let the front controller render its own error page.
ini_set('display_errors', $app->isDebug() ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

return $app;
