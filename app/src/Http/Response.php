<?php

declare(strict_types=1);

namespace Resm\Http;

/**
 * A response, assembled and then sent. Handlers return one of these rather
 * than echoing, so a route can be tested without output buffering and headers
 * are never sent before the handler has finished deciding.
 */
final class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        public readonly int $status,
        private array $headers,
        private string $body,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * The polling endpoint's cheap answer: nothing changed, here are no bytes
     * (spec 10.2).
     */
    public static function notModified(): self
    {
        return new self(304, [], '');
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['Location' => $url], '');
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        // Nothing loads from a CDN — the host has no build step and every
        // asset ships with the app — so the policy can be this tight.
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
            . "style-src 'self'; script-src 'self'; form-action 'self'; "
            . "frame-ancestors 'none'; base-uri 'self'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
