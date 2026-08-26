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

    return Response::html((new View($app))->render('officer/index', array_merge($ctx, [
        'title' => 'Officer Menu',
        'tiles' => OfficerMenu::tilesFor(
            $app,
            $ctx['user'],
            $ctx['team'] === null ? null : (int) $ctx['team']['id'],
            $ctx['shift'] === null ? null : (int) $ctx['shift']['id'],
        ),
        'back' => ['url' => $app->url(''), 'label' => 'Main Menu'],
    ])));
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
        return Response::html((new View($app))->render('officer/phase-confirm', array_merge($ctx, [
            'title' => 'Switch back to Unload?',
            'target' => $wanted,
            'back' => officerBack($app, $ctx),
        ])));
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
// Assign Unload / Assign Bump and Run (spec 6.9.4)
//
// The screen that determines whether the application succeeds. Two taps per
// placement and no drag and drop -- which is the intuitive choice and the
// wrong one, being unreliable with gloves, on wet glass, and one-handed.
//
// Both modes are server-rendered sheets rather than anything scripted: tapping
// a vacant position opens a page of eligible people, tapping a name posts the
// placement. Two taps either way round, and it works with scripting off.
// ---------------------------------------------------------------------------

$router->get('officer/assign/{phase}', static function (App $app, Request $request, array $params): Response {
    $ctx = officerContext($app, $request, Capability::AssignPositions);
    if ($ctx instanceof Response) {
        return $ctx;
    }

    $phase = (string) ($params['phase'] ?? '');
    if (!PhaseControl::isPhase($phase)) {
        return Response::html(officerEmpty($app, 'Not found', 'That is not a phase.'), 404);
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to assign anybody to.', 422);
    }

    return Response::html(assignPage($app, officerWithPhase($app, $ctx, $phase), $request));
});

$router->post('officer/assign/{phase}', static function (App $app, Request $request, array $params): Response {
    $ctx = officerContext($app, $request, Capability::AssignPositions);
    if ($ctx instanceof Response) {
        return $ctx;
    }

    $phase = (string) ($params['phase'] ?? '');
    if (!PhaseControl::isPhase($phase)) {
        return Response::html(officerEmpty($app, 'Not found', 'That is not a phase.'), 404);
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to assign anybody to.', 422);
    }

    $ctx = officerWithPhase($app, $ctx, $phase);

    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(
            assignPage($app, $ctx, $request, error: 'That page went stale. Try again.'),
            400
        );
    }

    $positionId = officerIntInput($request, 'position_id') ?? 0;
    $userId = officerIntInput($request, 'user_id') ?? 0;
    $service = officerAssignments($app);

    $result = match ((string) $request->input('action', '')) {
        'assign' => $service->assign($ctx['user'], $ctx['shift'], $phase, $positionId, $userId),
        'vacate' => $service->vacate($ctx['user'], $ctx['shift'], $phase, $positionId, $userId),
        default => ['ok' => false, 'error' => 'Unknown action.', 'carried' => false, 'vacated' => 0, 'taken' => false],
    };

    if (!$result['ok']) {
        // A lost race re-reads the board underneath the message, so the officer
        // can see who did get the spot rather than being told to guess.
        return Response::html(
            assignPage($app, officerWithPhase($app, $ctx, $phase), $request, error: $result['error']),
            $result['taken'] ? 409 : 422
        );
    }

    // Redirect after posting, so a reload on the tarmac does not re-assign.
    $vacated = (string) $request->input('action', '') === 'vacate';
    $extra = 'done=' . ($vacated ? 'vacated' : 'placed');
    if ($result['carried']) {
        $extra .= '&carried=1';
    }
    if (($request->input('mode') ?? '') !== '') {
        $extra .= '&mode=' . rawurlencode((string) $request->input('mode'));
    }

    // Taking a man off leaves the spot empty, and the officer almost always
    // wants to put somebody else on it -- so come back to the same sheet with
    // the eligible list already open, rather than to the board with the
    // position to find again. Two taps to refill, the same as to fill.
    if ($vacated) {
        $extra .= '&position=' . $positionId;
    }

    return Response::redirect(officerUrl($app, $ctx, 'officer/assign/' . $phase, $extra));
});


