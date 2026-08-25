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
use Resm\Admin\Seasons;
use Resm\Admin\ImportFile;
use Resm\Admin\RosterImport;
use Resm\Admin\Shifts;
use Resm\Admin\Teams;
use Resm\Admin\Users;
use Resm\AdminMenu;
use Resm\Auth\Capability;
use Resm\Auth\Role;
use Resm\Csrf;
use Resm\Diagnostics;
use Resm\Http\Request;
use Resm\Http\Response;
use Resm\Http\Router;
use Resm\Menu;
use Resm\Shift\Attendance;
use Resm\Shift\CurrentShift;
use Resm\Shift\Roster;
use Resm\ShiftClock;
use Resm\ShiftType;
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
// Check In / Out (spec 6.4)
// ---------------------------------------------------------------------------

$router->get('check-in', static function (App $app, Request $request): Response {
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }

    return Response::html(checkInPage($app, $user, $request, (string) $request->input('shift', '')));
});

$router->post('check-in', static function (App $app, Request $request): Response {
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(checkInPage($app, $user, $request, error: 'That page went stale. Try again.'), 400);
    }

    $season = adminSeasons($app)->active();
    if ($season === null) {
        return Response::html(checkInPage($app, $user, $request, error: 'There is no active season.'), 422);
    }

    $wanted = (string) $request->input('shift_id', '');

    // Resolved through the candidate list, so a shift he is not rostered on or
    // that is outside the 5.3 window cannot be checked into by posting its id.
    $shift = currentShift($app)->pick($user->id, (int) $season['id'], (int) $wanted);
    if ($shift === null) {
        return Response::html(
            checkInPage($app, $user, $request, error: 'That is not a shift you can check into right now.'),
            422
        );
    }

    $result = attendance($app)->record(
        $user,
        $shift,
        $user->id,
        (string) $request->input('type', ''),
    );

    if (!$result['ok']) {
        return Response::html(checkInPage($app, $user, $request, $wanted, error: $result['error']), 422);
    }

    // Redirect after posting, so a refresh on the tarmac does not re-record.
    return Response::redirect($app->url(sprintf(
        'check-in?shift=%d&at=%s&freed=%d',
        (int) $shift['id'],
        rawurlencode(($result['at'] ?? $app->now())->format('c')),
        $result['vacated'],
    )));
});

// ---------------------------------------------------------------------------
// My Shift Status (spec 6.5) and My Shifts (spec 6.6)
// ---------------------------------------------------------------------------

$router->get('my-shift', static function (App $app, Request $request): Response {
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }

    return Response::html(myShiftPage($app, $user, $request));
});

$router->post('my-shift', static function (App $app, Request $request): Response {
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(myShiftPage($app, $user, $request, error: 'That page went stale. Try again.'), 400);
    }

    $season = adminSeasons($app)->active();
    if ($season === null) {
        return Response::html(myShiftPage($app, $user, $request, error: 'There is no active season.'), 422);
    }

    // Same guard as check-in: resolved through his own candidates, so a shift
    // he is not rostered on cannot be reached by posting its id.
    $shift = currentShift($app)->pick($user->id, (int) $season['id'], (int) $request->input('shift_id', '0'));
    if ($shift === null) {
        return Response::html(myShiftPage($app, $user, $request, error: 'That is not one of your shifts.'), 422);
    }

    $result = (string) $request->input('action', '') === 'lunch'
        ? attendance($app)->setLunch($user, (int) $shift['id'], $user->id, (string) $request->input('state', ''))
        : attendance($app)->record($user, $shift, $user->id, (string) $request->input('type', ''));

    if (!$result['ok']) {
        return Response::html(myShiftPage($app, $user, $request, error: $result['error']), 422);
    }

    return Response::redirect($app->url('my-shift?shift=' . (int) $shift['id'] . '&done=1'));
});

