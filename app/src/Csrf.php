<?php

declare(strict_types=1);

namespace Resm;

/**
 * CSRF tokens on every state-changing request (spec 10.5).
 *
 * One token per session rather than one per form: a committeeman on a bad
 * connection will resubmit, go back, and open the same screen twice, and a
 * single-use token turns every one of those into a mysterious failure. The
 * token is rotated when privilege changes — at sign-in and at PIN change.
 */
final class Csrf
{
    public const FIELD = '_csrf';

    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /** Constant-time comparison; a missing or non-string value is a failure. */
    public static function check(mixed $provided): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '' || !is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /** Ready-to-echo hidden input. Already escaped. */
    public static function field(): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s">', self::FIELD, e(self::token()));
    }
}
