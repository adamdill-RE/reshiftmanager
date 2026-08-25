<?php

declare(strict_types=1);

/**
 * Generate the position matrix seed from the specification.
 *
 *   php bin/gen-position-seed.php              write the full seed to stdout
 *   php bin/gen-position-seed.php --out=FILE   write the full seed to a file
 *   php bin/gen-position-seed.php --criticals   write migration 004 to stdout
 *   php bin/gen-position-seed.php --check      compare migration 004 against
 *                                              the spec, non-zero on drift
 *
 * The 98 positions and their flags are transcribed from the table in
 * docs/spec-v2.md section 8.3, which is authoritative. Hand-typing 157
 * position-phase rows would introduce errors nobody would notice until an
 * officer found a position missing from a board at 17:00, so the seed is
 * derived from the spec instead and the counts are asserted on the way out.
 *
 * An applied migration is immutable, so an amended spec becomes a NEW
 * migration rather than an edit to 002. That has happened once already: open
 * item 4 came back and moved criticality from 23 positions to 39, which is
 * what --criticals emits and 004 carries.
 *
 * --check therefore guards 004, the artefact this script still owns. 002 is
 * frozen history, and the whole applied matrix is checked against this same
 * spec table by tests/position_matrix_test.php, which reads the database
 * rather than the SQL and so covers every migration at once.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$specFile = $root . '/docs/spec-v2.md';

// Group ordering follows the summary table in section 8.1: the five groups
// present in both phases first, then Unload, then the four Bump-and-Run-only
// groups. That is also the order officers read the board in.
const GROUPS = [
    'General'              => ['code' => 'general',              'sort' => 1],
    'Naomi Crosswalk'      => ['code' => 'naomi_crosswalk',      'sort' => 2],
    'Holly Hall Crosswalk' => ['code' => 'holly_hall_crosswalk', 'sort' => 3],
    'Reed Road'            => ['code' => 'reed_road',            'sort' => 4],
    'Gold Badge / LT'      => ['code' => 'gold_badge_lt',        'sort' => 5],
    'Unload'               => ['code' => 'unload',               'sort' => 6],
    'OST'                  => ['code' => 'ost',                  'sort' => 7],
    'West Loop'            => ['code' => 'west_loop',            'sort' => 8],
    'Monroe'               => ['code' => 'monroe',               'sort' => 9],
    'Maxey'                => ['code' => 'maxey',                'sort' => 10],
];

// Section 7. Order is the chip row on the assign board (spec 6.9.4), which
// leads with the certifications an officer actually filters by.
const SKILLS = [
    ['radio',            'Radio'],
    ['starter',          'Starter'],
    ['computer',         'Computer'],
    ['counter',          'Counter'],
    ['runner',           'Runner'],
    ['crosswalk_middle', 'Crosswalk Middle'],
    ['forklift',         'Forklift'],
    ['golfcart',         'Golf Cart'],
];

/** Counts stated in section 8: they are the check on the transcription. */
const EXPECTED = [
    'positions' => 98,
    'phases'    => 157,
    'unload'    => 62,
    'bump_run'  => 95,
    'radio'     => 22,
    'critical'  => 39,
    'multi'     => 3,
];

function fail(string $message): never
{
    fwrite(STDERR, "gen-position-seed: {$message}\n");
    exit(1);
}

function quote(string $value): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
}

if (!is_file($specFile)) {
    fail("cannot read {$specFile}");
}

$lines = file($specFile, FILE_IGNORE_NEW_LINES) ?: [];

/** @var array<int, array<string, mixed>> $positions */
$positions = [];
$seen = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (!str_starts_with($line, '|')) {
        continue;
    }

    $cells = array_map('trim', explode('|', trim($line, '|')));
    if (count($cells) !== 7) {
        continue;
    }

    [$group, $label, $phases, $radio, $multi, $carry, $critical] = $cells;

    // Skip the header and its separator row.
    if (!isset(GROUPS[$group])) {
        continue;
    }
    if ($label === '' || $label === 'Position') {
        continue;
    }

    $inUnload = str_contains($phases, 'Unload');
    $inBumpRun = str_contains($phases, 'B&R');
    if (!$inUnload && !$inBumpRun) {
        fail("position '{$label}' names no phase: {$phases}");
    }

    $key = $group . '/' . $label;
    if (isset($seen[$key])) {
        fail("duplicate position in the spec: {$key}");
    }
    $seen[$key] = true;

    $yes = static fn (string $cell): bool => strtoupper($cell) === 'Y';

    $positions[] = [
        'group'    => $group,
        'label'    => $label,
        'unload'   => $inUnload,
        'bump_run' => $inBumpRun,
        'radio'    => $yes($radio),
        'multi'    => $yes($multi),
        'carry'    => $yes($carry),
        'critical' => $yes($critical),
    ];
}

