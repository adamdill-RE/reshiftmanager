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
 * The layout is transcribed from what Rodeo Express supplied in August 2026:
 * a tent roughly 800ft by 150ft, buses loading on the west side in seven
 * lanes, guests entering from the southeast and walking to their stop, the
 * stops running north to south as Reed, OST, Special Events & Woodlands,
 * West Loop, Monroe, Maxey — Reed and OST the largest. Back gates on the
 * east side inside the tent; computers, counters and runners just outside
 * the west side; starters in the bus lanes even with their stops; overheads
 * at the extreme southeast; the Bus Ops office, log cabin, bathrooms and
 * chuck wagon north of the tent with the committee gate on their north side;
 * a second bathroom south of the tent. Curve and Holly Hall lie beyond the
 * drawing to the southwest, Naomi and its walkover bridge beyond it to the
 * northwest — both drawn as call-out boxes so the men who work them still
 * see their own dot.
 *
 * The drawing is a schematic, not a survey: the north-south axis is
 * stretched so six stop bands stay legible on a phone. Positions whose exact
 * spot was not described (GB/LT, Unload, the Bus Callers) are placed where
 * the description implies and are expected to move when the committee
 * corrects them — rerun this script and recommit; the ids never change.
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
// Geometry. North is up. The tent is drawn 1200x330 for an 800ft x 150ft
// tent — the north-south axis deliberately stretched (schematic, not survey).
// ---------------------------------------------------------------------------

const TENT = ['x0' => 260, 'y0' => 300, 'x1' => 1460, 'y1' => 630];
const LANES = ['x0' => 90, 'y0' => 290, 'x1' => 218, 'y1' => 640, 'count' => 7];

// The six stop bands, north to south, Reed and OST the largest.
const BANDS = [
    'reed'   => ['label' => 'Reed / Employee',            'y0' => 300, 'y1' => 377],
    'ost'    => ['label' => 'OST',                        'y0' => 377, 'y1' => 447],
    'sew'    => ['label' => 'Special Events & Woodlands', 'y0' => 447, 'y1' => 487],
    'wl'     => ['label' => 'West Loop',                  'y0' => 487, 'y1' => 535],
    'monroe' => ['label' => 'Monroe',                     'y0' => 535, 'y1' => 583],
    'maxey'  => ['label' => 'Maxey',                      'y0' => 583, 'y1' => 630],
];

// Starters sit in the lanes, even with their stop; the support crew sits on
// the west apron just outside the tent wall; back gates on the east side.
const STARTER_X = [160, 195];
const APRON_X = [230, 248];
const GATE_X = 1442;

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

/** The west-apron grid for a band: two columns, rows every 16. */
function apron(array &$dots, array $refs, string $band, array $labels): void
{
    $y0 = BANDS[$band]['y0'] + 8;
    foreach ($labels as $i => $label) {
        place($dots, $refs, $label, APRON_X[$i % 2], $y0 + intdiv($i, 2) * 16);
    }
}

/** Back gates for a band: one east column, spread over the band. */
function gates(array &$dots, array $refs, string $band, array $labels): void
{
    $n = count($labels);
    $y0 = BANDS[$band]['y0'];
    $step = (BANDS[$band]['y1'] - $y0) / ($n + 1);
    foreach ($labels as $i => $label) {
        place($dots, $refs, $label, GATE_X, $y0 + ($i + 1) * $step);
    }
}

// --- Reed / Employee (northmost, the largest) ------------------------------
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
place($dots, $refs, 'Woodlands Starter', STARTER_X[0], mid('sew'));
place($dots, $refs, 'Special Events Starter', STARTER_X[1], mid('sew'));
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

// --- Maxey (southmost) -----------------------------------------------------
place($dots, $refs, 'Maxey Starter', 178, mid('maxey'));
apron($dots, $refs, 'maxey', [
    'Maxey Computer', 'Maxey Counter 1', 'Maxey Counter 2', 'Maxey Runner 1', 'Maxey Runner 2',
]);
gates($dots, $refs, 'maxey', ['Maxey Back Gate']);

