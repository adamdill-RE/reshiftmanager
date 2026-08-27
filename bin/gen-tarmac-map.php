<?php

declare(strict_types=1);

/**
 * Generate the tarmac map from the committee's own description of the ground.
 *
 *   php bin/gen-tarmac-map.php              write the SVG to stdout
 *   php bin/gen-tarmac-map.php --out=FILE   write the SVG to a file
 *   php bin/gen-tarmac-map.php --refs       write the map_ref UPDATEs (008)
 *   php bin/gen-tarmac-map.php --check      compare the committed SVG and
 *                                           migration 008 against this script,
 *                                           non-zero on drift
 *
 * The layout follows the committee's sketch of August 2026 and the
 * corrections read against it (the first written description had the
 * cardinal directions turned around; the RELATIVE layout was right all
 * along). North up:
 *
 *   - The tent is a long, narrow building, about 800ft by 150ft, its long
 *     axis running NORTH-SOUTH — which happens to suit a phone held upright.
 *   - The stops are sections along the tent's length, numbered from the
 *     Bus Ops complex at the SOUTH end going north: Reed nearest the
 *     office, then OST, Special Events & Woodlands, West Loop, Monroe,
 *     Maxey, and Gold Badge / LT at the north end. Reed and OST the
 *     largest. The lone bathroom sits against the tent's north end.
 *   - Guests come in on the WEST side, through the Overheads / Tent
 *     Entrance lines (drawn toward the north end of that side, where the
 *     sketch put them), line up in the OUTSIDE QUEUE areas along the west
 *     wall, and enter the tent through the back gates — which is what the
 *     back gates are: each stop's gate from the outside queues.
 *   - The seven bus lanes run along the EAST side. Starters work in the
 *     lanes even with their stop; the Bus Callers in the lanes at Special
 *     Events; computers, counters and runners just outside the tent's east
 *     wall, between the tent and the lanes, where boarding happens.
 *   - The Bus Ops office, log cabin, restrooms and chuck wagon sit against
 *     the tent's SOUTH end, with the committee gate beyond them — drawn
 *     touching the tent on purpose, as the landmarks people orient by.
 *   - Holly Hall's gate lies beyond the drawing to the NORTHEAST, Naomi's
 *     (with the walkover bridge) to the SOUTHEAST — both drawn as call-out
 *     boxes so the men who work them still see their own dot. Curve is
 *     drawn with the Holly Hall call-out; its exact spot is unconfirmed.
 *   - The Unload cluster is drawn at the lanes' north end; its exact spot
 *     is likewise unconfirmed. When the committee corrects either, rerun
 *     this script and recommit; the ids never change.
 *
 * THE CONTRACT (Resm\TarmacMap): every one of the 98 positions in spec 8.3
 * is exactly one element in the SVG whose id equals that position's map_ref.
 * The position list is parsed from the spec, the placements are asserted
 * against it both ways, and the ids are derived from the labels by one rule
 * — so the set cannot drift without this script refusing to emit.
 *
 * No script, no inline styles: the page's CSP is style-src/script-src
 * 'self', and TarmacMap::read refuses a drawing carrying <script> outright.
 * Everything is classed and coloured from app.css, which is also what makes
 * the drawing follow the dark theme for free.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$specFile = $root . '/docs/spec-v2.md';
$svgFile = $root . '/public/assets/map/tarmac.svg';
$migrationFile = $root . '/db/migrations/008_tarmac_and_definitions.sql';

function fail(string $message): never
{
    fwrite(STDERR, "gen-tarmac-map: {$message}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// The 98 positions, from the spec's own table (section 8.3).
// ---------------------------------------------------------------------------

const GROUPS = [
    'General', 'Naomi Crosswalk', 'Holly Hall Crosswalk', 'Reed Road',
    'Gold Badge / LT', 'Unload', 'OST', 'West Loop', 'Monroe', 'Maxey',
];

if (!is_file($specFile)) {
    fail("cannot read {$specFile}");
}

$labels = [];
foreach (file($specFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if (!str_starts_with($line, '|')) {
        continue;
    }
    $cells = array_map('trim', explode('|', trim($line, '|')));
    if (count($cells) !== 7 || !in_array($cells[0], GROUPS, true) || $cells[1] === '' || $cells[1] === 'Position') {
        continue;
    }
    $labels[] = $cells[1];
}

if (count($labels) !== 98) {
    fail('expected 98 positions in spec 8.3, found ' . count($labels));
}

/**
 * label -> map_ref, by one rule rather than 98 decisions, so the id for a
 * position can always be re-derived and never argued about.
 */
