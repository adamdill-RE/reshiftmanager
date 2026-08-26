<?php

declare(strict_types=1);

/**
 * The Officer Menu's routes (spec 6.9), required by app/routes.php with the
 * router in scope.
 *
 * A separate file because 6.9 is eleven screens and the main table was already
 * long. The rules from routes.php hold unchanged here: every handler resolves
 * its own user and re-checks role and team scope server-side, and every POST
 * verifies a CSRF token before it changes anything.
 *
 * The team check is the one worth naming. Every screen below goes through
 * officerContext(), which resolves the team from the query string against the
 * list Access permits and then calls Access::require on whatever it resolved.
 * There is no path through this file where a team id reaches a query before
 * somebody has asked whether this officer may act on it.
 */

use Resm\App;
use Resm\Auth\Access;
use Resm\Auth\Capability;
use Resm\Csrf;
use Resm\Http\Request;
use Resm\Http\Response;
use Resm\Officer\Coverage;
use Resm\Officer\OfficerMenu;
use Resm\Officer\OfficerShift;
use Resm\Officer\PhaseControl;
use Resm\Shift\Window;
use Resm\View;

/** @var Resm\Http\Router $router */

// ---------------------------------------------------------------------------
// Officer Menu (spec 6.9)
// ---------------------------------------------------------------------------

$router->get('officer', static function (App $app, Request $request): Response {
    $ctx = officerContext($app, $request, Capability::ViewTeamRoster);
    if ($ctx instanceof Response) {
        return $ctx;
    }

    return Response::html((new View($app))->render('officer/index', $ctx + [
        'title' => 'Officer Menu',
        'tiles' => OfficerMenu::tilesFor(
            $app,
            $ctx['user'],
            $ctx['team'] === null ? null : (int) $ctx['team']['id'],
            $ctx['shift'] === null ? null : (int) $ctx['shift']['id'],
        ),
        'back' => ['url' => $app->url(''), 'label' => 'Main Menu'],
    ]));
});

// ---------------------------------------------------------------------------
// Phase control (spec 6.9.1, rules in 5.2)
// ---------------------------------------------------------------------------

$router->post('officer/phase', static function (App $app, Request $request): Response {
    $ctx = officerContext($app, $request, Capability::TogglePhase);
    if ($ctx instanceof Response) {
        return $ctx;
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return officerFailure($app, $ctx, 'That page went stale. Try again.', 400);
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to change the phase of.', 422);
    }

    $wanted = (string) $request->input('phase', '');
    $result = officerPhaseControl($app)->set(
        $ctx['user'],
        $ctx['shift'],
        $wanted,
        $request->input('confirm') !== null,
    );

    // Moving backward out of Bump and Run asks first (spec 5.2). The question
    // is a page rather than a dialog: it survives a reload, and it works with
    // no JavaScript at all.
    if ($result['confirm']) {
        return Response::html((new View($app))->render('officer/phase-confirm', $ctx + [
            'title' => 'Switch back to Unload?',
            'target' => $wanted,
            'back' => officerBack($app, $ctx),
        ]));
    }

    if (!$result['ok']) {
        return officerFailure($app, $ctx, $result['error'] ?? 'That phase change did not go through.', 422);
    }

    $extra = 'phase_set=' . rawurlencode($result['phase'] ?? '');
    if ($result['seeded'] > 0) {
        $extra .= '&seeded=' . $result['seeded'];
    }

    return Response::redirect(officerUrl($app, $ctx, (string) $request->input('return', 'officer'), $extra));
});

// ---------------------------------------------------------------------------
// Shared scaffolding
// ---------------------------------------------------------------------------

function officerShift(App $app): OfficerShift
{
    return new OfficerShift($app->db(), new Window($app->displayTimezone()));
}

function officerCoverage(App $app): Coverage
{
    return new Coverage($app->db());
}

function officerPhaseControl(App $app): PhaseControl
{
    return new PhaseControl($app->db(), new Resm\AuditLog($app->db()));
}

