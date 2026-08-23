<?php

declare(strict_types=1);

namespace Resm;

use RuntimeException;

/**
 * Session startup.
 *
 * Every setting below is stated explicitly. The host's defaults have
 * cookie_httponly off, cookie_secure off, samesite unset, use_strict_mode off
 * and cookie_path "/" (docs/hosting.md) — all five wrong for this app, and all
 * five able to change under us on a shared box. Nothing here is inherited.
 *
 * This is the short-lived request session only. It carries the live identity
 * and nothing else; the 90-day "keep me signed in" is a separate DB-backed
 * rotating token, because session.gc_maxlifetime is 1440s here and garbage
 * collection belongs to the host.
 */
final class Session
{
    public static function start(Config $config, string $appRoot): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $savePath = $config->get('session.save_path');
        if (!is_string($savePath) || $savePath === '') {
            $savePath = $appRoot . '/var/sessions';
        }

        // 0700 is right here *because* this directory sits outside
        // public_html. The "0700 yields 404" trap in docs/hosting.md applies
        // to web-reachable directories; this one is reached by filesystem
        // path only, and the tighter mode keeps session files off the
        // cPanel-wide save path shared with every other account's app.
        if (!is_dir($savePath) && !mkdir($savePath, 0700, true) && !is_dir($savePath)) {
            throw new RuntimeException("Cannot create session directory: {$savePath}");
        }

        ini_set('session.save_path', $savePath);

        // Reject a session id the browser was never issued, so an attacker
        // cannot fixate one.
        ini_set('session.use_strict_mode', '1');

        // The id travels in the cookie and nowhere else — never in a URL.
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '5');
        ini_set('session.cache_limiter', 'nocache');

        session_name($config->string('session.name', 'RESMSESS'));

        session_set_cookie_params([
            'lifetime' => $config->int('session.lifetime', 0),

            // Scoped to the app's subpath, not the domain root — other things
            // may be hosted beside /resm/ and have no business receiving this.
            'path'     => $config->string('app.base_path', '/resm/'),
            'domain'   => '',

            // False only for local http development (RESM_SESSION_SECURE=0).
            'secure'   => $config->bool('session.secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Rotate the session id while keeping the data. Called on privilege
     * changes — login, and PIN change.
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Drop the session and expire its cookie on the client. */
    public static function destroy(Config $config): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $config->bool('session.secure', true),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
    }
}
