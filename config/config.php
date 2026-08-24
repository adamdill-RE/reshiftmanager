<?php

declare(strict_types=1);

/**
 * Base configuration.
 *
 * This file is committed, so it holds no credentials. Two things override it,
 * in this order:
 *
 *   1. config/config.local.php   — gitignored, returns a partial array.
 *   2. RESM_* environment variables — see Resm\Config::ENV_MAP.
 *
 * On the server this file lives outside public_html, so even the defaults are
 * not web-readable.
 */
return [
    'app' => [
        'name' => 'Rodeo Express',

        // The app is served from https://www.reshiftmanager.com/resm/, never
        // the domain root. Every link, form action, redirect, asset URL and
        // cookie path is built from this value — nothing hard-codes a leading
        // slash path. Keep the trailing slash.
        'base_path' => '/resm/',

        // Everything is stored and compared in UTC; this is display only.
        'display_timezone' => 'America/Chicago',

        // Verbose errors on screen. Never true on the server.
        'debug' => false,

        // Guards /status. Null means the page 404s unless debug is on.
        'status_key' => null,

        // Guards /setup, which applies migrations and sets an administrator's
        // PIN from a browser. That exists because this account has no SSH and
        // no Terminal, so bin/migrate.php and bin/set-admin-pin.php cannot be
        // reached any other way.
        //
        // It is a genuine administrative credential: anyone holding it can
        // take the master admin account. Null disables /setup outright, and
        // that is the state to leave it in once the app is running — clearing
        // this key is how the door gets locked again.
        'setup_key' => null,
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'resm',
        'user'    => 'resm',
        'pass'    => '',
        // Set to a socket path to connect over a unix socket instead of TCP.
        'socket'  => null,
    ],

    'session' => [
        'name' => 'RESMSESS',

        // The host defaults for every setting below are unsafe (docs/hosting.md);
        // Resm\Session sets all of them explicitly before session_start().
        // 'secure' is the one that varies by environment: local development is
        // plain http, so docker-compose sets RESM_SESSION_SECURE=0 there.
        'secure' => true,

        // Browser-session cookie. The 90-day "keep me signed in" is a separate
        // DB-backed token, because session.gc_maxlifetime here is 1440s and
        // garbage collection is not ours to govern on shared hosting.
        'lifetime' => 0,

        // Null uses <app_root>/var/sessions rather than the cPanel-wide
        // /var/cpanel/php/sessions/ea-php82 directory.
        'save_path' => null,
    ],

    'auth' => [
        // Every imported or newly created account starts here (spec 3.1).
        'default_pin' => '1234',

        // bcrypt. argon2id's default 64MB memory_cost per hash is unusable
        // against a 128MB memory_limit when 100 people log in at shift start
        // (docs/hosting.md).
        'pin_algo' => PASSWORD_BCRYPT,
        'pin_cost' => 11,

        // Rolling persistent login (spec 3.2). Deliberately long: a
        // committeeman logs in once at the start of the season.
        'remember_days' => 90,

        // Deliberately loose (spec 3.2): a locked-out officer at 17:00 is a
        // worse outcome than a brute-force attempt on an internal shift tool.
        'lockout_attempts'      => 10,
        'lockout_window_seconds' => 900,
        'lockout_seconds'        => 60,
    ],

    'poll' => [
        // Spec 10.2. Held-open connections are impossible under CloudLinux LVE,
        // so clients short-poll a state_version integer instead.
        'foreground_seconds' => 10,
        'background_seconds' => 60,
    ],
];