// ---------------------------------------------------------------------------
// View Roster (6.9.3), View Checked In / View Absent (6.9.8),
// Lunch Management (6.9.9) and Reset PINs (6.9.11)
//
// One list of people, filtered five ways. The read is shared so the five
// screens cannot disagree about who is on the tarmac.
// ---------------------------------------------------------------------------

foreach (['roster', 'checked-in', 'absent', 'lunch', 'pins'] as $screen) {
    $router->get('officer/' . $screen, static function (App $app, Request $request) use ($screen): Response {
        $ctx = officerContext($app, $request, OfficerMenu::SECTIONS[$screen]['capability']);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($ctx['shift'] === null) {
            return officerFailure($app, $ctx, 'There is no shift to show a roster for.', 422);
        }

        return Response::html(rosterPage($app, $ctx, $request, $screen));
    });
}

$router->post('officer/roster', static function (App $app, Request $request): Response {
    return officerPeoplePost($app, $request, 'roster');
});

$router->post('officer/lunch', static function (App $app, Request $request): Response {
    return officerPeoplePost($app, $request, 'lunch');
});

$router->post('officer/pins', static function (App $app, Request $request): Response {
    return officerPeoplePost($app, $request, 'pins');
});

// ---------------------------------------------------------------------------
// Edit Certified Skills (spec 7.3, Capability::EditCertifiedSkills)
// ---------------------------------------------------------------------------

$router->get('officer/skills/{id}', static function (App $app, Request $request, array $params): Response {
    $ctx = officerContext($app, $request, Capability::EditCertifiedSkills);
    if ($ctx instanceof Response) {
        return $ctx;
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to show a roster for.', 422);
    }

    return officerSkillSheet($app, $ctx, $request, (int) ($params['id'] ?? 0));
});

$router->post('officer/skills/{id}', static function (App $app, Request $request, array $params): Response {
    $ctx = officerContext($app, $request, Capability::EditCertifiedSkills);
    if ($ctx instanceof Response) {
        return $ctx;
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to show a roster for.', 422);
    }

    $userId = (int) ($params['id'] ?? 0);

    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return officerSkillSheet($app, $ctx, $request, $userId, error: 'That page went stale. Try again.', status: 400);
    }

    // inputList, not input: a checkbox group posts skills[] and input()
    // answers null for anything that is not a string -- which would read as
    // "he ticked nothing" and quietly clear every certification he had.
    $result = officerPeople($app)->setCertified(
        $ctx['user'],
        (int) $ctx['team']['id'],
        (int) $ctx['season']['id'],
        $userId,
        $request->inputList('skills'),
    );

    if (!$result['ok']) {
        return officerSkillSheet($app, $ctx, $request, $userId, error: $result['error'], status: 422);
    }

    return Response::redirect(officerUrl($app, $ctx, 'officer/roster', 'saved=1'));
});


// ---------------------------------------------------------------------------
// View Unload / View Bump and Run (spec 6.9.7), Copy From Previous Shift
// (6.9.6) and Broadcast Message (6.9.10)
// ---------------------------------------------------------------------------

$router->get('officer/board/{phase}', static function (App $app, Request $request, array $params): Response {
    $ctx = officerContext($app, $request, Capability::ViewTeamRoster);
    if ($ctx instanceof Response) {
        return $ctx;
    }

    $phase = (string) ($params['phase'] ?? '');
    if (!PhaseControl::isPhase($phase)) {
        return Response::html(officerEmpty($app, 'Not found', 'That is not a phase.'), 404);
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to show a board for.', 422);
    }

    $ctx = officerWithPhase($app, $ctx, $phase);
    $groups = officerBoard($app)->groups((int) $ctx['shift']['id'], $phase);

    return Response::html((new View($app))->render('officer/board', array_merge($ctx, [
        'title' => 'View ' . PhaseControl::label($phase),
        'groups' => $groups,
        'error' => null,
        'notice' => null,
        'back' => officerBack($app, $ctx),
    ])));
});

$router->get('officer/copy', static function (App $app, Request $request): Response {
    $ctx = officerContext($app, $request, Capability::CopyAssignments);
    if ($ctx instanceof Response) {
        return $ctx;
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to copy a board onto.', 422);
    }

    return Response::html(copyPage($app, $ctx, $request));
});

