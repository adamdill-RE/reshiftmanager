<?php

declare(strict_types=1);

/**
 * Front controller — the only PHP file under the document root.
 *
 * On the server this is /home/reshiftmanager/public_html/resm/index.php, and
 * the application it loads lives at /home/reshiftmanager/resm-app/, outside
 * public_html entirely. Everything except this directory is unreachable by
 * URL, which is the point.
 */

/*
 * Find the application root.
 *
 * Locally, public/ sits inside the repository, so the parent directory holds
 * app/. On the server, public/ has been copied to public_html/resm/ and the
 * application to a sibling of public_html. Both are probed, so the same file
 * works in both places with nothing to configure. Set RESM_APP_ROOT in the
 * environment to override.
 */
$candidates = [];

$fromEnv = getenv('RESM_APP_ROOT');
if (is_string($fromEnv) && $fromEnv !== '') {
    $candidates[] = $fromEnv;
}

$candidates[] = dirname(__DIR__);               // <repo>/            (local)
$candidates[] = dirname(__DIR__, 2) . '/resm-app'; // ~/resm-app/     (server)

$appRoot = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $appRoot = $candidate;
        break;
    }
}

if ($appRoot === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Rodeo Express is not installed correctly: the application directory was not found.\n"
        . "Looked for app/bootstrap.php in:\n  - " . implode("\n  - ", $candidates) . "\n"
    );
}

/** @var Resm\App $app */
$app = require $appRoot . '/app/bootstrap.php';

// Session cookies must be configured before anything is sent, and the app is
// session-backed from the login screen onward.
$app->startSession();

$request = Resm\Http\Request::capture($app);

/** @var Resm\Http\Router $router */
$router = require $appRoot . '/app/routes.php';

try {
    $router->dispatch($app, $request)->send();
} catch (Throwable $e) {
    // display_errors is off on the server, so the detail goes to
    // /home/reshiftmanager/logs/php.error.log and the visitor gets a page.
    error_log(sprintf('[resm] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    if ($app->isDebug()) {
        throw $e;
    }

    Resm\Http\Response::html(
        (new Resm\View($app))->render('error', [
            'title'   => 'Something went wrong',
            'heading' => 'Something went wrong',
            'message' => 'The problem has been logged. Try again, and tell an officer if it keeps happening.',
        ]),
        500
    )->send();
}