$router->get('my-shifts', static function (App $app, Request $request): Response {
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }

    $season = adminSeasons($app)->active();
    $all = $season === null
        ? []
        : (new Roster($app->db()))->season($user->id, (int) $season['id']);

    // Split on the end time rather than the start: a shift running now is
    // still one of his, not history.
    $now = $app->now()->format('Y-m-d H:i:s');
    $upcoming = [];
    $past = [];
    foreach ($all as $shift) {
        if ((string) $shift['ends_at'] > $now) {
            $upcoming[] = $shift;
        } else {
            $past[] = $shift;
        }
    }
    // Soonest first among what is still to come; the query returns newest
    // first, which is right for history and backwards for a schedule.
    $upcoming = array_reverse($upcoming);

    return Response::html((new View($app))->render('my-shifts', [
        'title' => 'My Shifts',
        'season' => $season,
        'upcoming' => $upcoming,
        'past' => $past,
        'clock' => new ShiftClock($app->displayTimezone()),
        'back' => ['url' => $app->url(), 'label' => 'Menu'],
    ]));
});

// ---------------------------------------------------------------------------
// Admin Menu (spec 6.10)
//
// Every handler calls Access::require for the capability its section declares.
// The tile being visible is not the check; AdminMenu and the guard read the
// same table so they cannot disagree, and the guard is what refuses.
// ---------------------------------------------------------------------------

$router->get('admin', static function (App $app, Request $request): Response {
    $user = requireAdmin($app);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }

    return Response::html((new View($app))->render('admin/index', [
        'title' => 'Admin Menu',
        'tiles' => AdminMenu::tilesFor($app, $user),
        'season' => adminSeasons($app)->active(),
        'back' => ['url' => $app->url(), 'label' => 'Menu'],
    ]));
});

$router->get('admin/seasons', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::ManageSeasons);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }

    return Response::html(seasonsPage($app, notice: $request->input('created') !== null
        ? 'Season created.'
        : ($request->input('activated') !== null ? 'Active season changed.' : null)));
});

$router->post('admin/seasons', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::ManageSeasons);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(seasonsPage($app, error: 'That page went stale. Try again.'), 400);
    }

    $seasons = adminSeasons($app);

    if ($request->input('action') === 'activate') {
        $result = $seasons->activate($user, (int) $request->input('season_id', '0'));

        return $result['ok']
            ? Response::redirect($app->url('admin/seasons?activated=1'))
            : Response::html(seasonsPage($app, error: $result['error']), 422);
    }

    $result = $seasons->create(
        $user,
        (string) $request->input('name', ''),
        (string) $request->input('start_date', ''),
        (string) $request->input('end_date', ''),
    );

    return $result['ok']
        ? Response::redirect($app->url('admin/seasons?created=1'))
        : Response::html(seasonsPage($app, error: $result['error']), 422);
});

$router->get('admin/teams', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::ManageTeams);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }

    return Response::html(teamsPage($app, notice: $request->input('done') !== null ? 'Saved.' : null));
});

$router->post('admin/teams', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::ManageTeams);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(teamsPage($app, error: 'That page went stale. Try again.'), 400);
    }

    $season = adminSeasons($app)->active();
    if ($season === null) {
        return Response::html(teamsPage($app, error: 'There is no active season.'), 422);
    }

    $teams = adminTeams($app);
    $action = (string) $request->input('action', '');
    $teamId = (int) $request->input('team_id', '0');

    $result = match ($action) {
        'create' => $teams->create($user, (int) $season['id'], (string) $request->input('name', '')),
        'rename' => $teams->rename($user, $teamId, (string) $request->input('name', '')),
        'activate' => $teams->setActive($user, $teamId, true),
        'deactivate' => $teams->setActive($user, $teamId, false),
        default => ['ok' => false, 'error' => 'Unknown action.'],
    };

    return $result['ok']
        ? Response::redirect($app->url('admin/teams?done=1'))
        : Response::html(teamsPage($app, error: $result['error']), 422);
});

