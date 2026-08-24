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