function mapRef(string $label): string
{
    $slug = strtolower($label);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $ref = 'pos-' . trim($slug, '-');

    if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,59}$/', $ref) !== 1) {
        fail("derived ref '{$ref}' is not addressable (TarmacMap::ref)");
    }

    return $ref;
}

$refs = [];
foreach ($labels as $label) {
    $ref = mapRef($label);
    if (isset($refs[$ref])) {
        fail("two positions derive the same ref: {$ref}");
    }
    $refs[$ref] = $label;
}

// ---------------------------------------------------------------------------
// Geometry. North is up, per the committee's sketch: the tent runs
// north-south, the lanes run along its east side. Drawn ~1.5px/ft across
// and ~1.9px/ft along the tent (schematic, not a survey).
// ---------------------------------------------------------------------------

const TENT = ['x0' => 170, 'y0' => 220, 'x1' => 395, 'y1' => 1740];
const LANES = ['x0' => 445, 'y0' => 200, 'x1' => 585, 'y1' => 1780, 'count' => 7];

// The outside queue areas along the west wall, where guests line up before
// the back gates let them into the tent.
const QUEUES = ['x0' => 80, 'y0' => 240, 'x1' => 150, 'y1' => 1720];

// The seven stops, numbered from the Bus Ops complex at the south end going
// north: Reed nearest the office, Gold Badge / LT at the top. Reed and OST
// the largest.
const BANDS = [
    'gblt'   => ['label' => '7 · Gold Badge / LT',            'y0' => 220,  'y1' => 410],
    'maxey'  => ['label' => '6 · Maxey',                      'y0' => 410,  'y1' => 591],
    'monroe' => ['label' => '5 · Monroe',                     'y0' => 591,  'y1' => 790],
    'wl'     => ['label' => '4 · West Loop',                  'y0' => 790,  'y1' => 990],
    'sew'    => ['label' => '3 · Special Events & Woodlands', 'y0' => 990,  'y1' => 1132],
    'ost'    => ['label' => '2 · OST',                        'y0' => 1132, 'y1' => 1417],
    'reed'   => ['label' => '1 · Reed / Employee',            'y0' => 1417, 'y1' => 1740],
];

// Starters sit in the lanes, even with their stop. The support crew sits on
// the east apron between the tent wall and the lanes, where boarding
// happens. The back gates sit just outside the WEST wall, each stop's gate
// in from the outside queues.
const STARTER_X = [478, 513];
const APRON_X = [408, 426];
const GATE_X = 158;

/** @var array<string, array{x: float, y: float}> */
$dots = [];

/** Place one position; refuses an unknown label or a double placement. */
function place(array &$dots, array $refs, string $label, float $x, float $y): void
{
    $ref = mapRef($label);
    if (!isset($refs[$ref])) {
        fail("placed a position the spec does not have: {$label}");
    }
    if (isset($dots[$ref])) {
        fail("placed twice: {$label}");
    }
    $dots[$ref] = ['x' => $x, 'y' => $y];
}

/** Mid-latitude of a band. */
function mid(string $band): float
{
    return (BANDS[$band]['y0'] + BANDS[$band]['y1']) / 2;
}

/** The east-apron grid for a band: two columns, rows every 18. */
function apron(array &$dots, array $refs, string $band, array $labels): void
{
    $y0 = BANDS[$band]['y0'] + 16;
    foreach ($labels as $i => $label) {
        place($dots, $refs, $label, APRON_X[$i % 2], $y0 + intdiv($i, 2) * 18);
    }
}

/** Back gates for a band: one west column, spread over the band. */
function gates(array &$dots, array $refs, string $band, array $labels): void
{
    $n = count($labels);
    $y0 = BANDS[$band]['y0'];
    $step = (BANDS[$band]['y1'] - $y0) / ($n + 1);
    foreach ($labels as $i => $label) {
        place($dots, $refs, $label, GATE_X, $y0 + ($i + 1) * $step);
    }
}

