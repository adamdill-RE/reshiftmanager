<?php

declare(strict_types=1);

namespace Resm\Http;

use Resm\App;

/**
 * Route table. Exact paths, plus {name} placeholders for the officer screens
 * that address a phase or a position.
 *
 * Matching a route is presentation, never authorisation. Every handler that
 * touches team data re-checks role and team scope server-side, because hiding
 * a menu tile hides nothing (spec 10.5).
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: callable}> */
    private array $routes = [];

    /** @var null|callable(App, Request): Response */
    private $fallback = null;

    public function get(string $pattern, callable $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    /** @param callable(App, Request): Response $handler */
    public function notFound(callable $handler): self
    {
        $this->fallback = $handler;

        return $this;
    }

    private function add(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'regex'   => self::compile($pattern),
            'handler' => $handler,
        ];

        return $this;
    }

    /** '/officer/assign/{phase}' becomes a regex capturing 'phase'. */
    private static function compile(string $pattern): string
    {
        $quoted = preg_quote(trim($pattern, '/'), '#');

        // preg_quote escapes the braces, so match the escaped form.
        $withParams = preg_replace(
            '#\\\\\{([a-z_]+)\\\\\}#i',
            '(?P<$1>[^/]+)',
            $quoted
        );

        return '#^' . $withParams . '$#';
    }

    public function dispatch(App $app, Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return ($route['handler'])($app, $request, $params);
        }

        // A known path reached by the wrong verb is a 405, not a 404 — it says
        // "that exists, you asked wrong", which is the difference between a
        // five-minute bug and an hour of hunting.
        if ($pathMatched) {
            return Response::text("Method not allowed.\n", 405);
        }

        if ($this->fallback !== null) {
            return ($this->fallback)($app, $request);
        }

        return Response::text("Not found.\n", 404);
    }
}
