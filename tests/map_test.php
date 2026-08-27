<?php

declare(strict_types=1);

use Resm\App;
use Resm\TarmacMap;

/**
 * The tarmac map viewer (spec 6.5, 11.4).
 *
 * The drawing is content Rodeo Express still owes, so what is testable now is
 * the shape of the hole it drops into — and the one thing this screen must
 * never do, which is show a tarmac that does not exist.
 */

function mapApp(): App
{
    return App::boot(dirname(__DIR__));
}

/** Write a stand-in drawing, run $work, and take it away again. */
function withDrawing(string $svg, callable $work): void
{
    $app = mapApp();
    $file = TarmacMap::path($app);
    $dir = dirname($file);
    $existed = is_file($file);

    if ($existed) {
        // Never overwrite the real thing, on the day it exists.
        throw new RuntimeException('a real tarmac.svg is present; this test would clobber it');
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($file, $svg);

    try {
        $work($app);
    } finally {
        unlink($file);
    }
}

// ---------------------------------------------------------------------------
// While it is still owed
// ---------------------------------------------------------------------------

test('no tarmac is shipped, and the viewer says so rather than inventing one', function (): void {
    $app = mapApp();

    // The load-bearing assertion of this whole screen. A drawn-in layout would
    // make it look finished and would send a new committeeman confidently to
    // the wrong place — worse than sending him to ask somebody.
    assertSame(null, TarmacMap::read($app));
    assertSame(false, TarmacMap::isAvailable($app));
});

test('the placeholder lists what is actually needed', function (): void {
    $needed = TarmacMap::needed();

    // Spec 11.4's four items, on the screen rather than only in the document:
    // the person who can supply it is far likelier to open the app than the
    // spec, and every viewing is a chance to ask.
    assertCount(4, $needed);
    assertTrue(str_contains($needed[1], '98 positions'), 'it must say how many positions');
});

// ---------------------------------------------------------------------------
// The day it arrives
// ---------------------------------------------------------------------------

test('a drawing dropped into place is picked up with no code change', function (): void {
    withDrawing('<svg xmlns="http://www.w3.org/2000/svg"><rect id="R1"/></svg>', function (App $app): void {
        assertTrue(TarmacMap::isAvailable($app));
        assertTrue(str_contains((string) TarmacMap::read($app), 'id="R1"'));
    });
});

test('a file that is not a drawing is not rendered as one', function (): void {
    withDrawing('this is not an svg', function (App $app): void {
        assertSame(null, TarmacMap::read($app));
    });
});

test('a drawing carrying script is refused', function (): void {
    withDrawing(
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect id="R1"/></svg>',
        function (App $app): void {
            // The page's CSP is script-src 'self', so it would not run anyway.
            // But a site plan exported from a CAD tool has no business carrying
            // one, and refusing is a clearer signal than quietly serving a file
            // somebody has been at (spec 10.5).
            assertSame(null, TarmacMap::read($app));
        }
    );
});

test('a drawing is precached with the rest of the shell once it exists', function (): void {
    withDrawing('<svg xmlns="http://www.w3.org/2000/svg"><rect id="R1"/></svg>', function (App $app): void {
        $assets = Resm\Pwa\Shell::assets($app);
        $found = false;

        foreach ($assets as $url) {
            if (str_contains($url, 'map/tarmac.svg')) {
                $found = true;
            }
        }

        // Spec 10.3 caches the tarmac map cache-first. It lands under
        // public/assets/, which the shell discovers from disk — so this needs
        // no list to be updated on the day it arrives.
        assertTrue($found, 'the drawing must join the precached shell');
    });
});

// ---------------------------------------------------------------------------
// Addressing a position inside it
// ---------------------------------------------------------------------------

test('a map_ref is only used when it is safe to put in a selector', function (): void {
    // map_ref is typed in by hand alongside the drawing. A stray space or
    // quote in one row must mean "no highlight on that position", never a
    // broken query that takes out the whole screen.
    assertSame('R1', TarmacMap::ref('R1'));
    assertSame('reed-gate-2', TarmacMap::ref('reed-gate-2'));
    assertSame('Naomi_Bridge_1', TarmacMap::ref('Naomi_Bridge_1'));

    assertSame(null, TarmacMap::ref(null), 'no ref set');
    assertSame(null, TarmacMap::ref(''), 'empty');
    assertSame(null, TarmacMap::ref('reed gate'), 'a space');
    assertSame(null, TarmacMap::ref('R1"], [x'), 'a selector escape');
    assertSame(null, TarmacMap::ref('1R'), 'ids do not start with a digit');
    assertSame(null, TarmacMap::ref(str_repeat('a', 61)), 'longer than the column');
});

test('the position table can carry a ref for every position', function (): void {
    inRollback(function (Resm\Database $db): void {
        // 98 positions, and the column they will be filled in on. Nothing
        // populates it yet — it arrives with the drawing (spec 11.4 step 2).
        assertSame(98, (int) $db->value('SELECT COUNT(*) FROM position'));

        $db->execute("UPDATE position SET map_ref = 'R1' WHERE label = (SELECT label FROM (SELECT label FROM position ORDER BY id LIMIT 1) x)");
        assertSame(1, (int) $db->value("SELECT COUNT(*) FROM position WHERE map_ref = 'R1'"));
    });
});