$router->post('officer/copy', static function (App $app, Request $request): Response {
    $ctx = officerContext($app, $request, Capability::CopyAssignments);
    if ($ctx instanceof Response) {
        return $ctx;
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to copy a board onto.', 422);
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(copyPage($app, $ctx, $request, error: 'That page went stale. Try again.'), 400);
    }

    $phase = (string) $request->input('phase', (string) $ctx['phase']);
    $from = officerIntInput($request, 'from_shift') ?? 0;

    // Two posts, not one: the first shows the preview 6.9.6 asks for, and only
    // a post carrying the confirmation writes anything.
    if ($request->input('confirm') === null) {
        return Response::html(copyPage($app, $ctx, $request));
    }

    $result = officerCopyPrevious($app)->apply($ctx['user'], $ctx['shift'], $from, $phase);

    if (!$result['ok']) {
        return Response::html(copyPage($app, $ctx, $request, error: $result['error']), 422);
    }

    return Response::redirect(officerUrl(
        $app,
        $ctx,
        'officer/assign/' . $phase,
        'copied=' . $result['applied'],
    ));
});

$router->get('officer/broadcast', static function (App $app, Request $request): Response {
    $ctx = officerContext($app, $request, Capability::SendBroadcast);
    if ($ctx instanceof Response) {
        return $ctx;
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to broadcast to.', 422);
    }

    return Response::html(broadcastPage($app, $ctx, notice: $request->input('sent') !== null
        ? 'Pinned to every widget on the shift.'
        : ($request->input('cleared') !== null ? 'Taken down.' : null)));
});

$router->post('officer/broadcast', static function (App $app, Request $request): Response {
    $ctx = officerContext($app, $request, Capability::SendBroadcast);
    if ($ctx instanceof Response) {
        return $ctx;
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to broadcast to.', 422);
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(broadcastPage($app, $ctx, error: 'That page went stale. Try again.'), 400);
    }

    $shiftId = (int) $ctx['shift']['id'];
    $service = officerBroadcasts($app);

    if ((string) $request->input('action', '') === 'retire') {
        $service->retire($ctx['user'], $shiftId);

        return Response::redirect(officerUrl($app, $ctx, 'officer/broadcast', 'cleared=1'));
    }

    $result = $service->send(
        $ctx['user'],
        $shiftId,
        (string) $request->input('body', ''),
        (string) $request->input('expires_in', ''),
    );

    if (!$result['ok']) {
        return Response::html(broadcastPage($app, $ctx, error: $result['error']), 422);
    }

    return Response::redirect(officerUrl($app, $ctx, 'officer/broadcast', 'sent=1'));
});

// ---------------------------------------------------------------------------
// Shared scaffolding
// ---------------------------------------------------------------------------

function officerCopyPrevious(App $app): Resm\Officer\CopyPrevious
{
    return new Resm\Officer\CopyPrevious($app->db(), new Resm\AuditLog($app->db()));
}

function officerBroadcasts(App $app): Resm\Officer\Broadcasts
{
    return new Resm\Officer\Broadcasts($app->db(), new Resm\AuditLog($app->db()));
}

/**
 * Copy From Previous Shift, with the preview 6.9.6 requires.
 *
 * @param array<string, mixed> $ctx
 */
function copyPage(App $app, array $ctx, Request $request, ?string $error = null): string
{
    $phase = (string) $request->input('phase', (string) $ctx['phase']);
    if (!PhaseControl::isPhase($phase)) {
        $phase = (string) $ctx['phase'];
    }

    $ctx = officerWithPhase($app, $ctx, $phase);
    $shiftId = (int) $ctx['shift']['id'];
    $teamId = (int) $ctx['team']['id'];
    $seasonId = (int) $ctx['season']['id'];

    $service = officerCopyPrevious($app);
    $sources = $service->sources($teamId, $seasonId, $shiftId, $phase, $app->now());

    $from = officerIntInput($request, 'from_shift');
    $preview = null;
    $source = null;

    if ($from !== null) {
        foreach ($sources as $candidate) {
            if ((int) $candidate['id'] === $from) {
                $source = $candidate;
                break;
            }
        }
        if ($source !== null) {
            $preview = $service->preview($from, $shiftId, $teamId, $seasonId, $phase);
        }
    }

    return (new View($app))->render('officer/copy', array_merge($ctx, [
        'title' => 'Copy From Previous Shift',
        'sources' => $sources,
        'source' => $source,
        'preview' => $preview,
        'copyPhase' => $phase,
        'error' => $error,
        'notice' => null,
        'back' => officerBack($app, $ctx),
    ]));
}

