<?php

declare(strict_types=1);

use Resm\App;
use Resm\Config;
use Resm\Http\Request;
use Resm\Http\Response;
use Resm\Http\Router;

function testApp(): App
{
    return App::boot(dirname(__DIR__));
}

function requestFor(string $uri, string $method = 'GET'): Request
{
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = [];
    $_POST = [];

    return Request::capture(testApp());
}

// ---------------------------------------------------------------------------
// Base path handling — the app is mounted at /resm/, never the domain root
// ---------------------------------------------------------------------------

test('the app root arrives as an empty path', function (): void {
    assertSame('', requestFor('/resm/')->path);
});

test('the mount point is stripped from every path', function (): void {
    assertSame('officer/assign', requestFor('/resm/officer/assign')->path);
    assertSame('status', requestFor('/resm/status?key=abc')->path);
});

test('a percent-encoded path is decoded', function (): void {
    assertSame('tools/change pin', requestFor('/resm/tools/change%20pin')->path);
});

test('urls are built from the configured base path', function (): void {
    $app = testApp();
    assertSame('/resm/', $app->url());
    assertSame('/resm/officer/assign', $app->url('officer/assign'));

    // A caller who writes a leading slash still gets one prefix, not two.
    assertSame('/resm/officer/assign', $app->url('/officer/assign'));
});

test('asset urls carry a cache-busting stamp', function (): void {
    $url = testApp()->asset('css/app.css');
    assertTrue(str_starts_with($url, '/resm/assets/css/app.css?v='), "got {$url}");
});

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

test('routes dispatch on exact paths', function (): void {
    $router = (new Router())
        ->get('', static fn (): Response => Response::text('home'))
        ->get('status', static fn (): Response => Response::text('status'));

    assertSame('home', $router->dispatch(testApp(), requestFor('/resm/'))->body());
    assertSame('status', $router->dispatch(testApp(), requestFor('/resm/status'))->body());
});

test('placeholders are captured and passed to the handler', function (): void {
    $router = (new Router())->get(
        'officer/assign/{phase}',
        static fn (App $a, Request $r, array $params): Response => Response::text($params['phase'])
    );

    assertSame('bump_run', $router->dispatch(testApp(), requestFor('/resm/officer/assign/bump_run'))->body());
});

test('a placeholder does not match across a slash', function (): void {
    $router = (new Router())->get('officer/{phase}', static fn (): Response => Response::text('matched'));

    assertSame(404, $router->dispatch(testApp(), requestFor('/resm/officer/a/b'))->status);
});

test('a known path with the wrong method is 405, not 404', function (): void {
    $router = (new Router())->post('login', static fn (): Response => Response::text('ok'));

    assertSame(405, $router->dispatch(testApp(), requestFor('/resm/login', 'GET'))->status);
    assertSame(404, $router->dispatch(testApp(), requestFor('/resm/nope', 'GET'))->status);
});

test('the not-found handler takes over when nothing matches', function (): void {
    $router = (new Router())->notFound(static fn (): Response => Response::text('custom', 404));

    $response = $router->dispatch(testApp(), requestFor('/resm/missing'));
    assertSame(404, $response->status);
    assertSame('custom', $response->body());
});

// ---------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------

test('json responses are typed and unescaped', function (): void {
    $response = Response::json(['shift' => 3, 'version' => 12]);
    assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
    assertSame('{"shift":3,"version":12}', $response->body());
});

