<?php

declare(strict_types=1);

use Resm\Database;

/**
 * The applied position matrix against docs/spec-v2.md section 8.3.
 *
 * This reads the database, not the SQL, which is what makes it the real guard:
 * it sees the cumulative result of every migration at once, so a correction
 * shipped as 004 is checked the same way the original seed in 002 is. Text
 * diffing a committed migration cannot do that — an applied migration is
 * immutable, so the file that seeded a value is not the file that decides it.
 *
 * The spec is authoritative (CLAUDE.md). If these disagree, the database is
 * wrong.
 */

/**
 * Parse the section 8.3 table the same way bin/gen-position-seed.php does.
 *
 * @return array<string, array{unload: bool, bump_run: bool, radio: bool, multi: bool, carry: bool, critical: bool}>
 *         keyed "group code|label"
 */
function specMatrix(): array
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }

    // Section 8.1's group ordering, which is also the code for each group.
    $codes = [
        'General' => 'general',
        'Naomi Crosswalk' => 'naomi_crosswalk',
        'Holly Hall Crosswalk' => 'holly_hall_crosswalk',
        'Reed Road' => 'reed_road',
        'Gold Badge / LT' => 'gold_badge_lt',
        'Unload' => 'unload',
        'OST' => 'ost',
        'West Loop' => 'west_loop',
        'Monroe' => 'monroe',
        'Maxey' => 'maxey',
    ];

    $rows = [];
    $yes = static fn (string $cell): bool => strtoupper(trim($cell)) === 'Y';

    foreach (file(dirname(__DIR__) . '/docs/spec-v2.md', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if (!str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map('trim', explode('|', trim($line, '|')));
        if (count($cells) !== 7) {
            continue;
        }

        [$group, $label, $phases, $radio, $multi, $carry, $critical] = $cells;
        if (!isset($codes[$group]) || $label === '' || $label === 'Position') {
            continue;
        }

        $rows[$codes[$group] . '|' . $label] = [
            'unload'   => str_contains($phases, 'Unload'),
            'bump_run' => str_contains($phases, 'B&R'),
            'radio'    => $yes($radio),
            'multi'    => $yes($multi),
            'carry'    => $yes($carry),
            'critical' => $yes($critical),
        ];
    }

    $parsed = $rows;

    return $parsed;
}

test('the spec table still parses to 98 positions', function (): void {
    // If this fails, every assertion below is measuring nothing.
    assertCount(98, specMatrix(), 'section 8.3 did not parse — has the table shape changed?');
});

test('every position in the spec exists in the database, and nothing else does', function (): void {
    $rows = testDb()->all(
        'SELECT g.code, p.label
         FROM position p JOIN position_group g ON g.id = p.group_id'
    );

    $inDb = [];
    foreach ($rows as $row) {
        $inDb[] = $row['code'] . '|' . $row['label'];
    }
    $inSpec = array_keys(specMatrix());

    sort($inDb);
    sort($inSpec);

    assertSame($inSpec, $inDb, 'the position list has drifted from section 8.3');
});

test('every per-phase flag matches the spec', function (): void {
    $rows = testDb()->all(
        'SELECT g.code, p.label, p.is_radio, pp.phase, pp.multi_assign, pp.carry_forward, pp.is_critical
         FROM position_phase pp
         JOIN position p       ON p.id = pp.position_id
         JOIN position_group g ON g.id = p.group_id'
    );

    $spec = specMatrix();
    $wrong = [];
    $seen = [];

    foreach ($rows as $row) {
        $key = $row['code'] . '|' . $row['label'];
        $want = $spec[$key] ?? null;
        if ($want === null) {
            $wrong[] = "{$key} is in the database but not the spec";
            continue;
        }

        $phase = (string) $row['phase'];
        $seen[$key][$phase] = true;

        if (!$want[$phase]) {
            $wrong[] = "{$key} has a {$phase} row the spec does not give it";
        }
        if ((int) $row['is_radio'] !== ($want['radio'] ? 1 : 0)) {
            $wrong[] = "{$key} radio";
        }
        if ((int) $row['is_critical'] !== ($want['critical'] ? 1 : 0)) {
            $wrong[] = "{$key} critical ({$phase})";
        }
        if ((int) $row['carry_forward'] !== ($want['carry'] ? 1 : 0)) {
            $wrong[] = "{$key} carry_forward ({$phase})";
        }
        // Multi-assign applies only in Unload — the three Unload group
        // positions are the only ones that carry it, and that group has no
        // Bump and Run rows at all.
        $wantMulti = $want['multi'] && $phase === 'unload';
        if ((int) $row['multi_assign'] !== ($wantMulti ? 1 : 0)) {
            $wrong[] = "{$key} multi_assign ({$phase})";
        }
    }

    foreach ($spec as $key => $want) {
        foreach (['unload', 'bump_run'] as $phase) {
            if ($want[$phase] && !isset($seen[$key][$phase])) {
                $wrong[] = "{$key} is missing its {$phase} row";
            }
        }
    }

    assertSame([], $wrong, "matrix drift:\n" . implode("\n", array_unique($wrong)));
});

/**
 * The counts the specification states about itself, checked against the
 * database rather than against the spec's own table — so a miscount in either
 * one shows up here.
 */
test('the matrix totals match section 8', function (): void {
    $db = testDb();

    assertSame(10, (int) $db->value('SELECT COUNT(*) FROM position_group'));
    assertSame(98, (int) $db->value('SELECT COUNT(*) FROM position'));
    assertSame(157, (int) $db->value('SELECT COUNT(*) FROM position_phase'));
    assertSame(62, (int) $db->value("SELECT COUNT(*) FROM position_phase WHERE phase = 'unload'"));
    assertSame(95, (int) $db->value("SELECT COUNT(*) FROM position_phase WHERE phase = 'bump_run'"));
    assertSame(22, (int) $db->value('SELECT COUNT(*) FROM position WHERE is_radio = 1'));
    assertSame(3, (int) $db->value('SELECT COUNT(*) FROM position_phase WHERE multi_assign = 1'));
});

/**
 * Criticality, confirmed by Rodeo Express and shipped in migration 004.
 *
 * 37 of the 95 Bump and Run positions against a shift that can run 25 people
 * is deliberate: it is the floor, not a target, and the board is expected to
 * read red on a short night (spec 5.4).
 */
test('39 positions are critical — 23 in Unload, 37 in Bump and Run', function (): void {
    $db = testDb();

    assertSame(39, (int) $db->value(
        'SELECT COUNT(DISTINCT position_id) FROM position_phase WHERE is_critical = 1'
    ));
    assertSame(23, (int) $db->value(
        "SELECT COUNT(*) FROM position_phase WHERE is_critical = 1 AND phase = 'unload'"
    ));
    assertSame(37, (int) $db->value(
        "SELECT COUNT(*) FROM position_phase WHERE is_critical = 1 AND phase = 'bump_run'"
    ));
});

test('criticality never differs between the two phases of a position', function (): void {
    // Spec 8.1: one list, applied to whichever phases a position exists in.
    // The schema allows per-phase criticality and a later Position Matrix
    // Editor may use it; the seeded data must not.
    $split = testDb()->all(
        'SELECT position_id FROM position_phase
         GROUP BY position_id HAVING COUNT(DISTINCT is_critical) > 1'
    );

    assertSame([], $split, 'these positions are critical in one phase but not the other');
});

test('every group holds at least one critical position', function (): void {
    // A group with no critical position contributes nothing to the coverage
    // figure, which would make it invisible on the board's one red number.
    $bare = testDb()->all(
        'SELECT g.code
         FROM position_group g
         LEFT JOIN position p       ON p.group_id = g.id
         LEFT JOIN position_phase pp ON pp.position_id = p.id AND pp.is_critical = 1
         GROUP BY g.id, g.code
         HAVING COUNT(pp.position_id) = 0'
    );

    assertSame([], $bare);
});
