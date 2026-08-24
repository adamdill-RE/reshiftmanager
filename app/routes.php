<?php

declare(strict_types=1);

/**
 * The route table.
 *
 * Handlers take (App, Request, array $params) and return a Response. Two rules
 * hold everywhere:
 *
 *   Every handler that needs a signed-in user asks for one itself. Reaching a
 *   route says nothing about permission — a hidden tile is presentation, and
 *   the guard is the authorisation (spec 10.5).
 *
 *   Every POST verifies a CSRF token before it changes anything (spec 10.5).
 *
 * Returns the configured router to public/index.php.
 */

use Resm\App;
use Resm\Auth\Role;
use Resm\Csrf;
use Resm\Diagnostics;
use Resm\Http\Request;
use Resm\Http\Response;
use Resm\Http\Router;
use Resm\Menu;
use Resm\View;

$router = new Router();

// ---------------------------------------------------------------------------
// Main menu
// ---------------------------------------------------------------------------

$router->get('', static function (App $app, Request $request): Response {
    $user = $app->user();
    if ($user === null) {
        // Spec 6.1: the login screen is the landing page for any
        // unauthenticated request.
        return Response::redirect($app->url('login'));
    }

    return Response::html((new View($app))->render('menu', [
        'title' => 'Rodeo Express',
        'user' => $user,
        'tiles' => Menu::tilesFor($app, $user),
    ]));
});

// ---------------------------------------------------------------------------
// Signing in and out
// ---------------------------------------------------------------------------

$router->get('login', static function (App $app, Request $request): Response {
    if ($app->user() !== null) {
        return Response::redirect($app->url());
    }

    return Response::html(loginPage($app));
});

$router->post('login', static function (App $app, Request $request): Response {
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        // A form left open long enough for the session to lapse. Say so
        // plainly rather than showing a failure the user cannot act on.
        return Response::html(
            loginPage($app, 'That took too long and the page went stale. Try again.', (string) $request->input('member_id', '')),
            400
        );
    }

    $result = $app->auth()->attempt(
        (string) $request->input('member_id', ''),
        (string) $request->input('pin', ''),
        $request->input('remember') !== null,
    );

    if (!$result->ok) {
        return Response::html(
            loginPage($app, $result->message, (string) $request->input('member_id', '')),
            401
        );
    }

    // Redirect after a successful post, so a refresh on the menu does not
    // re-submit a PIN.
    return Response::redirect($app->url());
});

$router->post('logout', static function (App $app, Request $request): Response {
    if (Csrf::check($request->input(Csrf::FIELD))) {
        $app->auth()->logout();
    }

    return Response::redirect($app->url('login'));
});

// ---------------------------------------------------------------------------
// Tools (spec 6.7)
// ---------------------------------------------------------------------------

$router->get('tools', static function (App $app, Request $request): Response {
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }

    return Response::html(toolsPage($app, $user, notice: $request->input('changed') !== null
        ? 'Your PIN has been changed. Other devices have been signed out.'
        : null));
});

$router->post('tools/pin', static function (App $app, Request $request): Response {
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }

    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(toolsPage($app, $user, error: 'That page went stale. Try again.'), 400);
    }

    $result = $app->auth()->changePin(
        $user,
        (string) $request->input('current_pin', ''),
        (string) $request->input('new_pin', ''),
        (string) $request->input('confirm_pin', ''),
    );

    if (!$result->ok) {
        return Response::html(toolsPage($app, $user, error: $result->message), 422);
    }

    return Response::redirect($app->url('tools?changed=1'));
});

// ---------------------------------------------------------------------------
// Screens the build sequence has not reached
//
// Registered with the same role guard the real screen will use, so the Officer
// and Admin routes are already refusing the wrong role server-side rather than
// relying on the tile being hidden.
// ---------------------------------------------------------------------------

foreach (array_keys(Menu::SECTIONS) as $key) {
    $section = Menu::section($key);
    if ($section === null || $section['built']) {
        continue;
    }

    $router->get($key, static function (App $app, Request $request) use ($key, $section): Response {
        $user = $app->user();
        if ($user === null) {
            return Response::redirect($app->url('login'));
        }

        if (!Menu::visibleTo($user, $key)) {
            throw new Resm\Auth\AccessDenied(sprintf(
                'user %d (%s) may not open %s',
                $user->id,
                $user->role->value,
                $key
            ));
        }

        return Response::html((new View($app))->render('placeholder', [
            'title' => $section['label'],
            'heading' => $section['label'],
            'summary' => $section['summary'],
            'phase' => $section['phase'],
            'back' => ['url' => $app->url(), 'label' => 'Menu'],
        ]));
    });
}

// ---------------------------------------------------------------------------
// Deployment self-check
// ---------------------------------------------------------------------------

/*
 * Reports the runtime, the session cookie settings and whether the migrations
 * ran — the things a file-copy deploy gets wrong.
 *
 * It names software versions and paths, so it is not public: it answers only
 * with the key from config.local.php, and 404s rather than 403s without it,
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
            'title' => 'Deployment status',
            'checks' => $checks,
            'overall' => Diagnostics::worst($checks),
        ]),
        Diagnostics::worst($checks) === Diagnostics::FAIL ? 503 : 200
    );
});

$router->notFound(static fn (App $app): Response => notFoundResponse($app));

// ---------------------------------------------------------------------------

function loginPage(App $app, ?string $error = null, string $memberId = ''): string
{
    return (new View($app))->render('login', [
        'title' => 'Sign in',
        'error' => $error,
        'memberId' => $memberId,
        // Default on: a committeeman signs in once at the start of the season
        // (spec 3.2).
        'remember' => true,
        'scripts' => ['js/keypad.js'],
    ]);
}

function toolsPage(App $app, Resm\Auth\Identity $user, ?string $error = null, ?string $notice = null): string
{
    return (new View($app))->render('tools', [
        'title' => 'Tools',
        'user' => $user,
        'error' => $error,
        'notice' => $notice,
        'back' => ['url' => $app->url(), 'label' => 'Menu'],
    ]);
}

function notFoundResponse(App $app): Response
{
    return Response::html(
        (new View($app))->render('error', [
            'title' => 'Not found',
            'heading' => 'Not found',
            'message' => 'That page is not part of the application.',
        ]),
        404
    );
}

return $router;
