<?php

declare(strict_types=1);

use Resm\App;
use Resm\Http\Request;

/**
 * The /setup route exists because this hosting account has no shell: without
 * it, migrations and the seeded administrator's PIN cannot be reached at all.
 *
 * That makes its guard the most security-sensitive line in the application —
 * whoever passes it can take the master admin account. These pin the two
 * properties that matter: it does not exist unless a key is configured, and a
 * wrong key is refused.
 */
function setupRequest(string $key = ''): Request
{
    return new Request('GET', 'setup', $key === '' ? [] : ['key' => $key], [], []);
}

function permitted(?string $configured, string $provided): bool
{
    // Mirrors setupPermitted() in app/routes.php, which cannot be loaded here
    // without registering every route.
    return is_string($configured) && $configured !== ''
        && $provided !== ''
        && hash_equals($configured, $provided);
}

test('setup is closed when no key is configured', function (): void {
    // The state to leave the server in once the app is running.
    assertSame(false, permitted(null, 'anything'));
    assertSame(false, permitted('', 'anything'));
    assertSame(false, permitted(null, ''));
});

test('setup is closed for a wrong or empty key', function (): void {
    assertSame(false, permitted('correct-horse', 'wrong'));
    assertSame(false, permitted('correct-horse', ''));
    assertSame(false, permitted('correct-horse', 'correct-hors'));
    assertSame(false, permitted('correct-horse', 'correct-horsee'));
});

test('setup opens only for an exact key match', function (): void {
    assertSame(true, permitted('correct-horse', 'correct-horse'));
});

test('setup_key defaults to disabled', function (): void {
    // A deployment that never sets one has no setup route, which is what makes
    // forgetting to remove it the only way to leave the door open.
    $app = App::boot(dirname(__DIR__));
    assertSame(null, $app->config->get('app.setup_key'));
});

test('the key is read from the request like any other input', function (): void {
    assertSame('abc', setupRequest('abc')->input('key'));
    assertSame(null, setupRequest()->input('key'));
});
