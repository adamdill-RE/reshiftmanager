<?php

declare(strict_types=1);

namespace Resm;

use Throwable;

/**
 * Deployment self-checks.
 *
 * docs/hosting.md records a diagnostic script being uploaded by hand to read
 * the runtime and then deleted. This replaces that: the same answers, plus the
 * things that are easy to get wrong on a file-copy deploy — code sitting
 * inside the document root, a cookie scoped to "/" instead of "/resm/",
 * migrations not run. It is reachable only with the status key set in
 * config.local.php, or with debug on.
 */
final class Diagnostics
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Extensions the application actually uses. All were present on 2026-08-23. */
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'session'];

    public function __construct(private App $app)
    {
    }

    /** @return array<int, array{name: string, status: string, detail: string}> */
    public function run(): array
    {
        return [
            $this->phpVersion(),
            $this->extensions(),
            $this->timezone(),
            $this->codeOutsideDocumentRoot(),
            $this->basePath(),
            $this->sessionCookie(),
            $this->database(),
            $this->migrations(),
            $this->positionMatrix(),
        ];
    }

    /** @param array<int, array{name: string, status: string, detail: string}> $checks */
    public static function worst(array $checks): string
    {
        foreach ([self::FAIL, self::WARN] as $level) {
            foreach ($checks as $check) {
                if ($check['status'] === $level) {
                    return $level;
                }
            }
        }

        return self::PASS;
    }

    /** @return array{name: string, status: string, detail: string} */
    private static function check(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }

    /** @return array{name: string, status: string, detail: string} */
    private function phpVersion(): array
    {
        return self::check(
            'PHP runtime',
            PHP_VERSION_ID >= 80100 ? self::PASS : self::FAIL,
            sprintf('%s via %s', PHP_VERSION, PHP_SAPI)
        );
    }

    /** @return array{name: string, status: string, detail: string} */
    private function extensions(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $ext): bool => !extension_loaded($ext)
        ));

        if ($missing !== []) {
            return self::check('Extensions', self::FAIL, 'missing: ' . implode(', ', $missing));
        }

        // Absent on the production host and deliberately unused: intl and
        // sodium (docs/hosting.md). Noted, not required.
        $notes = [];
        foreach (['opcache' => 'every request recompiles', 'intl' => 'unused by design', 'sodium' => 'unused by design'] as $ext => $note) {
            if (!extension_loaded($ext)) {
                $notes[] = "{$ext} absent — {$note}";
            }
        }

        return self::check(
            'Extensions',
            self::PASS,
            implode('; ', array_merge(['all required present'], $notes))
        );
    }

    /** @return array{name: string, status: string, detail: string} */
    private function timezone(): array
    {
        $tz = date_default_timezone_get();

        return self::check(
            'Timezone',
            $tz === 'UTC' ? self::PASS : self::FAIL,
            sprintf('storing in %s, displaying in %s', $tz, $this->app->config->string('app.display_timezone'))
        );
    }

    /**
     * The rule that everything else depends on: app/ must not be web-readable.
     *
     * DOCUMENT_ROOT on this host is public_html itself and the app is served
     * from public_html/resm/, so anything under public_html is reachable by
     * URL. Application code therefore lives in a sibling directory
     * (/home/reshiftmanager/resm-app/) and is reached by filesystem path.
     * An .htaccess rule in front of code inside the document root would be
     * strictly weaker.
     *
     * @return array{name: string, status: string, detail: string}
     */
    private function codeOutsideDocumentRoot(): array
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $appDir = realpath($this->app->root . '/app');

        if (!is_string($documentRoot) || $documentRoot === '' || $appDir === false) {
            return self::check('Code outside the document root', self::WARN, 'no DOCUMENT_ROOT to compare against (CLI?)');
        }

        $documentRoot = realpath($documentRoot);
        if ($documentRoot === false) {
            return self::check('Code outside the document root', self::WARN, 'DOCUMENT_ROOT does not resolve');
        }

        $inside = str_starts_with($appDir . DIRECTORY_SEPARATOR, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

        return self::check(
            'Code outside the document root',
            $inside ? self::FAIL : self::PASS,
            $inside
                ? "app/ is inside {$documentRoot} and may be served as text — move it to a sibling of public_html"
                : 'app/ is not under the document root'
        );
    }

    /** @return array{name: string, status: string, detail: string} */
    private function basePath(): array
    {
        $base = $this->app->basePath();

        return self::check(
            'Base path',
            str_starts_with($base, '/') && str_ends_with($base, '/') ? self::PASS : self::FAIL,
            sprintf('links built from %s', $base)
        );
    }

    /**
     * Every one of these is off or wrong by default on this host, so read them
     * back rather than trusting that they were set.
     *
     * @return array{name: string, status: string, detail: string}
     */
    private function sessionCookie(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return self::check('Session cookie', self::WARN, 'no session started on this request');
        }

        $params = session_get_cookie_params();
        $expectedPath = $this->app->basePath();
        $wantSecure = $this->app->config->bool('session.secure', true);

        $problems = [];
        if (!$params['httponly']) {
            $problems[] = 'httponly off';
        }
        if ($params['secure'] !== $wantSecure) {
            $problems[] = 'secure ' . ($params['secure'] ? 'on' : 'off');
        }
        if (($params['samesite'] ?? '') !== 'Lax') {
            $problems[] = 'samesite ' . ($params['samesite'] === '' ? 'unset' : (string) $params['samesite']);
        }
        if ($params['path'] !== $expectedPath) {
            $problems[] = "path {$params['path']}";
        }
        if (ini_get('session.use_strict_mode') !== '1') {
            $problems[] = 'use_strict_mode off';
        }

        // Secure off is correct for local http development and wrong anywhere
        // else; the config decides which this is, and the check compares
        // against that rather than against a fixed expectation.
        return self::check(
            'Session cookie',
            $problems === [] ? self::PASS : self::FAIL,
            $problems === []
                ? sprintf('HttpOnly, SameSite=Lax, path %s, strict mode, secure %s', $params['path'], $wantSecure ? 'on' : 'off')
                : implode(', ', $problems)
        );
    }

    /** @return array{name: string, status: string, detail: string} */
    private function database(): array
    {
        try {
            $version = (string) $this->app->db()->value('SELECT VERSION()');
            $timeZone = (string) $this->app->db()->value('SELECT @@session.time_zone');

            return self::check(
                'Database',
                $timeZone === '+00:00' ? self::PASS : self::FAIL,
                sprintf('%s, session time_zone %s', $version, $timeZone)
            );
        } catch (Throwable $e) {
            return self::check('Database', self::FAIL, $e->getMessage());
        }
    }

    /** @return array{name: string, status: string, detail: string} */
    private function migrations(): array
    {
        try {
            $migrator = new Migrator($this->app->db(), $this->app->root . '/db/migrations');
            $migrator->ensureRegistry();

            $applied = count($migrator->applied());
            $pending = count($migrator->pending());
            $drift = $migrator->drift();

            if ($drift !== []) {
                return self::check('Migrations', self::FAIL, implode('; ', $drift));
            }

            return self::check(
                'Migrations',
                $pending === 0 ? self::PASS : self::FAIL,
                $pending === 0
                    ? sprintf('%d applied, none pending', $applied)
                    : sprintf('%d applied, %d PENDING — run php bin/migrate.php', $applied, $pending)
            );
        } catch (Throwable $e) {
            return self::check('Migrations', self::FAIL, $e->getMessage());
        }
    }

    /** @return array{name: string, status: string, detail: string} */
    private function positionMatrix(): array
    {
        try {
            $positions = (int) $this->app->db()->value('SELECT COUNT(*) FROM position');
            $phases = (int) $this->app->db()->value('SELECT COUNT(*) FROM position_phase');
            $groups = (int) $this->app->db()->value('SELECT COUNT(*) FROM position_group');

            $expected = $positions === 98 && $phases === 157 && $groups === 10;

            return self::check(
                'Position matrix',
                $expected ? self::PASS : self::WARN,
                sprintf(
                    '%d positions, %d position-phase records, %d groups%s',
                    $positions,
                    $phases,
                    $groups,
                    $expected ? '' : ' — differs from the seed; edited via the Position Matrix Editor?'
                )
            );
        } catch (Throwable $e) {
            return self::check('Position matrix', self::FAIL, $e->getMessage());
        }
    }
}
