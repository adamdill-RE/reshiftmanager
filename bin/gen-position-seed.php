<?php

declare(strict_types=1);

/**
 * Generate the position matrix seed from the specification.
 *
 *   php bin/gen-position-seed.php              write the full seed to stdout
 *   php bin/gen-position-seed.php --out=FILE   write the full seed to a file
 *   php bin/gen-position-seed.php --criticals   write migration 004 to stdout
 *   php bin/gen-position-seed.php --skills     write migration 005 to stdout
 *   php bin/gen-position-seed.php --check      compare migrations 004 and 005
 *                                              against the spec, non-zero on drift
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

// Section 7.1. Order is the chip row on the assign board (spec 6.9.4): the
// eight position skills first, in the order an officer reaches for them, then
// the two equipment certifications, which are roster information and are not
// on that row at all.
// Ids are historical and sort order is presentation, so the two diverge here.
// Migration 002 handed out 1-8, and Forklift and Golf Cart got 7 and 8 before
// anyone knew they were a different kind of thing; renumbering them now would
// rewrite rows that user_skill already points at. So they keep their ids and
// simply move to the end of the chip order, and the two new skills take 9 and
// 10 while sorting into the middle.
const SKILLS = [
    ['id' => 1,  'code' => 'radio',               'label' => 'Radio',               'kind' => 'position',  'sort' => 1],
    ['id' => 2,  'code' => 'starter',             'label' => 'Starter',             'kind' => 'position',  'sort' => 2],
    ['id' => 3,  'code' => 'computer',            'label' => 'Computer',            'kind' => 'position',  'sort' => 3],
    ['id' => 4,  'code' => 'counter',             'label' => 'Counter',             'kind' => 'position',  'sort' => 4],
    ['id' => 5,  'code' => 'runner',              'label' => 'Runner',              'kind' => 'position',  'sort' => 5],
    ['id' => 6,  'code' => 'crosswalk_middle',    'label' => 'Crosswalk Middle',    'kind' => 'position',  'sort' => 6],
    ['id' => 9,  'code' => 'crosswalk_perimeter', 'label' => 'Crosswalk Perimeter', 'kind' => 'position',  'sort' => 7],
    ['id' => 10, 'code' => 'gate',                'label' => 'Gate',                'kind' => 'position',  'sort' => 8],
    ['id' => 7,  'code' => 'forklift',            'label' => 'Forklift',            'kind' => 'equipment', 'sort' => 9],
    ['id' => 8,  'code' => 'golfcart',            'label' => 'Golf Cart',           'kind' => 'equipment', 'sort' => 10],
];

/** The eight ids migration 002 already handed out. */
const SEEDED_SKILL_IDS = 8;

/**
 * Which job skill a position calls for (spec 7.2).
 *
 * A rule, not 98 decisions. The order of the tests is the load-bearing part:
 * Naomi's centre position is called "Center Starter", so checking for Starter
 * before the crosswalk groups files the crosswalk centre under Starter and
 * nobody notices.
 */
