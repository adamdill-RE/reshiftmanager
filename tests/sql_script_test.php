<?php

declare(strict_types=1);

use Resm\SqlScript;

test('splits plain statements', function (): void {
    $parts = SqlScript::split("SELECT 1;\nSELECT 2;\n");
    assertCount(2, $parts);
    assertSame('SELECT 1', $parts[0]);
    assertSame('SELECT 2', $parts[1]);
});

test('a trailing statement without a semicolon still counts', function (): void {
    assertCount(2, SqlScript::split('SELECT 1; SELECT 2'));
});

test('semicolon inside a string literal does not split', function (): void {
    $parts = SqlScript::split("INSERT INTO t (a) VALUES ('one; two');");
    assertCount(1, $parts);
    assertSame("INSERT INTO t (a) VALUES ('one; two')", $parts[0]);
});

test('doubled and escaped quotes are literal', function (): void {
    assertCount(1, SqlScript::split("INSERT INTO t VALUES ('O''Brien; Jr');"));
    assertCount(1, SqlScript::split("INSERT INTO t VALUES ('O\\'Brien; Jr');"));
});

test('semicolon inside a backtick identifier does not split', function (): void {
    assertCount(1, SqlScript::split('SELECT `odd;name` FROM t;'));
});

test('line comments are dropped, including semicolons inside them', function (): void {
    $parts = SqlScript::split("-- a comment; with a semicolon\nSELECT 1;\n# another; one\nSELECT 2;");
    assertCount(2, $parts);
    assertSame('SELECT 1', $parts[0]);
    assertSame('SELECT 2', $parts[1]);
});

test('a bare double dash is not a comment', function (): void {
    // MySQL requires whitespace after "--"; "a--b" is arithmetic.
    $parts = SqlScript::split('SELECT 1--2;');
    assertCount(1, $parts);
    assertSame('SELECT 1--2', $parts[0]);
});

test('block comments are dropped but executable comments are kept', function (): void {
    assertSame('SELECT 1', SqlScript::split('/* commentary; here */ SELECT 1;')[0]);
    assertTrue(str_contains(SqlScript::split('/*!40101 SET NAMES utf8mb4 */;')[0], '40101'));
});

test('a UTF-8 byte order mark is stripped', function (): void {
    assertSame('SELECT 1', SqlScript::split("\xEF\xBB\xBFSELECT 1;")[0]);
});

test('empty input and comment-only input yield no statements', function (): void {
    assertCount(0, SqlScript::split(''));
    assertCount(0, SqlScript::split("-- nothing here\n\n;\n"));
});

// ---------------------------------------------------------------------------
// Named placeholders in the application's own queries
// ---------------------------------------------------------------------------

/**
 * With ATTR_EMULATE_PREPARES off — which is how Database connects — PDO binds
 * each named parameter to exactly one marker in the statement. A name that
 * appears twice is not two comparisons; it is SQLSTATE[HY093], at runtime,
 * from whichever screen happens to run that query.
 *
 * That has cost this project a debugging session once already (an INSERT whose
 * issued_at and last_used_at both read :now). PHP's own tokeniser is what reads
 * the source here, so a placeholder inside a comment or an identifier is not
 * mistaken for one in the SQL.
 *
 * Only single string literals are checked. A statement assembled by
 * concatenation is not visible to this and is covered by the tests that run it.
 */
test('no SQL literal reuses a named placeholder', function (): void {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__) . '/app', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $sql = trim(substr($token[1], 1, -1));
            if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) !== 1) {
                continue;
            }

            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $found);
            $counts = array_count_values($found[1]);
            foreach ($counts as $name => $count) {
                if ($count > 1) {
                    $offenders[] = sprintf('%s:%d — :%s appears %d times', $file->getFilename(), $token[2], $name, $count);
                }
            }
        }
    }

    assertSame([], $offenders, "a repeated placeholder is HY093 at runtime:\n" . implode("\n", $offenders));
});