/*
 * Create Committeeman (spec 6.10.7) and Create Officer / Admin (spec 6.10.6).
 *
 * The same screen twice. Only the roles it offers differ, and the service
 * refuses any role outside the set the route hands it — so the role a form
 * posts is a request, not an instruction.
 */
foreach (['committeemen', 'officers'] as $userKey) {
    $router->get('admin/' . $userKey, static function (App $app, Request $request) use ($userKey): Response {
        $user = requireAdmin($app, Capability::CreateOfficerAdminUsers);
        if (!$user instanceof Resm\Auth\Identity) {
            return $user;
        }

        return Response::html(usersPage(
            $app,
            $userKey,
            search: (string) $request->input('q', ''),
            notice: $request->input('created') !== null
                ? 'Account created. Their PIN is ' . $app->config->string('auth.default_pin', '1234') . '.'
                : ($request->input('done') !== null ? 'Saved.' : null),
        ));
    });

    $router->post('admin/' . $userKey, static function (App $app, Request $request) use ($userKey): Response {
        $user = requireAdmin($app, Capability::CreateOfficerAdminUsers);
        if (!$user instanceof Resm\Auth\Identity) {
            return $user;
        }
        if (!Csrf::check($request->input(Csrf::FIELD))) {
            return Response::html(usersPage($app, $userKey, error: 'That page went stale. Try again.'), 400);
        }

        $season = adminSeasons($app)->active();
        if ($season === null) {
            return Response::html(usersPage($app, $userKey, error: 'There is no active season.'), 422);
        }

        $screen = adminUserScreen($userKey);
        $users = adminUsers($app);
        $seasonId = (int) $season['id'];
        $action = (string) $request->input('action', '');
        $teamIds = $request->inputList('team_ids');

        if ($action === 'create') {
            $result = $users->create(
                $user,
                $seasonId,
                $screen['roles'],
                (string) $request->input('member_id', ''),
                (string) $request->input('last_name', ''),
                (string) $request->input('first_name', ''),
                // No default. A form always posts one — the radio group, or
                // the hidden field on the single-role screen — so a request
                // without a role is not a form, and gets refused rather than
                // quietly assigned one.
                (string) $request->input('role', ''),
                (string) $request->input('phone', ''),
                (string) $request->input('email', ''),
                $teamIds,
            );

            if ($result['ok']) {
                return Response::redirect($app->url('admin/' . $userKey . '?created=1'));
            }

            // Everything typed comes back with the message. Re-keying six
            // fields because one of them was wrong is how a roster of 150
            // people stops getting entered.
            return Response::html(usersPage($app, $userKey, error: $result['error'], form: [
                'member_id'  => (string) $request->input('member_id', ''),
                'first_name' => (string) $request->input('first_name', ''),
                'last_name'  => (string) $request->input('last_name', ''),
                'phone'      => (string) $request->input('phone', ''),
                'email'      => (string) $request->input('email', ''),
                'role'       => (string) $request->input('role', ''),
                'team_ids'   => $teamIds,
            ]), 422);
        }

        $userId = (int) $request->input('user_id', '0');

        $result = match ($action) {
            'teams' => $users->setTeams($user, $seasonId, $userId, $teamIds),
            'activate' => $users->setActive($user, $userId, true),
            'deactivate' => $users->setActive($user, $userId, false),
            default => ['ok' => false, 'error' => 'Unknown action.', 'id' => null],
        };

        return $result['ok']
            ? Response::redirect($app->url('admin/' . $userKey . '?done=1'))
            : Response::html(usersPage($app, $userKey, error: $result['error']), 422);
    });
}

/*
 * Create Shifts (spec 6.10.5).
 *
 * One form does both a single night and a whole pattern, because they differ
 * only in which dates they resolve to — the team, type, hours and groups are
 * the same decision either way.
 */
$router->get('admin/shifts', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::CreateShifts);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }

    $team = $request->input('team', '');

    return Response::html(shiftsPage(
        $app,
        filterTeam: is_string($team) && $team !== '' ? (int) $team : null,
        notice: $request->input('done') !== null ? 'Saved.' : null,
    ));
});