// --- Reed / Employee (northmost, nearest the entrance) ---------------------
place($dots, $refs, 'Reed Starter 1', STARTER_X[0], mid('reed'));
place($dots, $refs, 'Reed Starter 2', STARTER_X[1], mid('reed'));
apron($dots, $refs, 'reed', [
    'Reed Computer', 'Employee Computer',
    'Reed Counter 1', 'Reed Counter 2',
    'Employee Counter 1', 'Employee Counter 2',
    'Reed/Employee Runner 1', 'Reed/Employee Runner 2',
    'Reed/Employee Runner 3', 'Reed/Employee Runner 4',
]);
gates($dots, $refs, 'reed', [
    'Reed/Employee Back Gate 1', 'Reed/Employee Back Gate 2', 'Reed/Employee Back Gate 3',
]);

// --- OST -------------------------------------------------------------------
place($dots, $refs, 'OST Starter 1', STARTER_X[0], mid('ost'));
place($dots, $refs, 'OST Starter 2', STARTER_X[1], mid('ost'));
apron($dots, $refs, 'ost', [
    'OST Computer', 'OST Counter 1', 'OST Counter 2',
    'OST Runner 1', 'OST Runner 2', 'OST Runner 3', 'OST Runner 4',
]);
gates($dots, $refs, 'ost', ['OST Back Gate 1', 'OST Back Gate 2']);

// --- Special Events & Woodlands -------------------------------------------
// The Bus Callers work here too, in the lanes east of the starters (the
// committee's correction: "Bus Caller is located at Special Events").
place($dots, $refs, 'Woodlands Starter', STARTER_X[0], mid('sew') - 14);
place($dots, $refs, 'Special Events Starter', STARTER_X[1], mid('sew') - 14);
place($dots, $refs, 'Bus Caller 1', 543, mid('sew') + 14);
place($dots, $refs, 'Bus Caller 2', 566, mid('sew') + 14);
place($dots, $refs, 'Woodlands Runner', APRON_X[0], mid('sew'));
place($dots, $refs, 'Special Events Runner', APRON_X[1], mid('sew'));

// --- West Loop -------------------------------------------------------------
place($dots, $refs, 'WL Starter 1', STARTER_X[0], mid('wl'));
place($dots, $refs, 'WL Starter 2', STARTER_X[1], mid('wl'));
apron($dots, $refs, 'wl', [
    'WL Computer', 'WL Counter 1', 'WL Counter 2', 'WL Runner 1', 'WL Runner 2',
]);
gates($dots, $refs, 'wl', ['WL Back Gate 1', 'WL Back Gate 2']);

// --- Monroe ----------------------------------------------------------------
place($dots, $refs, 'Monroe Starter 1', STARTER_X[0], mid('monroe'));
place($dots, $refs, 'Monroe Starter 2', STARTER_X[1], mid('monroe'));
apron($dots, $refs, 'monroe', [
    'Monroe Computer', 'Monroe Counter 1', 'Monroe Counter 2', 'Monroe Runner 1', 'Monroe Runner 2',
]);
gates($dots, $refs, 'monroe', ['Monroe Back Gate 1', 'Monroe Back Gate 2']);

// --- Maxey -----------------------------------------------------------------
place($dots, $refs, 'Maxey Starter', 495, mid('maxey'));
apron($dots, $refs, 'maxey', [
    'Maxey Computer', 'Maxey Counter 1', 'Maxey Counter 2', 'Maxey Runner 1', 'Maxey Runner 2',
]);
gates($dots, $refs, 'maxey', ['Maxey Back Gate']);

// --- Gold Badge / LT (southmost, inside the tent south of Maxey) -----------
place($dots, $refs, 'GB/LT Starter 1', STARTER_X[0], mid('gblt'));
place($dots, $refs, 'GB/LT Starter 2', STARTER_X[1], mid('gblt'));
apron($dots, $refs, 'gblt', [
    'GB/LT Computer', 'GB/LT Counter 1', 'GB/LT Counter 2',
    'GB/LT Runner 1', 'GB/LT Runner 2', 'GB/LT Runner 3',
]);
gates($dots, $refs, 'gblt', ['GB/LT Back of Tent']);

// --- Tent Entrance / Overheads: the west side, toward the north end, where
// the committee's sketch put the entrance lines. Guests pass them into the
// outside queues.
foreach (['Tent Entrance/Overheads Lead', 'Tent Entrance/Overheads 2',
          'Tent Entrance/Overheads 3', 'Tent Entrance/Overheads 4',
          'Tent Entrance/Overheads 5', 'Tent Entrance/Overheads 6'] as $i => $label) {
    place($dots, $refs, $label, 46, 320 + $i * 46);
}