// --- Tent Entrance / Overheads: the extreme southeast, where guests enter --
foreach (['Tent Entrance/Overheads Lead', 'Tent Entrance/Overheads 2',
          'Tent Entrance/Overheads 3', 'Tent Entrance/Overheads 4',
          'Tent Entrance/Overheads 5', 'Tent Entrance/Overheads 6'] as $i => $label) {
    place($dots, $refs, $label, 1285 + $i * 26, 612);
}

// --- The committee gate, north of the Bus Ops office complex ---------------
place($dots, $refs, 'Main Committee Gate Lead', 635, 130);
place($dots, $refs, 'Main Committee Gate 2', 665, 130);

// --- Bus Callers: at the lane entry, south end of the lanes (approximate) --
place($dots, $refs, 'Bus Caller 1', 140, 655);
place($dots, $refs, 'Bus Caller 2', 168, 655);

// --- Unload: the lanes' north end (approximate; unload is phase one) -------
place($dots, $refs, 'Unload Starter', 140, 276);
place($dots, $refs, 'Unload Helper/Crowd Control', 168, 276);
place($dots, $refs, 'Unload Computer', 230, 276);

// --- GB/LT: north of the tent's west end (location approximate) ------------
foreach (['GB/LT Starter 1', 'GB/LT Starter 2', 'GB/LT Computer',
          'GB/LT Counter 1', 'GB/LT Counter 2'] as $i => $label) {
    place($dots, $refs, $label, 310 + $i * 24, 252);
}
foreach (['GB/LT Runner 1', 'GB/LT Runner 2', 'GB/LT Runner 3'] as $i => $label) {
    place($dots, $refs, $label, 310 + $i * 24, 274);
}
place($dots, $refs, 'GB/LT Back of Tent', 380, 316);

// --- Naomi: beyond the drawing to the northwest, drawn as a call-out -------
place($dots, $refs, 'Center Starter', 108, 84);
foreach ([1, 2, 3, 4, 5, 6] as $i) {
    place($dots, $refs, "Naomi Crosswalk Perimeter {$i}", 148 + ($i - 1) * 24, 84);
    place($dots, $refs, "Naomi Bridge {$i}", 148 + ($i - 1) * 24, 116);
}