function skillForPosition(string $groupCode, string $label): ?string
{
    if ($groupCode === 'naomi_crosswalk' || $groupCode === 'holly_hall_crosswalk') {
        if (str_contains($label, 'Center')) {
            return 'crosswalk_middle';
        }
        // Bridge work is its own thing, not perimeter work.
        return str_contains($label, 'Bridge') ? null : 'crosswalk_perimeter';
    }

    // Every gate: the Main Committee Gate and all ten Back Gates alike.
    if (str_contains($label, 'Gate')) {
        return 'gate';
    }

    foreach (['Computer' => 'computer', 'Counter' => 'counter',
              'Runner' => 'runner', 'Starter' => 'starter'] as $needle => $skill) {
        if (str_contains($label, $needle)) {
            return $skill;
        }
    }

    return null;
}

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
$seeded = array_values(array_filter(SKILLS, static fn (array $s): bool => $s['id'] <= SEEDED_SKILL_IDS));
usort($seeded, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
foreach ($seeded as $skill) {
    // Migration 002 as it was written: eight skills, no kind, sort order equal
    // to id. 005 adds the column, the last two skills and the new sort order,
    // so a fresh database and an upgraded one converge on the same rows.
    $rows[] = sprintf('    (%d, %s, %s, %d)', $skill['id'], quote($skill['code']), quote($skill['label']), $skill['id']);
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

// ---------------------------------------------------------------------------
// Migration 005: the skills model (spec 7)
// ---------------------------------------------------------------------------

$skillRows = [];
$mappedPositions = 0;
$perSkill = [];
$positionId = 0;

foreach ($positions as $position) {
    $positionId++;
    $skill = skillForPosition(GROUPS[$position['group']]['code'], $position['label']);
    if ($skill === null) {
        continue;
    }
    $mappedPositions++;
    $perSkill[$skill] = ($perSkill[$skill] ?? 0) + 1;
    $skillRows[$skill][] = $positionId;
}

$k = [];
$k[] = '-- Skills: ten of them, in two kinds, and what each position calls for.';
$k[] = '--';
$k[] = '-- GENERATED by bin/gen-position-seed.php --skills from docs/spec-v2.md';
$k[] = '-- section 7. Do not hand-edit.';
$k[] = '--';
$k[] = sprintf('-- %d skills. %d of %d positions map to one; the rest are their own jobs', count(SKILLS), $mappedPositions, $actual['positions']);
$k[] = '-- with nothing shared to certify (7.2).';
$k[] = '--';
$k[] = '-- Nothing here restricts an assignment. Certification is not a permission and';
$k[] = '-- preference is not a claim: both are shown beside a name so the officer can';
$k[] = '-- decide, and who stands where is settled on the ground (7.4).';
$k[] = '--';
$k[] = '-- resm:atomic';
$k[] = '';
$k[] = '-- Position skills go on the assign board chip row; equipment certifications';
$k[] = '-- are roster information and stay off it (7.1).';
$k[] = "ALTER TABLE skill ADD COLUMN kind ENUM('position','equipment') NOT NULL DEFAULT 'position' AFTER label;";
$k[] = '';

$k[] = '-- The two new position skills (7.1).';
foreach (SKILLS as $skill) {
    if ($skill['id'] <= SEEDED_SKILL_IDS) {
        continue;
    }
    $k[] = sprintf(
        'INSERT INTO skill (id, code, label, kind, sort_order) VALUES (%d, %s, %s, %s, %d);',
        $skill['id'],
        quote($skill['code']),
        quote($skill['label']),
        quote($skill['kind']),
        $skill['sort']
    );
}
$k[] = '';
$k[] = '-- Everything already seeded gets its kind, and the two equipment';
$k[] = '-- certifications move to the end of the chip order without changing id.';
foreach (SKILLS as $skill) {
    if ($skill['id'] > SEEDED_SKILL_IDS) {
        continue;
    }
    $k[] = sprintf(
        'UPDATE skill SET kind = %s, sort_order = %d WHERE code = %s;',
        quote($skill['kind']),
        $skill['sort'],
        quote($skill['code'])
    );
}
$k[] = '';
$k[] = '-- At most one job skill per position. Radio stays its own flag because it is';
$k[] = '-- orthogonal: Reed Starter 1 wants a Starter AND a radio.';
$k[] = 'ALTER TABLE position ADD COLUMN skill_id TINYINT UNSIGNED NULL AFTER is_radio,';
$k[] = '    ADD CONSTRAINT fk_position_skill FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE SET NULL;';
$k[] = '';

$skillIds = [];
foreach (SKILLS as $skill) {
    $skillIds[$skill['code']] = $skill['id'];
}
foreach ($skillRows as $skill => $ids) {
    sort($ids);
    $k[] = sprintf(
        '-- %s: %d positions',
        $skill,
        count($ids)
    );
    $k[] = sprintf(
        'UPDATE position SET skill_id = %d WHERE id IN (%s);',
        $skillIds[$skill],
        implode(', ', $ids)
    );
}
$k[] = '';
$k[] = '-- Certified and preferred are independent facts about the same pair (7.3).';
$k[] = '-- A man can be certified in something he would rather not do, and prefer';
$k[] = '-- something he is not yet certified for -- which is a training list nobody';
$k[] = '-- had to compile. Certified is granted_at being set, so it becomes nullable.';
$k[] = 'ALTER TABLE user_skill';
$k[] = '    MODIFY COLUMN granted_at DATETIME NULL DEFAULT NULL,';
$k[] = '    ADD COLUMN is_preferred TINYINT(1) NOT NULL DEFAULT 0 AFTER skill_id,';
$k[] = '    ADD COLUMN preferred_at DATETIME NULL DEFAULT NULL AFTER is_preferred;';
$k[] = '';

$skillsSql = implode("\n", $k);

$target = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $target = substr($arg, 6);
    }
}

$wantCriticals = in_array('--criticals', array_slice($argv, 1), true);
$wantSkills = in_array('--skills', array_slice($argv, 1), true);

if (in_array('--check', array_slice($argv, 1), true)) {
    $committed = $root . '/db/migrations/004_critical_positions.sql';
    if (!is_file($committed)) {
        fail("no committed migration at {$committed}");
    }
    if (rtrim((string) file_get_contents($committed)) !== rtrim($criticalsSql)) {
        fail('004_critical_positions.sql no longer matches docs/spec-v2.md section 8.3');
    }

    $skillsFile = $root . '/db/migrations/005_skills.sql';
    if (!is_file($skillsFile)) {
        fail("no committed migration at {$skillsFile}");
    }
    if (rtrim((string) file_get_contents($skillsFile)) !== rtrim($skillsSql)) {
        fail('005_skills.sql no longer matches docs/spec-v2.md section 7');
    }

    echo sprintf(
        "Criticality matches the specification (%d positions).\n"
        . "Skills match the specification (%d skills, %d of %d positions mapped).\n",
        $actual['critical'],
        count(SKILLS),
        $mappedPositions,
        $actual['positions']
    );
    exit(0);
}

$body = match (true) {
    $wantCriticals => $criticalsSql,
    $wantSkills => $skillsSql,
    default => $sql,
};

if ($target !== null) {
    file_put_contents($target, $body . "\n");
    fwrite(STDERR, sprintf("Wrote %s (%d positions, %d phase records).\n", $target, $actual['positions'], $actual['phases']));
    exit(0);
}

echo $body, "\n";
