<?php

declare(strict_types=1);

namespace Resm;

/**
 * Splits a .sql file into individual statements.
 *
 * PDO can be told to execute several statements in one call, but then a
 * failure halfway through reports against the whole blob and says nothing
 * about which statement failed. Splitting first means a migration error names
 * the statement, which is the difference between a two-minute fix and an
 * afternoon.
 *
 * The scanner tracks quoting so a semicolon inside a string or a backtick
 * identifier does not end a statement, and it drops comments — except MySQL
 * executable comments (slash-star-bang), which are syntax, not commentary.
 */
final class SqlScript
{
    /** @return array<int, string> */
    public static function split(string $sql): array
    {
        // Excel and some editors prepend a BOM; it is not valid SQL.
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;

        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // Line comments: "-- " (a double dash must be followed by
            // whitespace in MySQL) and "#".
            if (($char === '-' && $next === '-' && self::isSpace($sql[$i + 2] ?? "\n"))
                || $char === '#'
            ) {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                $current .= "\n";
                continue;
            }

            // Block comments. Keep executable ones.
            if ($char === '/' && $next === '*') {
                $executable = ($sql[$i + 2] ?? '') === '!';
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                if ($executable) {
                    $current .= substr($sql, $i, $end - $i);
                } else {
                    $current .= ' ';
                }
                $i = $end;
                continue;
            }

            // Quoted strings and quoted identifiers.
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = self::consumeQuoted($sql, $i, $char, $current);
                continue;
            }

            if ($char === ';') {
                $statements[] = $current;
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $statements[] = $current;

        return array_values(array_filter(
            array_map(static fn (string $s): string => trim($s), $statements),
            static fn (string $s): bool => $s !== ''
        ));
    }

    /**
     * Copy a quoted run into $current and return the index just past it.
     * Handles backslash escapes and the doubled-quote form ('' and ``).
     */
    private static function consumeQuoted(string $sql, int $i, string $quote, string &$current): int
    {
        $length = strlen($sql);
        $current .= $quote;
        $i++;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                $current .= $char . $sql[$i + 1];
                $i += 2;
                continue;
            }

            if ($char === $quote) {
                // A doubled quote is a literal quote, not the end.
                if (($sql[$i + 1] ?? '') === $quote) {
                    $current .= $quote . $quote;
                    $i += 2;
                    continue;
                }
                $current .= $quote;

                return $i + 1;
            }

            $current .= $char;
            $i++;
        }

        return $i;
    }

    private static function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }
}
