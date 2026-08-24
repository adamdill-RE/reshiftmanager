<?php

declare(strict_types=1);

namespace Resm\Http;

/**
 * Real cookies, with the app's flags applied to every one.
 *
 * The host's defaults are wrong on all of these (docs/hosting.md), so nothing
 * is left unstated: scoped to the app's subpath rather than the domain root,
 * unreadable from JavaScript, HTTPS-only outside local development, and not
 * sent on cross-site requests.
 */
final class Cookies implements CookieStore
{
    public function __construct(
        private string $path,
        private bool $secure,
    ) {
    }

    public function get(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function set(string $name, string $value, int $expiresAt): void
    {
        // Nothing to do if the response has already begun; the caller cannot
        // fix it and an error here would lose the page.
        if (headers_sent()) {
            return;
        }

        setcookie($name, $value, $this->options($expiresAt));
        $_COOKIE[$name] = $value;
    }

    public function forget(string $name): void
    {
        unset($_COOKIE[$name]);

        if (headers_sent()) {
            return;
        }

        setcookie($name, '', $this->options(time() - 42000));
    }

    /** @return array<string, mixed> */
    private function options(int $expiresAt): array
    {
        return [
            'expires'  => $expiresAt,
            'path'     => $this->path,
            'domain'   => '',
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
