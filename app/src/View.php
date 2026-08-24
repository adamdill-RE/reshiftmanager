<?php

declare(strict_types=1);

namespace Resm;

use RuntimeException;
use Throwable;

/**
 * Plain PHP templates from app/views, wrapped in a layout.
 *
 * No template engine: there is no build step on this host, and PHP with
 * escaping applied at every echo is the smaller, faster thing here.
 */
final class View
{
    public function __construct(private App $app)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param string|null $layout view name to wrap this in, or null for none
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->capture($template, $data);

        if ($layout === null) {
            return $content;
        }

        return $this->capture($layout, $data + [
            'content' => $content,
            'title'   => $data['title'] ?? $this->app->config->string('app.name', 'Rodeo Express'),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->app->root . '/app/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$template}");
        }

        // $app is available to every template; $data keys become locals.
        $app = $this->app;
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
