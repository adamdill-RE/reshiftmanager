<?php

declare(strict_types=1);

namespace Resm;

/**
 * Reading and writing the CSV the roster arrives as (spec 6.10.3).
 *
 * Thin, but not trivial. The file comes out of Excel on somebody's laptop, and
 * four things about that were measured rather than assumed:
 *
 *   fgetcsv does NOT strip a UTF-8 byte order mark. Excel writes one, and left
 *   alone it arrives glued to the first cell — so a header row reads
 *   "\u{FEFF}Lastname" and matches nothing.
 *
 *   fgetcsv handles CRLF natively, so line endings must NOT be normalised
 *   beforehand. A quoted field may legitimately contain a newline, and a
 *   global replace would rewrite the data inside it.
 *
 *   The escape parameter must be passed as an empty string. Its default is a
 *   backslash, which is not part of the CSV format and silently eats one from
 *   any field that contains it.
 *
 *   A blank line comes back as [null] rather than an empty array, which is not
 *   a row and must not be counted as one.
 */
final class Csv
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Rows of trimmed strings. Blank lines are dropped; short rows are kept as
     * they are, because a row missing a column is a validation error the
     * importer reports by line number, not something to silently pad.
     *
     * @return array<int, array{line: int, cells: array<int, string>}>
     */
    public static function rows(string $content): array
    {
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return [];
        }

        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            // A blank line. Not a row.
            if ($cells === [null] || $cells === []) {
                continue;
            }

            $rows[] = [
                'line' => $line,
                'cells' => array_map(
                    static fn (mixed $cell): string => trim((string) $cell),
                    $cells
                ),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * One CSV line, for the error report the Admin downloads.
     *
     * @param array<int, string|int> $fields
     */
    public static function line(array $fields): string
    {
        $out = [];

        foreach ($fields as $field) {
            $value = (string) $field;
            // Quote anything that would otherwise change the shape of the row,
            // and double an embedded quote, which is how CSV escapes one.
            if (preg_match('/["\r\n,]/', $value) === 1) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            $out[] = $value;
        }

        // CRLF: Excel is the program that opens this, and it is what Excel
        // writes.
        return implode(',', $out) . "\r\n";
    }
}