/**
 * Resolve the officer, the team, the shift and the coverage figures — or the
 * response to send instead.
 *
 * Returns a Response for a signed-out user and for an Admin whose season has
 * no teams yet; throws AccessDenied, which the front controller turns into a
 * 403, for anyone who may not act on the team that was resolved. Callers check
 * the type, so forgetting the guard is a type error rather than an open door.
 *
 * @return array{
 *     user: Resm\Auth\Identity, season: array<string, mixed>,
 *     teams: array<int, array<string, mixed>>, team: array<string, mixed>|null,
 *     shifts: array<int, array<string, mixed>>, shift: array<string, mixed>|null,
 *     phase: string, coverage: array<string, mixed>|null,
 *     error: ?string, notice: ?string
 * }|Response
 */
function officerContext(App $app, Request $request, Capability $capability): array|Response
{
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }

    // A committeeman, and an officer assigned to no team at all, get []. Both
    // are denials rather than empty screens: there is no team anywhere they
    // could act on, so there is nothing to show them.
    if (Access::teamsFor($user, $capability) === []) {
        Access::require($user, $capability, null);
    }

    $season = adminSeasons($app)->active();
    if ($season === null) {
        return Response::html(officerEmpty(
            $app,
            'No active season',
            'Teams, shifts and boards all hang off a season. An administrator creates and activates one first.'
        ), 422);
    }

    $resolved = officerShift($app)->context(
        $user,
        (int) $season['id'],
        $capability,
        officerIntInput($request, 'team'),
        officerIntInput($request, 'shift'),
    );

    if ($resolved['team'] === null) {
        // Only an Admin reaches this: an officer with no teams was denied
        // above. It means the season has no active teams yet.
        return Response::html(officerEmpty(
            $app,
            'No teams yet',
            'This season has no active teams. Create one under Manage Teams.'
        ), 422);
    }

    // The real guard, on the team that was actually resolved rather than the
    // one that was asked for.
    Access::require($user, $capability, (int) $resolved['team']['id']);

    $shift = $resolved['shift'];
    $phase = $shift === null ? 'unload' : (string) $shift['current_phase'];

    return $resolved + [
        'user' => $user,
        'season' => $season,
        'phase' => $phase,
        'coverage' => $shift === null ? null : officerCoverage($app)->forShift(
            (int) $shift['id'],
            (int) $resolved['team']['id'],
            (int) $season['id'],
            $phase,
        ),
        'error' => null,
        'notice' => null,
        // The team and shift selectors submit on change. An onchange attribute
        // would be blocked by the CSP (script-src 'self'), so it is a file.
        'scripts' => ['js/picker.js'],
    ];
}

function officerIntInput(Request $request, string $key): ?int
{
    $raw = $request->input($key);

    return is_string($raw) && $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
}

/** A URL on another officer screen, carrying the team and shift being viewed. */
function officerUrl(App $app, array $ctx, string $path, string $extra = ''): string
{
    return $app->url($path) . OfficerMenu::query(
        $ctx['team'] === null ? null : (int) $ctx['team']['id'],
        $ctx['shift'] === null ? null : (int) $ctx['shift']['id'],
        $extra,
    );
}

/** @return array{url: string, label: string} */
function officerBack(App $app, array $ctx): array
{
    return ['url' => officerUrl($app, $ctx, 'officer'), 'label' => 'Officer Menu'];
}

/**
 * A refused write, rendered back onto the screen it came from.
 *
 * The message says what happened and the board underneath it is re-read from
 * the database, so an officer can see for himself that nothing was written.
 */
function officerFailure(App $app, array $ctx, string $message, int $status): Response
{
    return Response::html((new View($app))->render('officer/index', $ctx + [
        'title' => 'Officer Menu',
        'error' => $message,
        'tiles' => OfficerMenu::tilesFor(
            $app,
            $ctx['user'],
            $ctx['team'] === null ? null : (int) $ctx['team']['id'],
            $ctx['shift'] === null ? null : (int) $ctx['shift']['id'],
        ),
        'back' => ['url' => $app->url(''), 'label' => 'Main Menu'],
    ]), $status);
}

function officerEmpty(App $app, string $heading, string $message): string
{
    return (new View($app))->render('error', [
        'title' => $heading,
        'heading' => $heading,
        'message' => $message,
        'back' => ['url' => $app->url(''), 'label' => 'Main Menu'],
    ]);
}