$router->post('admin/shifts', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::CreateShifts);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(shiftsPage($app, error: 'That page went stale. Try again.'), 400);
    }

    $season = adminSeasons($app)->active();
    if ($season === null) {
        return Response::html(shiftsPage($app, error: 'There is no active season.'), 422);
    }

    $shifts = adminShifts($app);
    $seasonId = (int) $season['id'];
    $action = (string) $request->input('action', 'create');
    $groupIds = $request->inputList('group_ids');

    if ($action === 'groups') {
        $result = $shifts->setGroups($user, (int) $request->input('shift_id', '0'), $groupIds);

        return $result['ok']
            ? Response::redirect($app->url('admin/shifts?done=1'))
            : Response::html(shiftsPage($app, error: $result['error']), 422);
    }

    if ($action === 'delete') {
        $result = $shifts->delete($user, (int) $request->input('shift_id', '0'));

        return $result['ok']
            ? Response::redirect($app->url('admin/shifts?done=1'))
            : Response::html(shiftsPage($app, error: $result['error']), 422);
    }

    $teamId = (int) $request->input('team_id', '0');
    $type = (string) $request->input('shift_type', '');
    $startTime = (string) $request->input('start_time', '');
    $endTime = (string) $request->input('end_time', '');
    $weekdays = $request->inputList('weekdays');

    // Everything typed comes back with the message. A rejected range is a lot
    // of choices to make twice.
    $form = [
        'team_id' => $teamId,
        'shift_type' => $type,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'mode' => (string) $request->input('mode', 'single'),
        'date' => (string) $request->input('date', ''),
        'from_date' => (string) $request->input('from_date', ''),
        'to_date' => (string) $request->input('to_date', ''),
        'weekdays' => $weekdays,
        'group_ids' => $groupIds,
    ];

    if ($form['mode'] === 'range') {
        $result = $shifts->createRange(
            $user,
            $seasonId,
            $teamId,
            $type,
            (string) $form['from_date'],
            (string) $form['to_date'],
            $weekdays,
            $startTime,
            $endTime,
            $groupIds,
        );

        if (!$result['ok']) {
            return Response::html(shiftsPage($app, error: $result['error'], form: $form), 422);
        }

        $summary = sprintf(
            'Created %d shift%s.',
            $result['created'],
            $result['created'] === 1 ? '' : 's'
        );

        return Response::html(shiftsPage(
            $app,
            notice: trim($summary . ' ' . (string) $result['notice']),
            filterTeam: $teamId,
        ));
    }

    $result = $shifts->create(
        $user,
        $seasonId,
        $teamId,
        $type,
        (string) $form['date'],
        $startTime,
        $endTime,
        $groupIds,
    );

    if (!$result['ok']) {
        return Response::html(shiftsPage($app, error: $result['error'], form: $form), 422);
    }

    return Response::html(shiftsPage(
        $app,
        notice: trim('Shift created. ' . (string) $result['notice']),
        filterTeam: $teamId,
    ));
});

/*
 * Import Roster (spec 6.10.3).
 *
 * Two requests. The first reads the file and shows what it would do; the
 * second does it. The file is held between them and re-parsed on confirm, so
 * the summary that was approved and the write that follows cannot disagree.
 */
$router->get('admin/import', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::ImportExportRoster);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }

    return Response::html(importPage($app));
});

