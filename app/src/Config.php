<?php

declare(strict_types=1);

namespace Resm;

use RuntimeException;

/**
 * Immutable configuration, read with dotted keys: $config->get('db.host').
 *
 * Layered, later winning: config/config.php, config/config.local.php, then
 * RESM_* environment variables. The local file is how the server gets its
 * database credentials without them entering git; the environment layer is
 * how docker-compose overrides things for local development.
 */
final class Config
{
    /** Environment variable => dotted config key. */
    private const ENV_MAP = [
        'RESM_BASE_PATH'      => 'app.base_path',
        'RESM_DEBUG'          => 'app.debug',
        'RESM_STATUS_KEY'     => 'app.status_key',
        'RESM_SETUP_KEY'      => 'app.setup_key',
        'RESM_DB_HOST'        => 'db.host',
        'RESM_DB_PORT'        => 'db.port',
        'RESM_DB_NAME'        => 'db.name',
        'RESM_DB_USER'        => 'db.user',
        'RESM_DB_PASS'        => 'db.pass',
        'RESM_DB_SOCKET'      => 'db.socket',
        'RESM_SESSION_SECURE' => 'session.secure',
        'RESM_SESSION_PATH'   => 'session.save_path',
    ];

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $configDir): self
    {
        $base = $configDir . '/config.php';
        if (!is_file($base)) {
            throw new RuntimeException("Missing configuration file: {$base}");
        }

        /** @var array<string, mixed> $values */
        $values = require $base;

        $local = $configDir . '/config.local.php';
        if (is_file($local)) {
            $overrides = self::readLocal($local);

            // An empty file is the usual cause: require returns int(1) for
            // one, and without this check that became an uncaught TypeError
            // deep in merge() and a blank page with nothing on screen to act
            // on. Say what is wrong instead.
            if (!is_array($overrides)) {
                throw new ConfigurationError(sprintf(
                    'config.local.php must return an array, but returned %s. '
                    . 'An empty or unsaved file is the usual cause - it should '
                    . 'start with "<?php" and end with a "return [...];".',
                    get_debug_type($overrides)
                ));
            }

            /** @var array<string, mixed> $overrides */
            $values = self::merge($values, $overrides);
        }

        foreach (self::ENV_MAP as $env => $key) {
            $raw = getenv($env);
            if ($raw === false || $raw === '') {
                continue;
            }
            self::assign($values, $key, self::coerce($raw));
        }

        return new self($values);
    }

    /**
     * Read config.local.php, turning a syntax error into something readable.
     *
     * This is the only PHP file on the server that is edited by hand, by an
     * administrator, in cPanel's File Manager - it holds the database password
     * and the keys, so it is not in git and the deploy never overwrites it.
     * There is no shell on that account, so there is nothing to lint it with
     * and no way to find out what went wrong except the error log.
     *
     * A missing comma there is a PHP parse error, which takes down every page
     * on the site with an empty 500 and no clue on screen. That has happened.
     * A ParseError from require IS catchable, so it becomes the same kind of
     * message the empty-file case below already gets: what is wrong, and which
     * line to go and look at.
     *
     * @throws ConfigurationError
     */
    private static function readLocal(string $file): mixed
    {
        try {
            return require $file;
        } catch (\ParseError $e) {
            throw new ConfigurationError(sprintf(
                'config.local.php has a syntax error on line %d: %s. '
                . 'A missing comma at the end of the line ABOVE is the usual '
                . 'cause - every entry in the array needs one, including the last.',
                $e->getLine(),
                $e->getMessage()
            ));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cursor = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Same as get(), but a missing key is a programming error rather than a
     * reason to silently fall back.
     */
    public function require(string $key): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($key, $sentinel);
        if ($value === $sentinel) {
            throw new RuntimeException("Missing required configuration key: {$key}");
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return $value === null ? $default : (string) $value;
    }

    /**
     * Recursive merge where the override wins. Lists are replaced outright
     * rather than appended — an override that lists two teams means two, not
     * two added to whatever was there.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /** @param array<string, mixed> $values */
    private static function assign(array &$values, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &$values;
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }

    /** Environment variables are strings; give the obvious ones their real type. */
    private static function coerce(string $raw): string|int|bool
    {
        $lower = strtolower($raw);
        if (in_array($lower, ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($lower, ['false', 'no', 'off'], true)) {
            return false;
        }
        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return $raw;
    }
}
