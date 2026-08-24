<?php

declare(strict_types=1);

namespace Resm;

/**
 * Composition root. One instance per request (or per CLI script), created by
 * app/bootstrap.php and passed down; nothing reaches for a global.
 */
final class App
{
    private ?Database $db = null;
    private ?Http\Request $request = null;
    private ?Auth\Auth $auth = null;

    private function __construct(
        public readonly Config $config,
        public readonly string $root,
    ) {
    }

    public static function boot(string $root): self
    {
        return new self(Config::load($root . '/config'), $root);
    }

    public function db(): Database
    {
        if (!$this->db instanceof Database) {
            /** @var array<string, mixed> $settings */
            $settings = $this->config->get('db', []);
            $this->db = new Database($settings);
        }

        return $this->db;
    }

    public function startSession(): void
    {
        Session::start($this->config, $this->root);
    }

    /**
     * Hand the app the request being served. Auth needs it for the client
     * address and user agent it records against a sign-in, so this is bound
     * once by the front controller before anything is dispatched.
     */
    public function bindRequest(Http\Request $request): void
    {
        $this->request = $request;
        $this->auth = null;
    }

    public function auth(): Auth\Auth
    {
        if (!$this->auth instanceof Auth\Auth) {
            if (!$this->request instanceof Http\Request) {
                throw new \RuntimeException('No request is bound; Auth cannot record where a sign-in came from.');
            }
            $this->auth = new Auth\Auth($this, $this->request);
        }

        return $this->auth;
    }

    /** The signed-in user, or null. Shorthand for auth()->user(). */
    public function user(): ?Auth\Identity
    {
        return $this->auth()->user();
    }

    /**
     * The subpath the app is mounted at, with a trailing slash: "/resm/".
     */
    public function basePath(): string
    {
        $base = $this->config->string('app.base_path', '/resm/');

        return rtrim($base, '/') . '/';
    }

    /**
     * Build an application URL. Callers pass a path relative to the app root
     * with no leading slash — url('officer/assign'), not url('/officer/assign')
     * — and this is the only place the /resm/ prefix is applied.
     */
    public function url(string $path = ''): string
    {
        return $this->basePath() . ltrim($path, '/');
    }

    /** Asset URLs get a cache-busting stamp from the file's mtime. */
    public function asset(string $path): string
    {
        $relative = 'assets/' . ltrim($path, '/');
        $url = $this->url($relative);

        $file = $this->root . '/public/' . $relative;
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }

        return $url;
    }

    public function isDebug(): bool
    {
        return $this->config->bool('app.debug', false);
    }

    public function displayTimezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->config->string('app.display_timezone', 'America/Chicago'));
    }

    /**
     * Convert a stored UTC timestamp for display.
     *
     * Never do this with a fixed offset: the season spans the March DST
     * transition, and a shift running 16:45–02:00 on that night is only
     * correct through a real timezone.
     */
    public function forDisplay(\DateTimeImmutable $utc): \DateTimeImmutable
    {
        return $utc->setTimezone($this->displayTimezone());
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