$router->post('admin/import', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::ImportExportRoster);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }
    if (!Csrf::check($request->input(Csrf::FIELD))) {
        return Response::html(importPage($app, error: 'That page went stale. Try again.'), 400);
    }

    $season = adminSeasons($app)->active();
    if ($season === null) {
        return Response::html(importPage($app, error: 'There is no active season.'), 422);
    }

    $held = importFile($app);
    $action = (string) $request->input('action', '');

    if ($action === 'discard') {
        $held->discard();

        return Response::redirect($app->url('admin/import'));
    }

    if ($action === 'dry-run') {
        $upload = importUpload();
        if ($upload === null) {
            return Response::html(importPage($app, error: importUploadError()), 422);
        }

        $stored = $held->store($upload['content'], $upload['name']);
        if (!$stored['ok']) {
            return Response::html(importPage($app, error: $stored['error']), 500);
        }

        // Re-read through the same path the confirm will use, so a file that
        // cannot be read back is discovered now rather than after approval.
        return Response::html(importPage($app));
    }

    if ($action !== 'commit') {
        return Response::html(importPage($app, error: 'Unknown action.'), 422);
    }

    $content = $held->contents();
    if ($content === null) {
        return Response::html(
            importPage($app, error: 'That upload is no longer here. Upload the file again.'),
            422
        );
    }

    $result = rosterImport($app)->commit($user, $content, (int) $season['id']);
    if (!$result['ok']) {
        return Response::html(importPage($app, error: $result['error']), 422);
    }

    $held->discard();
    $counts = $result['counts'];

    return Response::html(importPage($app, notice: sprintf(
        'Imported. %d new, %d updated%s, %d skipped, %d in error.',
        $counts['new'],
        $counts['update'],
        $counts['reactivate'] > 0 ? sprintf(', %d reactivated', $counts['reactivate']) : '',
        $counts['skip'],
        $counts['error'],
    )));
});

/*
 * The error report from the pending dry run (spec 6.10.3).
 *
 * Rebuilt from the held file rather than stored, for the same reason the
 * confirm re-parses: one source of truth for what this upload says.
 */
$router->get('admin/import/errors', static function (App $app, Request $request): Response {
    $user = requireAdmin($app, Capability::ImportExportRoster);
    if (!$user instanceof Resm\Auth\Identity) {
        return $user;
    }

    $season = adminSeasons($app)->active();
    $content = importFile($app)->contents();
    if ($season === null || $content === null) {
        return notFoundResponse($app);
    }

    $plan = rosterImport($app)->plan($content, (int) $season['id']);
    if (!$plan['ok']) {
        return notFoundResponse($app);
    }

    return Response::text(RosterImport::errorReport($plan['rows']))
        ->withHeader('Content-Type', 'text/csv; charset=utf-8')
        ->withHeader('Content-Disposition', 'attachment; filename="roster-import-report.csv"')
        ->withHeader('X-Robots-Tag', 'noindex, nofollow');
});

/*
 * Admin sections the build sequence has not reached, behind the same guard the
 * real screen will use.
 */
foreach (AdminMenu::SECTIONS as $adminKey => $adminSection) {
    if ($adminSection['built']) {
        continue;
    }

    $router->get('admin/' . $adminKey, static function (App $app, Request $request) use ($adminSection): Response {
        $user = requireAdmin($app, $adminSection['capability']);
        if (!$user instanceof Resm\Auth\Identity) {
            return $user;
        }

        return Response::html((new View($app))->render('placeholder', [
            'title' => $adminSection['label'],
            'heading' => $adminSection['label'],
            'summary' => $adminSection['summary'],
            'phase' => $adminSection['phase'],
            'back' => ['url' => $app->url('admin'), 'label' => 'Admin Menu'],
        ]));
    });
}

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

/**
 * Resolve the signed-in administrator, or the response to send instead.
 *
 * Returns an Identity when permitted, and a Response — a redirect to login, or
 * the 403 an AccessDenied becomes — when not. Callers check the type rather
 * than assuming, so a missing check is a type error rather than an open door.
 */
function requireAdmin(App $app, ?Capability $capability = null): Resm\Auth\Identity|Response
{
    $user = $app->user();
    if ($user === null) {
        return Response::redirect($app->url('login'));
    }

    Resm\Auth\Access::require($user, $capability ?? Capability::ManageSeasons);

    return $user;
}

function adminSeasons(App $app): Seasons
{
    return new Seasons($app->db(), new Resm\AuditLog($app->db()));
}

function adminTeams(App $app): Teams
{
    return new Teams($app->db(), new Resm\AuditLog($app->db()));
}