// --- The committee gate, beyond the Bus Ops complex at the south end -------
place($dots, $refs, 'Main Committee Gate Lead', 230, 1878);
place($dots, $refs, 'Main Committee Gate 2', 260, 1878);

// --- Unload: the lanes' north end (approximate; unload is phase one) -------
place($dots, $refs, 'Unload Starter', 478, 214);
place($dots, $refs, 'Unload Helper/Crowd Control', 513, 214);
place($dots, $refs, 'Unload Computer', 426, 214);

// --- Holly Hall (and Curve): beyond the drawing to the northeast -----------
place($dots, $refs, 'Holly Hall Center', 630, 268);
foreach ([1, 2, 3, 4, 5] as $i) {
    place($dots, $refs, "Holly Hall {$i}", 658 + ($i - 1) * 24, 268);
}
place($dots, $refs, 'Curve 1', 630, 316);
place($dots, $refs, 'Curve 2', 654, 316);

// --- Naomi: beyond the drawing to the southeast ----------------------------
place($dots, $refs, 'Center Starter', 630, 1652);
foreach ([1, 2, 3, 4, 5, 6] as $i) {
    place($dots, $refs, "Naomi Crosswalk Perimeter {$i}", 658 + ($i - 1) * 24, 1652);
    place($dots, $refs, "Naomi Bridge {$i}", 658 + ($i - 1) * 24, 1692);
}

// Both ways: everything placed exists, and everything that exists is placed.
foreach ($refs as $ref => $label) {
    if (!isset($dots[$ref])) {
        fail("never placed: {$label}");
    }
}
if (count($dots) !== 98) {
    fail('expected 98 markers, placed ' . count($dots));
}

// ---------------------------------------------------------------------------
// Emit.
// ---------------------------------------------------------------------------

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * The tiny tag shown on a marker so "Runner 3" is tellable from "Runner 1"
 * inside a cluster: the trailing number, E-prefixed for the Employee pair.
 */
function tag(string $label): string
{
    $n = preg_match('/(\d+)$/', $label, $m) === 1 ? $m[1] : '';

    return (str_starts_with($label, 'Employee') ? 'E' : '') . $n;
}

