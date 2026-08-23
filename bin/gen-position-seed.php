<?php

declare(strict_types=1);

/**
 * Generate the position matrix seed from the specification.
 *
 *   php bin/gen-position-seed.php              write the SQL to stdout
 *   php bin/gen-position-seed.php --out=FILE   write it to a file
 *   php bin/gen-position-seed.php --check      compare against the committed
 *                                              seed and exit non-zero on drift
 *
 * The 98 positions and their flags are transcribed from the table in
 * docs/spec-v2.md section 8.3, which is authoritative. Hand-typing 157
 * position-phase rows would introduce errors nobody would notice until an
 * officer found a position missing from a board at 17:00, so the seed is
 * derived from the spec instead and the counts are asserted on the way out.
 *
 * Two of the deliverables Rodeo Express still owes will change this table —
 * the critical position review (open item 4) and the skill mapping question
 * (open item 2). When the spec is amended, regenerate and commit the result as
 * a NEW migration: an applied migration is immutable, and after go-live the
 * Position Matrix Editor (spec 6.10.8) is the way to change the live matrix.
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
    'critical'  => 23,
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

$target = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $target = substr($arg, 6);
    }
}

if (in_array('--check', array_slice($argv, 1), true)) {
    $committed = $root . '/db/migrations/002_seed_reference.sql';
    if (!is_file($committed)) {
        fail("no committed seed at {$committed}");
    }
    if (rtrim((string) file_get_contents($committed)) !== rtrim($sql)) {
        fail('the committed seed no longer matches docs/spec-v2.md section 8.3');
    }
    echo "Seed matches the specification.\n";
    exit(0);
}

if ($target !== null) {
    file_put_contents($target, $sql . "\n");
    fwrite(STDERR, sprintf("Wrote %s (%d positions, %d phase records).\n", $target, $actual['positions'], $actual['phases']));
    exit(0);
}

echo $sql, "\n";