function seasonsPage(App $app, ?string $error = null, ?string $notice = null): string
{
    return (new View($app))->render('admin/seasons', [
        'title' => 'Seasons',
        'seasons' => adminSeasons($app)->all(),
        'error' => $error,
        'notice' => $notice,
        'back' => ['url' => $app->url('admin'), 'label' => 'Admin Menu'],
    ]);
}

function teamsPage(App $app, ?string $error = null, ?string $notice = null): string
{
    $season = adminSeasons($app)->active();

    return (new View($app))->render('admin/teams', [
        'title' => 'Teams',
        'season' => $season,
        'teams' => $season === null ? [] : adminTeams($app)->forSeason((int) $season['id']),
        'error' => $error,
        'notice' => $notice,
        'back' => ['url' => $app->url('admin'), 'label' => 'Admin Menu'],
    ]);
}

function adminUsers(App $app): Users
{
    return new Users(
        $app->db(),
        new Resm\AuditLog($app->db()),
        $app->config->int('auth.pin_cost', 11),
        $app->config->string('auth.default_pin', '1234'),
    );
}

/**
 * What separates the two Create-User screens. The roles are the load-bearing
 * part: Users::create refuses anything not in this list, so the Committeeman
 * screen cannot be posted into an Admin account.
 *
 * @return array{heading: string, intro: string, roles: array<int, Role>, noun: string, nounPlural: string}
 */
function adminUserScreen(string $key): array
{
    return $key === 'officers'
        ? [
            'heading' => 'Create Officer / Admin',
            'intro' => 'Officers run a shift board for the teams they cover. Admins '
                . 'do that everywhere, and hold the screens on this menu. An officer '
                . 'on a team with an active shift appears in that shift\'s officer '
                . 'contact list.',
            'roles' => [Role::Officer, Role::Admin],
            'noun' => 'officer or admin',
            'nounPlural' => 'officers and admins',
        ]
        : [
            'heading' => 'Create Committeeman',
            'intro' => 'One at a time. For a whole roster, Import Roster takes the '
                . 'same fields as a CSV and shows you what it would do before it '
                . 'writes anything.',
            'roles' => [Role::Committeeman],
            'noun' => 'committeeman',
            'nounPlural' => 'committeemen',
        ];
}

/**
 * @param array<string, mixed> $form
 */
function usersPage(
    App $app,
    string $key,
    ?string $error = null,
    ?string $notice = null,
    string $search = '',
    array $form = [],
): string {
    $actor = $app->user();
    $screen = adminUserScreen($key);
    $season = adminSeasons($app)->active();
    $seasonId = $season === null ? 0 : (int) $season['id'];

    return (new View($app))->render('admin/users', [
        'title' => $screen['heading'],
        'endpoint' => 'admin/' . $key,
        'heading' => $screen['heading'],
        'intro' => $screen['intro'],
        'roles' => $screen['roles'],
        'noun' => $screen['noun'],
        'nounPlural' => $screen['nounPlural'],
        'season' => $season,
        // Only active teams can be assigned to. Last year's disbanded team is
        // still on the Teams screen and still owns its history; it is not a
        // place to put somebody this year.
        'teams' => $season === null
            ? []
            : array_values(array_filter(
                adminTeams($app)->forSeason($seasonId),
                static fn (array $t): bool => (int) $t['is_active'] === 1
            )),
        'people' => $season === null
            ? []
            : adminUsers($app)->withRoles($seasonId, $screen['roles'], $search),
        'search' => $search,
        'actorId' => $actor?->id ?? 0,
        'defaultPin' => $app->config->string('auth.default_pin', '1234'),
        'form' => $form,
        'error' => $error,
        'notice' => $notice,
        'back' => ['url' => $app->url('admin'), 'label' => 'Admin Menu'],
    ]);
}

function adminShifts(App $app): Shifts
{
    return new Shifts(
        $app->db(),
        new Resm\AuditLog($app->db()),
        new ShiftClock($app->displayTimezone()),
    );
}

