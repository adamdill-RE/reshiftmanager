<?php

declare(strict_types=1);

/**
 * The route table.
 *
 * Handlers take (App, Request, array $params) and return a Response. Matching
 * a route says nothing about permission: every handler that reads team data
 * checks role and team scope itself, server-side, on every request.
 *
 * Returns the configured router to public/index.php.
 */

use Resm\App;
use Resm\Diagnostics;
use Resm\Http\Request;
use Resm\Http\Response;
use Resm\Http\Router;
use Resm\View;

$router = new Router();

$router->get('', static function (App $app, Request $request): Response {
    $view = new View($app);
    $db = $app->db();

    return Response::html($view->render('home', [
        'title'        => 'Rodeo Express',
        'positions'    => (int) $db->value('SELECT COUNT(*) FROM position'),
        'groups'       => (int) $db->value('SELECT COUNT(*) FROM position_group'),
        'phaseRecords' => (int) $db->value('SELECT COUNT(*) FROM position_phase'),
    ]));
});

/*
 * Deployment self-check. Reports the runtime, the session cookie settings and
 * whether the migrations ran — the things a file-copy deploy gets wrong.
 *
 * It names software versions and paths, so it is not public: it answers only
 * with the key from config.local.php, and 404s (rather than 403s) without it,
 * which tells a passer-by nothing about whether the page exists.
 */
$router->get('status', static function (App $app, Request $request): Response {
    $expected = $app->config->get('app.status_key');
    $provided = $request->input('key', '');

    $permitted = $app->isDebug()
        || (is_string($expected) && $expected !== '' && is_string($provided) && hash_equals($expected, $provided));

    if (!$permitted) {
        return notFoundResponse($app);
    }

    $checks = (new Diagnostics($app))->run();

    return Response::html(
        (new View($app))->render('status', [
            'title'   => 'Deployment status',
            'checks'  => $checks,
            'overall' => Diagnostics::worst($checks),
        ]),
        Diagnostics::worst($checks) === Diagnostics::FAIL ? 503 : 200
    );
});

$router->notFound(static fn (App $app): Response => notFoundResponse($app));

function notFoundResponse(App $app): Response
{
    return Response::html(
        (new View($app))->render('error', [
            'title'   => 'Not found',
            'heading' => 'Not found',
            'message' => 'That page is not part of the application.',
        ]),
        404
    );
}

return $router;