/** @param array<string, mixed> $ctx */
function broadcastPage(App $app, array $ctx, ?string $error = null, ?string $notice = null): string
{
    $shiftId = (int) $ctx['shift']['id'];

    return (new View($app))->render('officer/broadcast', array_merge($ctx, [
        'title' => 'Broadcast Message',
        'live' => attendance($app)->broadcast($shiftId),
        'history' => officerBroadcasts($app)->history($shiftId),
        'error' => $error,
        'notice' => $notice,
        'back' => officerBack($app, $ctx),
    ]));
}

function officerPeople(App $app): Resm\Officer\People
{
    return new Resm\Officer\People(
        $app->db(),
        new Resm\AuditLog($app->db()),
        $app->config->int('auth.pin_cost', 11),
        $app->config->string('auth.default_pin', '1234'),
    );
}

function officerTeamRoster(App $app): Resm\Officer\TeamRoster
{
    return new Resm\Officer\TeamRoster($app->db(), officerBoard($app));
}

/**
 * The five people screens, which are one list filtered five ways (6.9.3,
 * 6.9.8, 6.9.9, 6.9.11).
 *
 * @param array<string, mixed> $ctx
 */
function rosterPage(
    App $app,
    array $ctx,
    Request $request,
    string $screen,
    ?string $error = null,
    ?string $notice = null,
): string {
    $shiftId = (int) $ctx['shift']['id'];
    $teamId = (int) $ctx['team']['id'];
    $seasonId = (int) $ctx['season']['id'];
    $search = (string) ($request->input('q') ?? '');

    $people = officerTeamRoster($app)->forShift($shiftId, $teamId, $seasonId, $search);

    // Absent is no check event at all, never the complement of checked in
    // (6.9.8): a man who came and went is on neither list.
    $filtered = match ($screen) {
        'checked-in' => array_values(array_filter($people, static fn (array $p): bool => $p['checked_in'])),
        'absent' => array_values(array_filter($people, static fn (array $p): bool => $p['absent'])),
        default => $people,
    };

    if ($notice === null && $request->input('saved') !== null) {
        $notice = 'Saved.';
    }

    return (new View($app))->render('officer/' . (in_array($screen, ['roster', 'pins', 'lunch'], true) ? $screen : 'people'), array_merge($ctx, [
        'title' => OfficerMenu::SECTIONS[$screen]['label'],
        'screen' => $screen,
        'people' => $filtered,
        'allPeople' => $people,
        'lunchCounts' => Resm\Officer\TeamRoster::lunchCounts($people),
        'search' => $search,
        'lunchFilter' => in_array((string) $request->input('state'), ['not_yet', 'at_lunch', 'done'], true)
            ? (string) $request->input('state')
            : '',
        'skills' => officerBoard($app)->chipSkills(),
        'error' => $error,
        'notice' => $notice,
        'back' => officerBack($app, $ctx),
    ]));
}

/**
 * Everything the people screens post: officer check in and out, lunch state,
 * PIN resets and walk-ons.
 *
 * One handler because they share a guard and a re-render, and because the
 * capability each action needs is checked against the resolved team here
 * rather than being assumed from which screen the form was on.
 */
