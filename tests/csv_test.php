<?php

declare(strict_types=1);

use Resm\Csv;

/**
 * The parser has to survive whatever Excel writes (spec 6.10.3). Every case
 * here was measured against fgetcsv before the class was written.
 */

/** @return array<int, array<int, string>> */
function cells(string $content): array
{
    return array_map(static fn (array $r): array => $r['cells'], Csv::rows($content));
}

test('a quoted field may contain a comma', function (): void {
    assertSame([['Smith, Jr.', 'Al', '1001']], cells("\"Smith, Jr.\",Al,1001\n"));
});

test('a UTF-8 byte order mark is stripped', function (): void {
    // Excel writes one, and fgetcsv does not remove it — left alone it arrives
    // glued to the first cell and the header matches nothing.
    $rows = cells("\xEF\xBB\xBFLastname,Firstname,Member_ID\n");
    assertSame('Lastname', $rows[0][0], 'the BOM is still attached');
});

test('CRLF and LF both work, and a newline inside a quoted field survives', function (): void {
    assertSame([['Smith', 'Al'], ['Jones', 'Bo']], cells("Smith,Al\r\nJones,Bo\r\n"));
    assertSame([['Smith', 'Al'], ['Jones', 'Bo']], cells("Smith,Al\nJones,Bo\n"));

    // Which is why line endings are never normalised globally first.
    assertSame([["Smith\nJr", 'Al']], cells("\"Smith\nJr\",Al\n"));
});

test('a doubled quote is one literal quote', function (): void {
    assertSame([['He said "hi"', 'Al']], cells("\"He said \"\"hi\"\"\",Al\n"));
});

test('a backslash is data, not an escape', function (): void {
    // fgetcsv defaults the escape character to a backslash, which is not part
    // of CSV and silently eats one. The parser passes an empty escape.
    assertSame([['C:\\path\\', 'Al']], cells("\"C:\\path\\\",Al\n"));
});

test('blank lines are not rows, and line numbers still count them', function (): void {
    $rows = Csv::rows("Smith,Al\n\nJones,Bo\n");

    assertCount(2, $rows);
    assertSame(1, $rows[0]['line']);
    assertSame(3, $rows[1]['line'], 'the error report has to name the real line');
});

test('every cell is trimmed', function (): void {
    assertSame([['Smith', 'Al', '1001']], cells("  Smith , Al ,\t1001  \n"));
});

test('a short row is kept as it is', function (): void {
    // Padding it would hide a missing column; the importer reports it instead.
    assertSame([['Smith', 'Al']], cells("Smith,Al\n"));
});

test('empty input yields nothing', function (): void {
    assertSame([], Csv::rows(''));
    assertSame([], Csv::rows("\n\n"));
});

test('writing quotes only what needs it', function (): void {
    assertSame("a,b,c\r\n", Csv::line(['a', 'b', 'c']));
    assertSame("\"Smith, Jr.\",Al\r\n", Csv::line(['Smith, Jr.', 'Al']));
    assertSame("\"He said \"\"hi\"\"\"\r\n", Csv::line(['He said "hi"']));
    assertSame("7,x\r\n", Csv::line([7, 'x']), 'ints are written plainly');
});

test('what is written can be read back', function (): void {
    $nasty = ['Smith, Jr.', 'He said "hi"', "two\nlines", 'plain'];
    $round = cells(Csv::line($nasty));

    assertSame([$nasty], $round);
});