// ---------------------------------------------------------------------------
// Check the transcription against the counts the spec states for itself.
// ---------------------------------------------------------------------------

$actual = [
    'positions' => count($positions),
    'phases'    => 0,
    'unload'    => 0,
    'bump_run'  => 0,
    'radio'     => 0,
    'critical'  => 0,
    'multi'     => 0,
];

foreach ($positions as $position) {
    $actual['unload']   += $position['unload'] ? 1 : 0;
    $actual['bump_run'] += $position['bump_run'] ? 1 : 0;
    $actual['radio']    += $position['radio'] ? 1 : 0;
    $actual['critical'] += $position['critical'] ? 1 : 0;
    $actual['multi']    += $position['multi'] ? 1 : 0;
}
$actual['phases'] = $actual['unload'] + $actual['bump_run'];

foreach (EXPECTED as $name => $expected) {
    if ($actual[$name] !== $expected) {
        fail("expected {$expected} {$name}, parsed {$actual[$name]} — has docs/spec-v2.md section 8 changed?");
    }
}

// ---------------------------------------------------------------------------
// Emit
// ---------------------------------------------------------------------------

$out = [];
$out[] = '-- Reference data: skills, position groups, positions, position-phase records.';
$out[] = '--';
$out[] = '-- GENERATED by bin/gen-position-seed.php from docs/spec-v2.md section 8.3.';
$out[] = '-- Do not hand-edit. Regenerate, or once the app is live use the Position';
$out[] = '-- Matrix Editor (spec 6.10.8), which edits these tables directly.';
$out[] = '--';
$out[] = sprintf(
    '-- %d positions in %d groups, %d position-phase records (%d Unload, %d Bump and Run).',
    $actual['positions'],
    count(GROUPS),
    $actual['phases'],
    $actual['unload'],
    $actual['bump_run']
);
$out[] = sprintf(
    '-- %d flagged radio, %d critical, %d multi-assign.',
    $actual['radio'],
    $actual['critical'],
    $actual['multi']
);
$out[] = '--';
$out[] = '-- Ids are explicit so every environment agrees on them, and so a future';
$out[] = '-- migration can reference a position without a lookup.';
$out[] = '--';
$out[] = '-- Data only, so this one really is atomic:';
$out[] = '-- resm:atomic';
$out[] = '';

$out[] = 'INSERT INTO skill (id, code, label, sort_order) VALUES';
$rows = [];
foreach (SKILLS as $index => [$code, $label]) {
    $rows[] = sprintf('    (%d, %s, %s, %d)', $index + 1, quote($code), quote($label), $index + 1);
}
$out[] = implode(",\n", $rows) . ';';
$out[] = '';

$out[] = 'INSERT INTO position_group (id, code, label, sort_order) VALUES';
$rows = [];
$groupIds = [];
foreach (GROUPS as $label => $meta) {
    $groupIds[$label] = $meta['sort'];
    $rows[] = sprintf('    (%d, %s, %s, %d)', $meta['sort'], quote($meta['code']), quote($label), $meta['sort']);
}
$out[] = implode(",\n", $rows) . ';';
$out[] = '';

$out[] = 'INSERT INTO position (id, group_id, label, is_radio, sort_order) VALUES';
$rows = [];
$phaseRows = [];
$positionId = 0;
$sortWithinGroup = [];

foreach ($positions as $position) {
    $positionId++;
    $groupId = $groupIds[$position['group']];
    $sortWithinGroup[$groupId] = ($sortWithinGroup[$groupId] ?? 0) + 1;

    $rows[] = sprintf(
        '    (%3d, %2d, %-34s %d, %2d)',
        $positionId,
        $groupId,
        quote($position['label']) . ',',
        $position['radio'] ? 1 : 0,
        $sortWithinGroup[$groupId]
    );

    foreach (['unload', 'bump_run'] as $phase) {
        if (!$position[$phase]) {
            continue;
        }
        $phaseRows[] = sprintf(
            '    (%3d, %-10s %d, %d, %d)',
            $positionId,
            quote($phase) . ',',
            // Multi-assign applies to the three Unload group positions, which
            // exist only in the Unload phase.
            $position['multi'] && $phase === 'unload' ? 1 : 0,
            $position['carry'] ? 1 : 0,
            // The spec gives one Critical column covering both phases. Open
            // item 4 asks whether criticality should differ between them; until
            // that comes back, both phases carry the same flag.
            $position['critical'] ? 1 : 0
        );
    }
}