function shiftsPage(
    App $app,
    ?string $error = null,
    ?string $notice = null,
    ?int $filterTeam = null,
    array $form = [],
): string {
    $season = adminSeasons($app)->active();
    $seasonId = $season === null ? 0 : (int) $season['id'];
    $shifts = adminShifts($app);

    return (new View($app))->render('admin/shifts', [
        'title' => 'Create Shifts',
        'season' => $season,
        // Only active teams can take a new shift, same rule as team assignment.
        'teams' => $season === null
            ? []
            : array_values(array_filter(
                adminTeams($app)->forSeason($seasonId),
                static fn (array $t): bool => (int) $t['is_active'] === 1
            )),
        'groups' => $shifts->allGroups(),
        'defaultGroups' => $shifts->defaultGroupIds(),
        'types' => ShiftType::all(),
        'shifts' => $season === null ? [] : $shifts->forSeason($seasonId, $filterTeam),
        'clock' => new ShiftClock($app->displayTimezone()),
        'filterTeam' => $filterTeam,
        'form' => $form,
        'error' => $error,
        'notice' => $notice === '' ? null : $notice,
        'scripts' => ['js/shift-form.js'],
        'back' => ['url' => $app->url('admin'), 'label' => 'Admin Menu'],
    ]);
}

function rosterImport(App $app): RosterImport
{
    return new RosterImport(
        $app->db(),
        new Resm\AuditLog($app->db()),
        $app->config->int('auth.pin_cost', 11),
        $app->config->string('auth.default_pin', '1234'),
    );
}

function importFile(App $app): ImportFile
{
    // Outside public_html, like everything under var/.
    return new ImportFile($app->root . '/var/imports');
}

/**
 * The uploaded file, or null when the browser sent nothing usable.
 *
 * @return array{content: string, name: string}|null
 */
function importUpload(): ?array
{
    $file = $_FILES['roster'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $path = $file['tmp_name'] ?? '';
    if (!is_string($path) || $path === '' || !is_uploaded_file($path)) {
        return null;
    }

    $content = @file_get_contents($path);
    if ($content === false || $content === '') {
        return null;
    }

    return ['content' => $content, 'name' => basename((string) ($file['name'] ?? 'roster.csv'))];
}

/**
 * Why the upload did not arrive, in words an administrator can act on.
 *
 * post_max_size is 8M and upload_max_filesize 2M on this host
 * (docs/hosting.md); a file over post_max_size arrives as an empty $_FILES
 * with no error code at all, which is why the default says what it does.
 */
function importUploadError(): string
{
    $code = $_FILES['roster']['error'] ?? UPLOAD_ERR_NO_FILE;

    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server accepts.',
        UPLOAD_ERR_PARTIAL => 'That upload did not finish. Try again.',
        UPLOAD_ERR_NO_FILE => 'Choose a CSV file first. If you did, it may be too large to upload.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the upload.',
        default => 'That upload did not arrive.',
    };
}

function importPage(App $app, ?string $error = null, ?string $notice = null): string
{
    $season = adminSeasons($app)->active();
    $seasonId = $season === null ? 0 : (int) $season['id'];
    $held = importFile($app);

    // Only build a plan when there is a file to build it from; the upload form
    // is what shows otherwise.
    $content = $held->contents();
    $plan = null;
    if ($season !== null && $content !== null) {
        $plan = rosterImport($app)->plan($content, $seasonId);
        if (!$plan['ok']) {
            $error ??= $plan['error'];
            $held->discard();
            $plan = null;
        }
    }

    return (new View($app))->render('admin/import', [
        'title' => 'Import Roster',
        'season' => $season,
        'teams' => $season === null
            ? []
            : array_values(array_filter(
                adminTeams($app)->forSeason($seasonId),
                static fn (array $t): bool => (int) $t['is_active'] === 1
            )),
        'plan' => $plan,
        'fileName' => $held->name(),
        'error' => $error,
        'notice' => $notice,
        'back' => ['url' => $app->url('admin'), 'label' => 'Admin Menu'],
    ]);
}