// --- Curve and Holly Hall: beyond the drawing to the southwest -------------
place($dots, $refs, 'Curve 1', 108, 764);
place($dots, $refs, 'Curve 2', 132, 764);
place($dots, $refs, 'Holly Hall Center', 210, 764);
foreach ([1, 2, 3, 4, 5] as $i) {
    place($dots, $refs, "Holly Hall {$i}", 234 + ($i - 1) * 24, 764);
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
    // and becoming unreadable.
    $out[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1700 830" '
        . 'width="1700" height="830" role="img" '
        . 'aria-label="Schematic of the NRG bus operations tarmac">';

    // Generated file marker — regenerate, never hand-edit.
    $out[] = '<!-- Generated by bin/gen-tarmac-map.php. Layout supplied by Rodeo';
    $out[] = '     Express, August 2026. Schematic, not a survey: the north-south';
    $out[] = '     axis is stretched for legibility. Edit the generator, rerun,';
    $out[] = '     recommit - the position ids must keep matching position.map_ref. -->';

    // Compass and standing caption.
    $out[] = '<path class="tmap-arrow" d="M 1640 70 L 1640 28"/>';
    $out[] = '<path class="tmap-arrowhead" d="M 1640 20 L 1633 34 L 1647 34 Z"/>';
    $out[] = '<text class="tmap-label" x="1640" y="88" text-anchor="middle">N</text>';
    $out[] = '<text class="tmap-sublabel" x="1655" y="810" text-anchor="end">Schematic - not to scale</text>';

    // The tent and its six stop bands.
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
        $out[] = sprintf(
            '<text class="tmap-label" x="%d" y="%.0f">%s</text>',
            $t['x0'] + 320,
            ($band['y0'] + $band['y1']) / 2 + 5,
            esc($band['label'])
        );
    }
    $out[] = sprintf(
        '<rect class="tmap-tent" x="%d" y="%d" width="%d" height="%d"/>',
        $t['x0'],
        $t['y0'],
        $t['x1'] - $t['x0'],
        $t['y1'] - $t['y0']
    );
    $out[] = sprintf(
        '<text class="tmap-sublabel" x="%d" y="%d">Tent - about 800 ft by 150 ft</text>',
        $t['x0'] + 8,
        $t['y0'] - 8
    );

    // The seven bus lanes on the west side.
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
        '<text class="tmap-sublabel" x="%d" y="%d">Bus lanes (7) - buses load on the west side</text>',
        $l['x0'],
        $l['y1'] + 18
    );

    // North of the tent: the Bus Ops complex, committee gate on its north side.
    $buildings = [
        ['Bus Ops Office', 560, 170, 180, 60],
        ['Log Cabin', 760, 170, 90, 60],
        ['Bathrooms', 870, 170, 80, 60],
        ['Chuck Wagon', 970, 170, 110, 60],
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
    $out[] = '<path class="tmap-lane-line" d="M 560 150 L 1080 150"/>';
    $out[] = '<text class="tmap-sublabel" x="700" y="112">Committee Gate</text>';

    // South of the tent: the second bathroom.
    $out[] = '<rect class="tmap-bldg" x="840" y="680" width="100" height="50"/>';
    $out[] = '<text class="tmap-sublabel" x="890" y="709" text-anchor="middle">Bathroom</text>';

    // GB/LT loading area, north of the tent's west end - placement approximate.
    $out[] = '<rect class="tmap-off" x="290" y="232" width="210" height="60" rx="8"/>';
    $out[] = '<text class="tmap-sublabel" x="295" y="226">GB / LT loading (approx.)</text>';

    // Guests enter from the southeast and walk west to their stop.
    $out[] = sprintf(
        '<path class="tmap-arrow" d="M 1600 590 L %d 590"/>',
        $t['x1'] + 14
    );
    $out[] = sprintf(
        '<path class="tmap-arrowhead" d="M %d 590 L %d 583 L %d 597 Z"/>',
        $t['x1'] + 4,
        $t['x1'] + 18,
        $t['x1'] + 18
    );
    $out[] = '<text class="tmap-sublabel" x="1600" y="578" text-anchor="end">Guests enter (southeast)</text>';
    $out[] = sprintf(
        '<path class="tmap-walk" d="M %d 600 L %d 600"/>',
        $t['x1'] - 30,
        $t['x0'] + 60
    );
    $out[] = sprintf(
        '<text class="tmap-sublabel" x="%d" y="592">guests walk west to their stop</text>',
        $t['x0'] + 620
    );

    // The two call-outs for ground beyond the drawing.
    $out[] = '<rect class="tmap-off" x="70" y="40" width="330" height="104" rx="10"/>';
    $out[] = '<text class="tmap-sublabel" x="82" y="62">Naomi crosswalk &amp; walkover bridge &#8598; northwest of here</text>';
    $out[] = '<text class="tmap-sublabel" x="290" y="88">crosswalk</text>';
    $out[] = '<text class="tmap-sublabel" x="290" y="120">bridge</text>';

    $out[] = '<rect class="tmap-off" x="70" y="722" width="330" height="76" rx="10"/>';
    $out[] = '<text class="tmap-sublabel" x="82" y="744">Curve &#183; Holly Hall crosswalk &#8601; southwest of here</text>';

    // Cluster captions for the markers outside the bands.
    $out[] = '<text class="tmap-sublabel" x="128" y="300" text-anchor="middle">Unload</text>';
    $out[] = '<text class="tmap-sublabel" x="154" y="679" text-anchor="middle">Bus Callers</text>';
    $out[] = '<text class="tmap-sublabel" x="1363" y="637" text-anchor="middle">Tent Entrance / Overheads</text>';

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
