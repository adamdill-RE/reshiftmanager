<?php

declare(strict_types=1);

/**
 * A ~100-line test runner.
 *
 * The host has no Composer and no build step (CLAUDE.md), so PHPUnit is not
 * available and is not worth the dependency here. This gives the two things
 * that actually matter: a non-zero exit code when something breaks, and a
 * message that names what broke.
 *
 *   php tests/run.php            run every test
 *   php tests/run.php sql        run files matching "sql"
 */

require __DIR__ . '/../app/bootstrap.php';

final class TestRunner
{
    /** @var array<int, array{name: string, fn: callable}> */
    private static array $tests = [];

    private static int $passed = 0;
    /** @var array<int, string> */
    private static array $failures = [];
    /** @var array<int, string> */
    private static array $skipped = [];

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    public static function run(): int
    {
        foreach (self::$tests as $test) {
            try {
                ($test['fn'])();
                self::$passed++;
                fwrite(STDOUT, ".");
            } catch (SkippedTest $e) {
                self::$skipped[] = $test['name'] . ' — ' . $e->getMessage();
                fwrite(STDOUT, "s");
            } catch (Throwable $e) {
                self::$failures[] = $test['name'] . "\n    " . $e->getMessage();
                fwrite(STDOUT, "F");
            }
        }

        fwrite(STDOUT, "\n\n");

        foreach (self::$skipped as $skip) {
            fwrite(STDOUT, "SKIP  {$skip}\n");
        }
        foreach (self::$failures as $failure) {
            fwrite(STDOUT, "FAIL  {$failure}\n");
        }

        $summary = sprintf(
            "%d passed, %d failed, %d skipped\n",
            self::$passed,
            count(self::$failures),
            count(self::$skipped)
        );
        fwrite(STDOUT, "\n" . $summary);

        return self::$failures === [] ? 0 : 1;
    }
}

final class SkippedTest extends RuntimeException
{
}

function test(string $name, callable $fn): void
{
    TestRunner::add($name, $fn);
}

function skip(string $why): never
{
    throw new SkippedTest($why);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%sexpected %s, got %s",
            $message === '' ? '' : $message . ': ',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(bool $actual, string $message = 'expected true'): void
{
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function assertCount(int $expected, array $actual, string $message = ''): void
{
    assertSame($expected, count($actual), $message === '' ? 'count' : $message);
}

function assertThrows(string $class, callable $fn, string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException(($message === '' ? '' : $message . ': ')
            . "expected {$class}, got " . get_class($e) . ' — ' . $e->getMessage());
    }

    throw new RuntimeException(($message === '' ? '' : $message . ': ') . "expected {$class}, nothing thrown");
}

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    require $file;
}

exit(TestRunner::run());