function currentShift(App $app): CurrentShift
{
    return new CurrentShift($app->db(), $app->displayTimezone());
}

function attendance(App $app): Attendance
{
    return new Attendance($app->db(), new Resm\AuditLog($app->db()));
}

function checkInPage(
    App $app,
    Resm\Auth\Identity $user,
    ?Request $request = null,
    string $wantedShift = '',
    ?string $error = null,
): string {
    $season = adminSeasons($app)->active();
    $seasonId = $season === null ? 0 : (int) $season['id'];
    $shifts = currentShift($app);

    $resolved = $season === null
        ? ['current' => null, 'candidates' => [], 'doubled' => false]
        : $shifts->forUser($user->id, $seasonId);

    // An explicit choice from the switcher wins over the resolved default,
    // but only if it is genuinely one of his.
    $shift = $resolved['current'];
    if ($wantedShift !== '' && $season !== null) {
        $picked = $shifts->pick($user->id, $seasonId, (int) $wantedShift);
        if ($picked !== null) {
            $shift = $picked;
        }
    }

    $confirmed = null;
    $at = $request?->input('at', '') ?? '';
    if ($at !== '') {
        try {
            $confirmed = (new ShiftClock($app->displayTimezone()))
                ->display(new DateTimeImmutable($at), 'D j M, H:i');
        } catch (Throwable) {
            // A hand-edited timestamp in the query string. The page is still
            // correct without the confirmation line.
            $confirmed = null;
        }
    }

    return (new View($app))->render('check-in', [
        'title' => 'Check In / Out',
        'shift' => $shift,
        'candidates' => $resolved['candidates'],
        'clock' => new ShiftClock($app->displayTimezone()),
        'confirmed' => $confirmed,
        'vacated' => (int) ($request?->input('freed', '0') ?? 0),
        'error' => $error,
        'back' => ['url' => $app->url(), 'label' => 'Menu'],
    ]);
}

function myShiftPage(
    App $app,
    Resm\Auth\Identity $user,
    ?Request $request = null,
    ?string $error = null,
): string {
    $season = adminSeasons($app)->active();
    $seasonId = $season === null ? 0 : (int) $season['id'];
    $shifts = currentShift($app);

    $resolved = $season === null
        ? ['current' => null, 'candidates' => [], 'doubled' => false]
        : $shifts->forUser($user->id, $seasonId);

    $shift = $resolved['current'];
    $wanted = (string) ($request?->input('shift', '') ?? '');
    if ($wanted !== '' && $season !== null) {
        $picked = $shifts->pick($user->id, $seasonId, (int) $wanted);
        if ($picked !== null) {
            $shift = $picked;
        }
    }

    $assignment = null;
    $mates = [];
    $officers = [];

    if ($shift !== null) {
        $roster = new Roster($app->db());
        $assignment = attendance($app)->assignments((int) $shift['id'], $user->id)[(string) $shift['current_phase']] ?? null;
        $officers = $roster->officers((int) $shift['id']);

        if ($assignment !== null) {
            // The group id comes from the assignment itself. Looking it up by
            // position label would be wrong: labels are unique within a group,
            // not across them.
            $mates = $roster->groupMates(
                (int) $shift['id'],
                (string) $shift['current_phase'],
                $user->id,
                (int) $assignment['group_id']
            );
        }
    }

    return (new View($app))->render('my-shift', [
        'title' => 'My Shift Status',
        'shift' => $shift,
        'candidates' => $resolved['candidates'],
        'assignment' => $assignment,
        'mates' => $mates,
        'officers' => $officers,
        'clock' => new ShiftClock($app->displayTimezone()),
        'error' => $error,
        'notice' => ($request?->input('done') !== null) ? 'Saved.' : null,
        'back' => ['url' => $app->url(), 'label' => 'Menu'],
    ]);
}

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
