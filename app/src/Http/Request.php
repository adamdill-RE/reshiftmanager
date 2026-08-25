<?php

declare(strict_types=1);

namespace Resm\Http;

use Resm\App;

/**
 * The incoming request, with the /resm/ prefix already stripped.
 *
 * Handlers see paths relative to the app root — 'officer/assign', never
 * '/resm/officer/assign' — so nothing downstream has to know where the app is
 * mounted.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
    ) {
    }

    public static function capture(App $app): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        // Strip the mount point. A request for the app root arrives as
        // "/resm/" and becomes "".
        $base = rtrim($app->basePath(), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return new self(
            $method,
            trim($path, '/'),
            $_GET,
            $_POST,
            $_SERVER
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** A query or form value as a trimmed string, or null when absent. */
    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        return $default;
    }

    /**
     * A repeated form field — a group of checkboxes posts `team_ids[]` — as a
     * list of trimmed strings.
     *
     * Anything that is not a scalar is dropped rather than handed on: PHP will
     * build a nested array out of `team_ids[a][b]` without being asked, and no
     * caller here wants one.
     *
     * @return array<int, string>
     */
    public function inputList(string $key): array
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? null;

        if (!is_array($value)) {
            return is_string($value) ? [trim($value)] : [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $items[] = trim((string) $item);
            }
        }

        return $items;
    }

    /**
     * The client address, as text.
     *
     * Read straight from REMOTE_ADDR. X-Forwarded-For is deliberately ignored:
     * LiteSpeed hands us the real peer, and trusting a client-supplied header
     * would let anyone spread their login attempts across imaginary addresses
     * and walk around the rate limit.
     */
    public function ip(): ?string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /** Packed form for the VARBINARY(16) columns; null if unparseable. */
    public function ipBinary(): ?string
    {
        $ip = $this->ip();
        if ($ip === null) {
            return null;
        }
        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    public function userAgent(): ?string
    {
        $agent = $this->server['HTTP_USER_AGENT'] ?? null;

        return is_string($agent) ? substr($agent, 0, 255) : null;
    }

    /** True when the client asked for JSON — used by the polling endpoint. */
    public function wantsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');

        return str_contains($accept, 'application/json');
    }
}