test('a not-modified response carries no body', function (): void {
    // The polling endpoint's cheap answer (spec 10.2).
    assertSame(304, Response::notModified()->status);
    assertSame('', Response::notModified()->body());
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

test('configuration reads with dotted keys', function (): void {
    $config = Config::load(dirname(__DIR__) . '/config');
    assertSame('/resm/', $config->string('app.base_path'));
    assertSame('America/Chicago', $config->string('app.display_timezone'));
    assertSame(null, $config->get('nothing.here'));
    assertSame('fallback', $config->string('nothing.here', 'fallback'));
});

test('a missing required key is an error, not a silent null', function (): void {
    $config = Config::load(dirname(__DIR__) . '/config');
    assertThrows(RuntimeException::class, static fn () => $config->require('app.not_a_key'));
});

test('an empty config.local.php is reported, not a blank page', function (): void {
    // What actually happened on the server: File Manager saved a 0-byte file,
    // require returned int(1), and merge() died with an uncaught TypeError.
    // An administrator with no shell had nothing on screen to act on.
    $dir = sys_get_temp_dir() . '/resm-cfg-' . bin2hex(random_bytes(4));
    mkdir($dir);
    copy(dirname(__DIR__) . '/config/config.php', $dir . '/config.php');
    file_put_contents($dir . '/config.local.php', '');

    try {
        assertThrows(Resm\ConfigurationError::class, static fn () => Config::load($dir));

        try {
            Config::load($dir);
        } catch (Resm\ConfigurationError $e) {
            assertTrue(str_contains($e->getMessage(), 'config.local.php'), 'names the file');
            assertTrue(str_contains($e->getMessage(), 'empty'), 'names the likely cause');
        }
    } finally {
        @unlink($dir . '/config.php');
        @unlink($dir . '/config.local.php');
        @rmdir($dir);
    }
});

test('a syntax error in config.local.php names the line, not a blank 500', function (): void {
    // What actually happened on the server, 27 August: a missing comma while
    // adding setup_key by hand in File Manager. config.local.php is the one
    // PHP file an administrator edits directly, it is not in git, and that
    // account has no shell to lint it with - so a parse error there took down
    // every page on the site with an empty 500 and nothing on screen.
    //
    // A ParseError from require is catchable, which is what makes this
    // reportable at all.
    $dir = sys_get_temp_dir() . '/resm-cfg-' . bin2hex(random_bytes(4));
    mkdir($dir);
    copy(dirname(__DIR__) . '/config/config.php', $dir . '/config.php');
    file_put_contents($dir . '/config.local.php', <<<'BROKEN'
        <?php

        return [
            'app' => [
                'status_key' => 'abc'
                'debug' => false,
            ],
        ];
        BROKEN);

    try {
        assertThrows(Resm\ConfigurationError::class, static fn () => Config::load($dir));

        try {
            Config::load($dir);
        } catch (Resm\ConfigurationError $e) {
            assertTrue(str_contains($e->getMessage(), 'config.local.php'), 'names the file');
            assertTrue(str_contains($e->getMessage(), 'syntax error'), 'says what kind of problem');
            assertTrue(str_contains($e->getMessage(), 'line 6'), 'names the line: ' . $e->getMessage());
            assertTrue(str_contains($e->getMessage(), 'comma'), 'names the usual cause');
        }
    } finally {
        @unlink($dir . '/config.php');
        @unlink($dir . '/config.local.php');
        @rmdir($dir);
    }
});

test('a valid config.local.php still overrides normally', function (): void {
    $dir = sys_get_temp_dir() . '/resm-cfg-' . bin2hex(random_bytes(4));
    mkdir($dir);
    copy(dirname(__DIR__) . '/config/config.php', $dir . '/config.php');
    // Deliberately a key with no RESM_* mapping: the environment layer is
    // applied after the local file and would otherwise win, which is by
    // design and not what this test is about.
    file_put_contents($dir . '/config.local.php', "<?php return ['app' => ['display_timezone' => 'America/Denver']];");

    try {
        assertSame('America/Denver', Config::load($dir)->string('app.display_timezone'));
    } finally {
        @unlink($dir . '/config.php');
        @unlink($dir . '/config.local.php');
        @rmdir($dir);
    }
});

test('environment variables override the committed defaults', function (): void {
    putenv('RESM_BASE_PATH=/other/');
    putenv('RESM_SESSION_SECURE=false');
    putenv('RESM_DB_PORT=3307');
    try {
        $config = Config::load(dirname(__DIR__) . '/config');
        assertSame('/other/', $config->string('app.base_path'));
        assertSame(false, $config->get('session.secure'), 'booleans are coerced');
        assertSame(3307, $config->get('db.port'), 'integers are coerced');
    } finally {
        putenv('RESM_BASE_PATH');
        putenv('RESM_SESSION_SECURE');
        putenv('RESM_DB_PORT');
    }
});

// ---------------------------------------------------------------------------
// Escaping
// ---------------------------------------------------------------------------

test('the escaping helper handles quotes and null', function (): void {
    assertSame('&lt;script&gt;', e('<script>'));
    assertSame('O&#039;Brien', e("O'Brien"));
    assertSame('', e(null));
});

test('the retention window is configured, and is a query bound not a delete', function (): void {
    // Spec 11.5 #7, answered 27 August: five years. The audit log and the
    // export range over that rather than over everything ever recorded.
    //
    // Pinned here for the same reason the poll intervals were pinned before
    // the polling layer existed: phase 6 builds against this number, and a
    // number that quietly changes underneath a report is worse than one that
    // was never there.
    $config = Config::load(dirname(__DIR__) . '/config');

    assertSame(5, $config->int('retention.seasons_years'));
});