function officerPeoplePost(App $app, Request $request, string $screen): Response
{
    $action = (string) $request->input('action', '');

    // The capability follows the action, not the screen it was posted from.
    $capability = match ($action) {
        'check-in', 'check-out', 'lunch' => Capability::CheckOthersInOut,
        'reset-pin' => Capability::ResetCommitteemanPin,
        'walkon' => Capability::AddWalkon,
        default => Capability::ViewTeamRoster,
    };

    $ctx = officerContext($app, $request, $capability);
    if ($ctx instanceof Response) {
        return $ctx;
    }
    if ($ctx['shift'] === null) {
        return officerFailure($app, $ctx, 'There is no shift to act on.', 422);
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(rosterPage($app, $ctx, $request, $screen, error: 'That page went stale. Try again.'), 400);
    }

    $teamId = (int) $ctx['team']['id'];
    $seasonId = (int) $ctx['season']['id'];
    $shiftId = (int) $ctx['shift']['id'];
    $userId = officerIntInput($request, 'user_id') ?? 0;

    if ($action === 'walkon') {
        $result = officerPeople($app)->addWalkon(
            $ctx['user'],
            $seasonId,
            $teamId,
            (string) $request->input('last_name', ''),
            (string) $request->input('first_name', ''),
            (string) $request->input('phone', ''),
            (string) $request->input('member_id', ''),
        );

        return $result['ok']
            ? Response::redirect(officerUrl($app, $ctx, 'officer/' . $screen, 'added=1'))
            : Response::html(rosterPage($app, $ctx, $request, $screen, error: $result['error']), 422);
    }

    if ($action === 'reset-pin') {
        $result = officerPeople($app)->resetPin($ctx['user'], $teamId, $seasonId, $userId);

        return $result['ok']
            ? Response::redirect(officerUrl($app, $ctx, 'officer/' . $screen, 'reset=1'))
            : Response::html(rosterPage($app, $ctx, $request, $screen, error: $result['error']), 422);
    }

    // Check in, check out and lunch all act on somebody on this team, so the
    // target is resolved through the roster rather than taken from the form.
    $roster = officerTeamRoster($app)->forShift($shiftId, $teamId, $seasonId);
    $subject = null;
    foreach ($roster as $person) {
        if ((int) $person['id'] === $userId) {
            $subject = $person;
            break;
        }
    }

    if ($subject === null) {
        return Response::html(
            rosterPage($app, $ctx, $request, $screen, error: 'That person is not on this team.'),
            422
        );
    }

    if ($action === 'lunch') {
        $result = attendance($app)->setLunch(
            $ctx['user'],
            $shiftId,
            $userId,
            (string) $request->input('state', ''),
        );

        return $result['ok']
            ? Response::redirect(officerUrl($app, $ctx, 'officer/' . $screen, 'saved=1'))
            : Response::html(rosterPage($app, $ctx, $request, $screen, error: $result['error']), 422);
    }

    if ($action === 'check-in' || $action === 'check-out') {
        // Attendance reads the subject's own check state off this array, not
        // the officer's — an officer checking somebody in is the same event
        // recorded by somebody else.
        $result = attendance($app)->record(
            $ctx['user'],
            [
                'id' => $shiftId,
                'check_state' => $subject['check_state'],
                'checked_at' => $subject['checked_at'],
            ],
            $userId,
            $action === 'check-in' ? 'in' : 'out',
        );

        return $result['ok']
            ? Response::redirect(officerUrl($app, $ctx, 'officer/' . $screen, 'saved=1'))
            : Response::html(rosterPage($app, $ctx, $request, $screen, error: $result['error']), 422);
    }

    return Response::html(rosterPage($app, $ctx, $request, $screen, error: 'Unknown action.'), 422);
}

/**
 * The certified-skills sheet for one man (spec 7.3).
 *
 * @param array<string, mixed> $ctx
 */
function officerSkillSheet(
    App $app,
    array $ctx,
    Request $request,
    int $userId,
    ?string $error = null,
    int $status = 200,
): Response {
    $shiftId = (int) $ctx['shift']['id'];
    $teamId = (int) $ctx['team']['id'];
    $seasonId = (int) $ctx['season']['id'];

    $person = null;
    foreach (officerTeamRoster($app)->forShift($shiftId, $teamId, $seasonId) as $candidate) {
        if ((int) $candidate['id'] === $userId) {
            $person = $candidate;
            break;
        }
    }

    if ($person === null) {
        return Response::html(officerEmpty($app, 'Not found', 'That person is not on this team.'), 404);
    }

    $held = [];
    foreach ($person['certified'] as $skill) {
        $held[$skill['code']] = true;
    }
    foreach ($person['equipment'] as $skill) {
        $held[$skill['code']] = true;
    }

    return Response::html((new View($app))->render('officer/skills', array_merge($ctx, [
        'title' => $person['list_name'],
        'person' => $person,
        'allSkills' => $app->db()->all('SELECT code, label, kind FROM skill ORDER BY sort_order'),
        'held' => $held,
        'error' => $error,
        'notice' => null,
        'back' => ['url' => officerUrl($app, $ctx, 'officer/roster'), 'label' => 'View Roster'],
    ])), $status);
}

