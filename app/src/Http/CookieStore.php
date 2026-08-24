<?php

declare(strict_types=1);

namespace Resm\Http;

/**
 * Where cookies are read from and written to.
 *
 * An interface rather than calls to setcookie() spread through Auth, for two
 * reasons: the flags that matter here — path /resm/, HttpOnly, Secure,
 * SameSite=Lax — get stated once instead of at each call site, and the
 * remember-me token's rotation can be tested without a browser.
 */
interface CookieStore
{
    public function get(string $name): ?string;

    public function set(string $name, string $value, int $expiresAt): void;

    public function forget(string $name): void;
}