function svg(array $dots, array $refs): string
{
    $t = TENT;
    $l = LANES;

    $out = [];
    // Explicit width and height: the drawing renders at its own scale and
    // pans inside .map (which scrolls), rather than shrinking to fit a phone
    // and becoming unreadable. Portrait, because the tent runs north-south.
    $out[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 890 1960" '
        . 'width="890" height="1960" role="img" '
        . 'aria-label="Schematic of the NRG bus operations tarmac, north up">';

    // Generated file marker — regenerate, never hand-edit.
    $out[] = '<!-- Generated by bin/gen-tarmac-map.php. Layout from the committee';
    $out[] = '     sketch of August 2026 (north up, tent running north-south).';
    $out[] = '     Schematic, not a survey. Edit the generator, rerun, recommit -';
    $out[] = '     the position ids must keep matching position.map_ref. -->';

    // Compass and standing caption.
    $out[] = '<path class="tmap-arrow" d="M 835 100 L 835 58"/>';
    $out[] = '<path class="tmap-arrowhead" d="M 835 50 L 828 64 L 842 64 Z"/>';
    $out[] = '<text class="tmap-label" x="835" y="118" text-anchor="middle">N</text>';
    $out[] = '<text class="tmap-sublabel" x="860" y="1940" text-anchor="end">Schematic - not to scale</text>';

    // The tent and its seven stops, sections along its length.
    $zone = 0;
    foreach (BANDS as $band) {
        $class = ($zone++ % 2 === 0) ? 'tmap-zone-a' : 'tmap-zone-b';
        $out[] = sprintf(
            '<rect class="%s" x="%d" y="%d" width="%d" height="%d"/>',
            $class,
            $t['x0'],
            $band['y0'],
            $t['x1'] - $t['x0'],
            $band['y1'] - $band['y0']
        );
        $cx = ($t['x0'] + $t['x1']) / 2;
        $cy = ($band['y0'] + $band['y1']) / 2;
        if (str_contains($band['label'], ' & ')) {
            [$first, $rest] = explode(' & ', $band['label'], 2);
            $out[] = sprintf(
                '<text class="tmap-label" x="%.0f" y="%.0f" text-anchor="middle">%s</text>',
                $cx,
                $cy - 4,
                esc($first)
            );
            $out[] = sprintf(
                '<text class="tmap-label" x="%.0f" y="%.0f" text-anchor="middle">&amp; %s</text>',
                $cx,
                $cy + 16,
                esc($rest)
            );
        } else {
            $out[] = sprintf(
                '<text class="tmap-label" x="%.0f" y="%.0f" text-anchor="middle">%s</text>',
                $cx,
                $cy + 5,
                esc($band['label'])
            );
        }
    }
    $out[] = sprintf(
        '<rect class="tmap-tent" x="%d" y="%d" width="%d" height="%d"/>',
        $t['x0'],
        $t['y0'],
        $t['x1'] - $t['x0'],
        $t['y1'] - $t['y0']
    );
    $out[] = '<text class="tmap-sublabel" x="282" y="1728" text-anchor="middle">Tent - about 800 ft by 150 ft</text>';

    // The seven bus lanes along the east side.
    $out[] = sprintf(
        '<rect class="tmap-lanes" x="%d" y="%d" width="%d" height="%d"/>',
        $l['x0'],
        $l['y0'],
        $l['x1'] - $l['x0'],
        $l['y1'] - $l['y0']
    );
    $step = ($l['x1'] - $l['x0']) / $l['count'];
    for ($i = 1; $i < $l['count']; $i++) {
        $x = $l['x0'] + $i * $step;
        $out[] = sprintf(
            '<path class="tmap-lane-line" d="M %.1f %d L %.1f %d"/>',
            $x,
            $l['y0'],
            $x,
            $l['y1']
        );
    }
    $out[] = sprintf(
        '<text class="tmap-sublabel" x="%d" y="%d">Bus lanes 1-7 - buses load on the east side</text>',
        $l['x0'],
        $l['y1'] + 22
    );

    // The west side: the outside queue areas between the entrance lines and
    // the back gates, which are each stop's way in from those queues.
    $q = QUEUES;
    $out[] = sprintf(
        '<rect class="tmap-off" x="%d" y="%d" width="%d" height="%d" rx="8"/>',
        $q['x0'],
        $q['y0'],
        $q['x1'] - $q['x0'],
        $q['y1'] - $q['y0']
    );
    $out[] = sprintf(
        '<text class="tmap-sublabel" transform="rotate(-90 115 %.0f)" x="115" y="%.0f" text-anchor="middle">Outside queues - guests line up here, then enter through the back gates</text>',
        ($q['y0'] + $q['y1']) / 2,
        ($q['y0'] + $q['y1']) / 2
    );

    // South of the tent: the Bus Ops complex, drawn TOUCHING the south wall —
    // the committee wants the buildings as reference points people orient by.
    // The committee gate sits beyond them.
    $buildings = [
        ['Bus Ops Office', 170, 1740, 110, 55],
        ['Log Cabin', 285, 1740, 110, 55],
        ['Restrooms', 170, 1800, 110, 55],
        ['Chuck Wagon', 285, 1800, 110, 55],
    ];
    foreach ($buildings as [$label, $x, $y, $w, $h]) {
        $out[] = sprintf('<rect class="tmap-bldg" x="%d" y="%d" width="%d" height="%d"/>', $x, $y, $w, $h);
        $out[] = sprintf(
            '<text class="tmap-sublabel" x="%.0f" y="%.0f" text-anchor="middle">%s</text>',
            $x + $w / 2,
            $y + $h / 2 + 4,
            esc($label)
        );
    }
    $out[] = '<path class="tmap-lane-line" d="M 170 1866 L 395 1866"/>';
    $out[] = '<text class="tmap-sublabel" x="290" y="1882">Committee Gate</text>';

    // North of the tent: the lone bathroom, against the north wall.
    $out[] = '<rect class="tmap-bldg" x="315" y="180" width="80" height="40"/>';
    $out[] = '<text class="tmap-sublabel" x="355" y="204" text-anchor="middle">Bathroom</text>';

    // Guests come in on the west side, through the entrance lines and into
    // the outside queues.
    $out[] = '<text class="tmap-sublabel" x="24" y="214">Tent Entrance / Overheads</text>';
    $out[] = '<text class="tmap-sublabel" x="24" y="232">guests enter here</text>';
    $out[] = '<path class="tmap-arrow" d="M 58 570 L 88 570"/>';
    $out[] = '<path class="tmap-arrowhead" d="M 96 570 L 82 563 L 82 577 Z"/>';

    // The two call-outs for ground beyond the drawing.
    $out[] = '<rect class="tmap-off" x="606" y="220" width="264" height="120" rx="10"/>';
    $out[] = '<text class="tmap-sublabel" x="618" y="244">To Holly Hall Gate &#8599; northeast</text>';
    $out[] = '<text class="tmap-sublabel" x="794" y="272">crosswalk</text>';
    $out[] = '<text class="tmap-sublabel" x="686" y="320">Curve (location approx.)</text>';

    $out[] = '<rect class="tmap-off" x="606" y="1604" width="264" height="120" rx="10"/>';
    $out[] = '<text class="tmap-sublabel" x="618" y="1628">To Naomi Gate &#8600; southeast</text>';
    $out[] = '<text class="tmap-sublabel" x="810" y="1656">crosswalk</text>';
    $out[] = '<text class="tmap-sublabel" x="810" y="1696">bridge</text>';

    // Cluster captions for the markers outside the bands.
    $out[] = sprintf(
        '<text class="tmap-sublabel" x="554" y="%.0f" text-anchor="middle">Bus Callers</text>',
        mid('sew') + 36
    );

    // Every position: one addressable dot, its id the position's map_ref.
    foreach ($dots as $ref => $at) {
        $label = $refs[$ref];
        $out[] = sprintf(
            '<circle id="%s" class="pos" cx="%.1f" cy="%.1f" r="6"><title>%s</title></circle>',
            esc($ref),
            $at['x'],
            $at['y'],
            esc($label)
        );

        $t2 = tag($label);
        if ($t2 !== '') {
            $out[] = sprintf(
                '<text class="pos-num" x="%.1f" y="%.1f">%s</text>',
                $at['x'],
                $at['y'] + 2.6,
                esc($t2)
            );
        }
    }

    $out[] = '</svg>';

    return implode("\n", $out) . "\n";
}

/** The UPDATE statements migration 008 carries, one per position. */
function refSql(array $refs): string
{
    $out = [];
    foreach ($refs as $ref => $label) {
        $safe = str_replace("'", "''", $label);
        $out[] = "UPDATE position SET map_ref = '{$ref}' WHERE label = '{$safe}';";
    }

    return implode("\n", $out) . "\n";
}

$args = array_slice($argv, 1);
$mode = 'svg';
$outFile = null;
foreach ($args as $arg) {
    if ($arg === '--refs') {
        $mode = 'refs';
    } elseif ($arg === '--check') {
        $mode = 'check';
    } elseif (str_starts_with($arg, '--out=')) {
        $outFile = substr($arg, 6);
    } else {
        fail("unknown argument: {$arg}");
    }
}

if ($mode === 'refs') {
    echo refSql($refs);
    exit(0);
}

if ($mode === 'check') {
    $drift = [];

    if (!is_file($svgFile) || file_get_contents($svgFile) !== svg($dots, $refs)) {
        $drift[] = 'public/assets/map/tarmac.svg differs from the generator';
    }

    $migration = is_file($migrationFile) ? (string) file_get_contents($migrationFile) : '';
    foreach (explode("\n", trim(refSql($refs))) as $statement) {
        if (!str_contains($migration, $statement)) {
            $drift[] = 'migration 008 is missing: ' . $statement;
        }
    }

    if ($drift !== []) {
        foreach ($drift as $line) {
            fwrite(STDERR, "gen-tarmac-map: {$line}\n");
        }
        exit(1);
    }

    echo "gen-tarmac-map: SVG and migration 008 match the generator\n";
    exit(0);
}

$svg = svg($dots, $refs);
if ($outFile !== null) {
    if (!is_dir(dirname($outFile))) {
        mkdir(dirname($outFile), 0755, true);
    }
    file_put_contents($outFile, $svg);
    fwrite(STDERR, 'gen-tarmac-map: wrote ' . strlen($svg) . " bytes to {$outFile}\n");
} else {
    echo $svg;
}