function officerAssignments(App $app): Resm\Officer\Assignments
{
    return new Resm\Officer\Assignments($app->db(), new Resm\AuditLog($app->db()));
}

function officerBoard(App $app): Resm\Officer\Board
{
    return new Resm\Officer\Board($app->db());
}

/**
 * The same context, read for a named phase.
 *
 * An officer sets Bump and Run up while the shift is still running Unload, so
 * the board he is editing and the phase the shift is in are two different
 * facts. The counter has to follow the board, or it reports on a board nobody
 * is looking at.
 *
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function officerWithPhase(App $app, array $ctx, string $phase): array
{
    if ($ctx['shift'] === null) {
        return $ctx;
    }

    $ctx['phase'] = $phase;
    $ctx['coverage'] = officerCoverage($app)->forShift(
        (int) $ctx['shift']['id'],
        (int) $ctx['team']['id'],
        (int) $ctx['season']['id'],
        $phase,
    );

    return $ctx;
}

/**
 * The assign board, in whichever of its two modes was asked for, or one of the
 * two sheets that a tap on it opens.
 *
 * @param array<string, mixed> $ctx
 */
function assignPage(App $app, array $ctx, Request $request, ?string $error = null): string
{
    $phase = (string) $ctx['phase'];
    $shiftId = (int) $ctx['shift']['id'];
    $teamId = (int) $ctx['team']['id'];
    $seasonId = (int) $ctx['season']['id'];

    $board = officerBoard($app);
    $groups = $board->groups($shiftId, $phase);

    $filters = [
        'search' => (string) ($request->input('q') ?? ''),
        'skill' => (string) ($request->input('skill') ?? ''),
    ];
    $available = $board->available($shiftId, $teamId, $seasonId, $phase, $filters);

    $data = array_merge($ctx, [
        'title' => 'Assign ' . PhaseControl::label($phase),
        'error' => $error,
        'notice' => match ((string) $request->input('done', '')) {
            'placed' => $request->input('carried') === null
                ? 'Placed.'
                : 'Placed, and carried into Bump and Run.',
            'vacated' => $request->input('carried') === null
                ? 'Taken off. The spot is open.'
                : 'Taken off, in both phases. The spot is open.',
            default => $request->input('copied') === null
                ? null
                : ((int) $request->input('copied') . ' placed from the previous shift.'),
        },
        'groups' => $groups,
        'criticalVacancies' => Resm\Officer\Board::criticalVacancies($groups),
        'available' => $available,
        'chips' => $board->chipSkills(),
        'filters' => $filters,
        'mode' => (string) $request->input('mode') === 'roster' ? 'roster' : 'position',
        'back' => officerBack($app, $ctx),
    ]);

    // A tap on a position, or on a name: the second half of the two taps.
    $positionId = officerIntInput($request, 'position');
    if ($positionId !== null) {
        $position = $board->position($shiftId, $phase, $positionId);
        if ($position !== null) {
            return (new View($app))->render('officer/sheet-position', $data + [
                'title' => $position['label'],
                'position' => $position,
            ]);
        }
    }

    $personId = officerIntInput($request, 'person');
    if ($personId !== null) {
        foreach ($available as $candidate) {
            if ((int) $candidate['id'] === $personId) {
                return (new View($app))->render('officer/sheet-person', $data + [
                    'title' => $candidate['list_name'],
                    'person' => $candidate,
                    'vacancies' => Resm\Officer\Board::vacancies($groups),
                ]);
            }
        }
    }

    return (new View($app))->render('officer/assign', $data);
}

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
    return Response::html((new View($app))->render('officer/index', array_merge($ctx, [
        'title' => 'Officer Menu',
        'error' => $message,
        'tiles' => OfficerMenu::tilesFor(
            $app,
            $ctx['user'],
            $ctx['team'] === null ? null : (int) $ctx['team']['id'],
            $ctx['shift'] === null ? null : (int) $ctx['shift']['id'],
        ),
        'back' => ['url' => $app->url(''), 'label' => 'Main Menu'],
    ])), $status);
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
