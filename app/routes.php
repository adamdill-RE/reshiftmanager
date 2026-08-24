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
// First-run setup, over the web
//
// This account has no SSH and no Terminal, so bin/migrate.php and
// bin/set-admin-pin.php cannot be reached at all. Without this route the
// application cannot be brought up: the schema would never be created and the
// seeded administrator would stay locked forever.
//
// It is guarded by app.setup_key rather than by a login, because before
// migrations run there is no user table to log in against. Whoever holds that
// key can take the master admin account, so the key lives only in
// config.local.php - which is not in git and not web-readable - and removing
// it makes this route stop existing.
// ---------------------------------------------------------------------------

$router->get('setup', static function (App $app, Request $request): Response {
    if (!setupPermitted($app, $request)) {
        return notFoundResponse($app);
    }

    return setupPage($app, $request);
});

$router->post('setup', static function (App $app, Request $request): Response {
    if (!setupPermitted($app, $request)) {
        return notFoundResponse($app);
    }

    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return setupPage($app, $request, error: 'That page went stale. Reload and try again.');
    }

    $action = (string) $request->input('action', '');

    if ($action === 'migrate') {
        return setupMigrate($app, $request);
    }

    if ($action === 'set-pin') {
        return setupSetPin($app, $request);
    }

    return setupPage($app, $request, error: 'Unknown action.');
});

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

/**
 * The key must be configured AND match. There is deliberately no debug
 * bypass: this route changes data, and "it was only on because debug was on"
 * is not a story anyone wants to tell about an admin account.
 */
function setupPermitted(App $app, Request $request): bool
{
    $expected = $app->config->get('app.setup_key');
    $provided = $request->input('key', '');

    return is_string($expected) && $expected !== ''
        && is_string($provided) && $provided !== ''
        && hash_equals($expected, $provided);
}

/**
 * @param array<int, string> $log
 */
function setupPage(
    App $app,
    Request $request,
    ?string $error = null,
    ?string $notice = null,
    array $log = [],
): Response {
    $key = (string) $request->input('key', '');
    $state = [
        'dbError' => null,
        'dbDetail' => null,
        // What the application is actually trying, read back from the merged
        // configuration. If config.local.php is not being picked up, these
        // show the committed defaults instead and the problem is obvious at a
        // glance. The password is not among them and never will be.
        'dbTarget' => sprintf(
            '%s@%s / %s',
            $app->config->string('db.user'),
            $app->config->string('db.host'),
            $app->config->string('db.name')
        ),
        'applied' => [],
        'pending' => [],
        'drift' => [],
        'admin' => null,
    ];

    try {
        $db = $app->db();
        $db->value('SELECT 1');

        $migrator = new Resm\Migrator($db, $app->root . '/db/migrations');
        $migrator->ensureRegistry();
        $state['applied'] = array_keys($migrator->applied());
        $state['pending'] = array_map('basename', $migrator->pending());
        $state['drift'] = $migrator->drift();

        // Only meaningful once the schema exists.
        if ($state['applied'] !== []) {
            $state['admin'] = $db->one(
                "SELECT member_id, first_name, last_name, is_active, pin_hash
                 FROM `user` WHERE role = 'admin' ORDER BY id LIMIT 1"
            );
        }
    } catch (Throwable $e) {
        // Before config.local.php is right, this is the message that tells the
        // administrator what to fix.
        $state['dbError'] = $e->getMessage();

        // Database deliberately replaces the driver's message with a generic
        // one, because it carries the DSN and would otherwise reach a log or a
        // public error page. That is right everywhere except here: this page
        // is already behind the setup key, the administrator has no shell to
        // read a log with, and "Database connection failed." names none of the
        // four things that could be wrong. The driver says which.
        $cause = $e->getPrevious();
        if ($cause !== null) {
            $state['dbDetail'] = $cause->getMessage();
        }
    }

    return Response::html((new View($app))->render('setup', [
        'title' => 'Setup',
        'key' => $key,
        'state' => $state,
        'error' => $error,
        'notice' => $notice,
        'log' => $log,
    ]))->withHeader('X-Robots-Tag', 'noindex, nofollow');
}

function setupMigrate(App $app, Request $request): Response
{
    $log = [];

    try {
        $migrator = new Resm\Migrator($app->db(), $app->root . '/db/migrations');
        $applied = $migrator->migrate(static function (string $line) use (&$log): void {
            $log[] = $line;
        });
    } catch (Throwable $e) {
        return setupPage($app, $request, error: $e->getMessage(), log: $log);
    }

    return setupPage(
        $app,
        $request,
        notice: $applied === []
            ? 'Database is already up to date.'
            : sprintf('Applied %d migration%s.', count($applied), count($applied) === 1 ? '' : 's'),
        log: $log
    );
}

/**
 * Sets an administrator's PIN. Same rules as bin/set-admin-pin.php, and the
 * same refusal to touch a non-administrator: officers reset a committeeman
 * from the roster screen, where it is audited against them.
 */
function setupSetPin(App $app, Request $request): Response
{
    $memberId = trim((string) $request->input('member_id', ''));
    $pin = (string) $request->input('pin', '');
    $confirm = (string) $request->input('confirm', '');

    if (!Resm\Auth\Pin::isValid($pin)) {
        return setupPage($app, $request, error: 'A PIN is exactly four digits.');
    }

    if (!hash_equals($pin, $confirm)) {
        return setupPage($app, $request, error: 'The two PINs did not match.');
    }

    try {
        $db = $app->db();
        $user = $db->one(
            'SELECT id, role FROM `user` WHERE member_id = :member_id',
            ['member_id' => $memberId]
        );

        if ($user === null) {
            return setupPage($app, $request, error: "No account with Member ID {$memberId}. Have the migrations run?");
        }

        if (Resm\Auth\Role::from((string) $user['role']) !== Resm\Auth\Role::Admin) {
            return setupPage($app, $request, error: "{$memberId} is not an administrator.");
        }

        $userId = (int) $user['id'];
        $cost = $app->config->int('auth.pin_cost', 11);
        $now = gmdate('Y-m-d H:i:s');

        $db->transaction(static function (Resm\Database $db) use ($userId, $pin, $cost, $now): void {
            $db->execute(
                'UPDATE `user` SET pin_hash = :hash, pin_changed_at = :now WHERE id = :id',
                ['hash' => Resm\Auth\Pin::hash($pin, $cost), 'now' => $now, 'id' => $userId]
            );
            // As with changing a PIN in the app, every existing session for
            // this account stops working.
            $db->execute(
                'UPDATE auth_token SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL',
                ['now' => $now, 'id' => $userId]
            );
        });

        (new Resm\AuditLog($db))->record($userId, 'pin_set_via_setup', 'user', $userId);
    } catch (Throwable $e) {
        return setupPage($app, $request, error: $e->getMessage());
    }

    return setupPage(
        $app,
        $request,
        notice: "PIN set for {$memberId}. Sign in, then remove setup_key from config.local.php."
    );
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
