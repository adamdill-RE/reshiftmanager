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
            $overrides = require $local;

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