$out[] = implode(",\n", $rows) . ';';
$out[] = '';
$out[] = 'INSERT INTO position_phase (position_id, phase, multi_assign, carry_forward, is_critical) VALUES';
$out[] = implode(",\n", $phaseRows) . ';';
$out[] = '';

$sql = implode("\n", $out);

// ---------------------------------------------------------------------------
// Migration 004: criticality only
//
// Written declaratively — clear every flag, then set the ones the spec names —
// so the result is the spec's set whatever the table held before. That is the
// right shape for a one-time correction and the wrong shape afterwards: once
// the Position Matrix Editor ships (spec 6.10.8) an administrator's own edits
// live in these columns, and a second migration of this form would erase them.
// ---------------------------------------------------------------------------

$criticalRows = [];
foreach ($positions as $position) {
    if ($position['critical']) {
        $criticalRows[] = sprintf(
            '    (%s, %s)',
            quote(GROUPS[$position['group']]['code']),
            quote($position['label'])
        );
    }
}

$criticalUnload = 0;
$criticalBumpRun = 0;
foreach ($positions as $position) {
    if (!$position['critical']) {
        continue;
    }
    $criticalUnload += $position['unload'] ? 1 : 0;
    $criticalBumpRun += $position['bump_run'] ? 1 : 0;
}

$m = [];
$m[] = '-- Criticality, as confirmed by Rodeo Express (spec open item 4).';
$m[] = '--';
$m[] = '-- GENERATED by bin/gen-position-seed.php --criticals from the Critical';
$m[] = '-- column of docs/spec-v2.md section 8.3. Do not hand-edit.';
$m[] = '--';
$m[] = sprintf(
    '-- %d of %d positions are critical: %d in Unload, %d in Bump and Run.',
    $actual['critical'],
    $actual['positions'],
    $criticalUnload,
    $criticalBumpRun
);
$m[] = '--';
$m[] = '-- Migration 002 seeded 23, drawn from the proposed set in the spec. The';
$m[] = '-- confirmed set is larger and differently shaped: one Starter, one';
$m[] = '-- Computer, one Counter and one Runner per lane or stop, rather than just';
$m[] = '-- the Starter and Computer. Two General Starters came off the list, because';
$m[] = '-- an adjacent worker covers Woodlands and Special Events when people are';
$m[] = '-- short, and Reed Road now carries one Computer and one Counter for the';
$m[] = '-- group rather than one per gate.';
$m[] = '--';
$m[] = '-- 37 critical positions against a shift that can run 25 people is not an';
$m[] = '-- error. It is the floor: at 37 everything runs, barely, and everyone gets';
$m[] = '-- home. The board is expected to show red on a short night.';
$m[] = '--';
$m[] = '-- Data only, so this one really is atomic:';
$m[] = '-- resm:atomic';
$m[] = '';
$m[] = '-- Criticality does not vary by phase (spec 8.1), so both phase rows of a';
$m[] = '-- position always agree and neither statement below names a phase.';
$m[] = 'UPDATE position_phase SET is_critical = 0;';
$m[] = '';
$m[] = 'UPDATE position_phase pp';
$m[] = '    JOIN position p       ON p.id = pp.position_id';
$m[] = '    JOIN position_group g ON g.id = p.group_id';
$m[] = 'SET pp.is_critical = 1';
$m[] = 'WHERE (g.code, p.label) IN (';
$m[] = implode(",\n", $criticalRows);
$m[] = ');';
$m[] = '';

$criticalsSql = implode("\n", $m);

$target = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $target = substr($arg, 6);
    }
}

$wantCriticals = in_array('--criticals', array_slice($argv, 1), true);

if (in_array('--check', array_slice($argv, 1), true)) {
    $committed = $root . '/db/migrations/004_critical_positions.sql';
    if (!is_file($committed)) {
        fail("no committed migration at {$committed}");
    }
    if (rtrim((string) file_get_contents($committed)) !== rtrim($criticalsSql)) {
        fail('004_critical_positions.sql no longer matches docs/spec-v2.md section 8.3');
    }
    echo sprintf("Criticality matches the specification (%d positions).\n", $actual['critical']);
    exit(0);
}

$body = $wantCriticals ? $criticalsSql : $sql;

if ($target !== null) {
    file_put_contents($target, $body . "\n");
    fwrite(STDERR, sprintf("Wrote %s (%d positions, %d phase records).\n", $target, $actual['positions'], $actual['phases']));
    exit(0);
}

echo $body, "\n";
