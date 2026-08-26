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

// ---------------------------------------------------------------------------
// Skills (spec 7), added by migration 005
// ---------------------------------------------------------------------------

/**
 * The mapping rule from spec 7.2, transcribed a second time.
 *
 * Deliberately a separate copy from bin/gen-position-seed.php: the generator
 * writes the migration and this checks what the migration produced, so a
 * shared helper would let one mistake agree with itself.
 */
function expectedSkillFor(string $groupCode, string $label): ?string
{
    if ($groupCode === 'naomi_crosswalk' || $groupCode === 'holly_hall_crosswalk') {
        if (str_contains($label, 'Center')) {
            return 'crosswalk_middle';
        }

        return str_contains($label, 'Bridge') ? null : 'crosswalk_perimeter';
    }
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

test('ten skills, in two kinds, in chip-row order', function (): void {
    $rows = testDb()->all('SELECT code, kind, sort_order FROM skill ORDER BY sort_order');

    $expected = [
        ['radio', 'position'], ['starter', 'position'], ['computer', 'position'],
        ['counter', 'position'], ['runner', 'position'], ['crosswalk_middle', 'position'],
        ['crosswalk_perimeter', 'position'], ['gate', 'position'],
        ['forklift', 'equipment'], ['golfcart', 'equipment'],
    ];

    assertSame(
        $expected,
        array_map(static fn (array $r): array => [(string) $r['code'], (string) $r['kind']], $rows)
    );
});

test('the two equipment certifications kept the ids migration 002 gave them', function (): void {
    // Renumbering would rewrite rows user_skill already points at.
    $db = testDb();
    assertSame(7, (int) $db->value("SELECT id FROM skill WHERE code = 'forklift'"));
    assertSame(8, (int) $db->value("SELECT id FROM skill WHERE code = 'golfcart'"));
});

test('every position maps to the skill the rule gives it', function (): void {
    $rows = testDb()->all(
        'SELECT g.code AS group_code, p.label, s.code AS skill
         FROM position p
         JOIN position_group g ON g.id = p.group_id
         LEFT JOIN skill s ON s.id = p.skill_id'
    );

    $wrong = [];
    foreach ($rows as $row) {
        $want = expectedSkillFor((string) $row['group_code'], (string) $row['label']);
        $got = $row['skill'] === null ? null : (string) $row['skill'];
        if ($want !== $got) {
            $wrong[] = sprintf('%s: expected %s, got %s', $row['label'], $want ?? 'none', $got ?? 'none');
        }
    }

    assertSame([], $wrong, "skill mapping drift:\n" . implode("\n", $wrong));
});

test('the crosswalk centre is not filed under Starter', function (): void {
    // "Center Starter" contains Starter, so a rule that checked for it first
    // would put the crosswalk centre in the wrong place and nobody would see.
    assertSame('crosswalk_middle', (string) testDb()->value(
        "SELECT s.code FROM position p JOIN skill s ON s.id = p.skill_id
          WHERE p.label = 'Center Starter'"
    ));
});

test('Gate is every gate, and Bridge is none of them', function (): void {
    $db = testDb();

    assertSame(12, (int) $db->value(
        "SELECT COUNT(*) FROM position p JOIN skill s ON s.id = p.skill_id WHERE s.code = 'gate'"
    ), 'the Main Committee Gate and all ten Back Gates');

    assertSame(6, (int) $db->value(
        "SELECT COUNT(*) FROM position WHERE label LIKE 'Naomi Bridge%' AND skill_id IS NULL"
    ), 'bridge work is its own thing');
});

test('80 of 98 positions carry a skill', function (): void {
    $db = testDb();
    assertSame(80, (int) $db->value('SELECT COUNT(*) FROM position WHERE skill_id IS NOT NULL'));
    assertSame(18, (int) $db->value('SELECT COUNT(*) FROM position WHERE skill_id IS NULL'));

    // Radio is orthogonal — its own flag, not a skill_id (spec 7.2).
    assertSame(22, (int) $db->value('SELECT COUNT(*) FROM position WHERE is_radio = 1'));
    assertSame(0, (int) $db->value(
        "SELECT COUNT(*) FROM position p JOIN skill s ON s.id = p.skill_id WHERE s.code = 'radio'"
    ));
});

test('an equipment certification is never what a position asks for', function (): void {
    assertSame(0, (int) testDb()->value(
        "SELECT COUNT(*) FROM position p JOIN skill s ON s.id = p.skill_id WHERE s.kind = 'equipment'"
    ));
});

test('a preference can exist without a certification, and the reverse', function (): void {
    // Spec 7.3: two independent facts. Preferring something you are not yet
    // certified for is a training list nobody had to compile.
    inRollback(function (Resm\Database $db): void {
        $db->execute(
            "INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role)
             VALUES ('test-pref', 'Pref', 'Test', '!x', 'committeeman')"
        );
        $user = $db->lastInsertId();
        $runner = (int) $db->value("SELECT id FROM skill WHERE code = 'runner'");
        $gate = (int) $db->value("SELECT id FROM skill WHERE code = 'gate'");

        // Wants to be a runner, nobody has said he can be.
        $db->execute(
            'INSERT INTO user_skill (user_id, skill_id, granted_at, is_preferred, preferred_at)
             VALUES (:u, :s, NULL, 1, UTC_TIMESTAMP())',
            ['u' => $user, 's' => $runner]
        );
        // Certified on the gate, would rather not.
        $db->execute(
            'INSERT INTO user_skill (user_id, skill_id, granted_at, is_preferred)
             VALUES (:u, :s, UTC_TIMESTAMP(), 0)',
            ['u' => $user, 's' => $gate]
        );

        // Keyed by code rather than by row order, which is a detail of the
        // query and not of the thing being tested.
        $held = [];
        foreach ($db->all(
            'SELECT s.code, us.granted_at, us.is_preferred FROM user_skill us
             JOIN skill s ON s.id = us.skill_id WHERE us.user_id = :u',
            ['u' => $user]
        ) as $row) {
            $held[(string) $row['code']] = $row;
        }

        assertCount(2, $held);
        assertSame(null, $held['runner']['granted_at'], 'wants it, not yet certified');
        assertSame(1, (int) $held['runner']['is_preferred']);
        assertTrue($held['gate']['granted_at'] !== null, 'certified on the gate');
        assertSame(0, (int) $held['gate']['is_preferred'], 'and would rather not');
    });
});
