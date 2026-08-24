<?php

declare(strict_types=1);

use Resm\App;
use Resm\Auth\Auth;
use Resm\Auth\Pin;
use Resm\Auth\Role;
use Resm\Database;
use Resm\Http\CookieStore;
use Resm\Http\Request;

/** Cookies in memory, so rotation can be watched without a browser. */
final class ArrayCookies implements CookieStore
{
    /** @var array<string, string> */
    public array $jar = [];
    /** @var array<int, string> */
    public array $forgotten = [];

    public function get(string $name): ?string
    {
        return $this->jar[$name] ?? null;
    }

    public function set(string $name, string $value, int $expiresAt): void
    {
        $this->jar[$name] = $value;
    }

    public function forget(string $name): void
    {
        unset($this->jar[$name]);
        $this->forgotten[] = $name;
    }
}

function authRequest(string $ip = '198.51.100.7'): Request
{
    return new Request('POST', 'login', [], [], [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => 'test-agent',
    ]);
}

/** A fresh Auth over the test database, with its own cookie jar and session. */
function makeAuth(?ArrayCookies $cookies = null, string $ip = '198.51.100.7'): array
{
    $app = App::boot(dirname(__DIR__));
    // Force the same connection the fixtures were written on, so everything
    // stays inside the test transaction.
    $reflection = new ReflectionProperty(App::class, 'db');
    $reflection->setValue($app, testDb());

    $cookies ??= new ArrayCookies();
    $auth = new Auth($app, authRequest($ip), $cookies);

    return [$auth, $cookies, $app];
}

/** Create a user and return their id. */
function makeUser(Database $db, string $memberId, string $pin = '1234', Role $role = Role::Committeeman, bool $active = true): int
{
    $db->execute(
        'INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role, is_active)
         VALUES (:m, :l, :f, :h, :r, :a)',
        [
            'm' => $memberId,
            'l' => 'Tester',
            'f' => 'Terry',
            'h' => Pin::hash($pin, 4),
            'r' => $role->value,
            'a' => $active ? 1 : 0,
        ]
    );

    return $db->lastInsertId();
}

/** Each test gets a clean session and a rolled-back database. */
function inAuthRollback(callable $work): void
{
    $_SESSION = [];
    $db = testDb();
    $pdo = $db->pdo();
    $pdo->beginTransaction();
    try {
        $work($db);
    } finally {
        $pdo->rollBack();
        $_SESSION = [];
    }
}

// ---------------------------------------------------------------------------
// The PIN itself
// ---------------------------------------------------------------------------

test('a PIN is exactly four digits', function (): void {
    assertTrue(Pin::isValid('1234'));
    assertTrue(Pin::isValid('0000'), 'leading zeros are real PINs');

    foreach (['123', '12345', '', 'abcd', '12 4', '1e34', '12.4', '-123'] as $bad) {
        assertTrue(!Pin::isValid($bad), "'{$bad}' should be rejected");
    }
});

test('PINs round-trip through bcrypt and never store plaintext', function (): void {
    $hash = Pin::hash('4821', 4);

    assertTrue(str_starts_with($hash, '$2y$'), 'bcrypt');
    assertTrue(!str_contains($hash, '4821'), 'the PIN must not appear in the hash');
    assertTrue(Pin::verify('4821', $hash));
    assertTrue(!Pin::verify('4822', $hash));
});

test('the same PIN hashes differently every time', function (): void {
    // Salted, so two committeemen who both left theirs at 1234 do not share a
    // row value that gives it away.
    assertTrue(Pin::hash('1234', 4) !== Pin::hash('1234', 4));
});

test('a cost increase is detected', function (): void {
    assertTrue(Pin::needsRehash(Pin::hash('1234', 4), 11));
    assertTrue(!Pin::needsRehash(Pin::hash('1234', 11), 11));
});

// ---------------------------------------------------------------------------
// Signing in
// ---------------------------------------------------------------------------

test('correct credentials sign a user in', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-100', '4821');
        [$auth] = makeAuth();

        $result = $auth->attempt('m-100', '4821', false);

        assertTrue($result->ok, $result->message ?? '');
        assertSame($id, $result->identity?->id);
        assertSame('m-100', $result->identity?->memberId);
    });
});

test('a wrong PIN, an unknown Member ID and a disabled account answer alike', function (): void {
    inAuthRollback(function (Database $db): void {
        makeUser($db, 'm-101', '4821');
        makeUser($db, 'm-102', '4821', active: false);

        [$auth] = makeAuth();
        $wrongPin = $auth->attempt('m-101', '0000', false);
        $unknown = $auth->attempt('m-999', '4821', false);
        $disabled = $auth->attempt('m-102', '4821', false);

        foreach ([$wrongPin, $unknown, $disabled] as $result) {
            assertTrue(!$result->ok);
        }

        // Identical wording: anything else reports which Member IDs are real.
        assertSame($wrongPin->message, $unknown->message);
        assertSame($wrongPin->message, $disabled->message);
    });
});

test('a malformed PIN still counts as an attempt', function (): void {
    inAuthRollback(function (Database $db): void {
        makeUser($db, 'm-103', '4821');
        [$auth] = makeAuth();

        $auth->attempt('m-103', 'abcd', false);

        // Otherwise the limit is walked around by sending rubbish first.
        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM login_attempt WHERE succeeded = 0 AND member_id = 'm-103'"
        ));
    });
});

test('signing in records the attempt and writes an audit row', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-104', '4821');
        [$auth] = makeAuth();

        $auth->attempt('m-104', '4821', false);

        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM login_attempt WHERE succeeded = 1 AND member_id = 'm-104'"
        ));
        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'login' AND actor_id = :id",
            ['id' => $id]
        ));
    });
});

// ---------------------------------------------------------------------------
// Rate limiting (spec 3.2)
// ---------------------------------------------------------------------------

test('ten failures from one address lock it out, and others are unaffected', function (): void {
    inAuthRollback(function (Database $db): void {
        makeUser($db, 'm-105', '4821');
        [$auth] = makeAuth(ip: '203.0.113.9');

        for ($i = 0; $i < 9; $i++) {
            assertTrue(!$auth->attempt('m-105', '0000', false)->ok);
        }
        // Nine failures: the tenth attempt is still allowed through, and the
        // right PIN still works.
        assertSame(0, $auth->attempt('m-105', '0000', false)->lockoutSeconds);

        // Now at ten, the next attempt is refused before the PIN is examined —
        // even the correct one.
        $locked = $auth->attempt('m-105', '4821', false);
        assertTrue(!$locked->ok);
        assertTrue($locked->lockoutSeconds > 0 && $locked->lockoutSeconds <= 60, 'lockout window');

        // A different address is untouched.
        [$other] = makeAuth(ip: '203.0.113.10');
        assertTrue($other->attempt('m-105', '4821', false)->ok);
    });
});

test('the lockout lapses once the attempts age past the window', function (): void {
    inAuthRollback(function (Database $db): void {
        makeUser($db, 'm-106', '4821');
        [$auth] = makeAuth(ip: '203.0.113.11');

        for ($i = 0; $i < 10; $i++) {
            $auth->attempt('m-106', '0000', false);
        }
        assertTrue(!$auth->attempt('m-106', '4821', false)->ok, 'locked out');

        // Age every failure past the fifteen-minute window.
        $db->execute(
            "UPDATE login_attempt SET occurred_at = :old WHERE succeeded = 0 AND member_id = 'm-106'",
            ['old' => gmdate('Y-m-d H:i:s', time() - 1000)]
        );

        assertTrue($auth->attempt('m-106', '4821', false)->ok, 'let back in');
    });
});

// ---------------------------------------------------------------------------
// The remember-me token (spec 3.3)
// ---------------------------------------------------------------------------

test('keep me signed in issues a cookie; a plain sign-in does not', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-107', '4821');

        [$plain, $plainJar] = makeAuth();
        $plain->attempt('m-107', '4821', false);
        assertSame(null, $plainJar->get(Auth::COOKIE), 'no cookie without the box ticked');

        [$remembered, $jar] = makeAuth();
        $remembered->attempt('m-107', '4821', true);
        assertTrue($jar->get(Auth::COOKIE) !== null, 'cookie issued');

        // Both still get a server-side token, which is what makes a session
        // revocable at all.
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = :u',
            ['u' => $id]
        ));
        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = :u AND is_persistent = 1',
            ['u' => $id]
        ));
    });
});

test('the cookie carries a selector and a verifier, and stores only a hash', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-108', '4821');
        [$auth, $jar] = makeAuth();
        $auth->attempt('m-108', '4821', true);

        $cookie = (string) $jar->get(Auth::COOKIE);
        [$selector, $verifier] = explode('.', $cookie, 2);

        assertSame(32, strlen($selector), '16 random bytes, hex');
        assertSame(64, strlen($verifier), '32 random bytes, hex');

        $stored = $db->one(
            'SELECT selector, verifier_hash FROM auth_token WHERE user_id = :u',
            ['u' => $id]
        );
        assertSame($selector, $stored['selector']);
        assertTrue($stored['verifier_hash'] !== $verifier, 'the verifier itself is never stored');
        assertSame(hash('sha256', $verifier), $stored['verifier_hash']);
    });
});

test('a returning cookie signs the user back in and rotates', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-109', '4821');

        [$first, $jar] = makeAuth();
        $first->attempt('m-109', '4821', true);
        $original = (string) $jar->get(Auth::COOKIE);

        // A later visit: no PHP session left, only the cookie.
        $_SESSION = [];
        [$second] = makeAuth($jar);
        $user = $second->user();

        assertSame($id, $user?->id, 'signed back in from the cookie alone');
        assertTrue($jar->get(Auth::COOKIE) !== $original, 'rotated on use');
        assertSame(
            1,
            (int) $db->value('SELECT COUNT(*) FROM auth_token WHERE user_id = :u', ['u' => $id]),
            'rotated in place, not duplicated'
        );
    });
});

test('a rotated-away cookie no longer works', function (): void {
    inAuthRollback(function (Database $db): void {
        makeUser($db, 'm-110', '4821');

        [$first, $jar] = makeAuth();
        $first->attempt('m-110', '4821', true);
        $stale = (string) $jar->get(Auth::COOKIE);

        $_SESSION = [];
        [$second] = makeAuth($jar);
        $second->user();

        // Replay the pre-rotation cookie.
        $replay = new ArrayCookies();
        $replay->jar[Auth::COOKIE] = $stale;
        $_SESSION = [];
        [$third] = makeAuth($replay);

        assertSame(null, $third->user(), 'a spent cookie is refused');
    });
});

test('a mismatched verifier is refused without destroying the session', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-111', '4821');
        [$first, $jar] = makeAuth();
        $first->attempt('m-111', '4821', true);

        $cookie = (string) $jar->get(Auth::COOKIE);
        [$selector] = explode('.', $cookie, 2);

        $tampered = new ArrayCookies();
        $tampered->jar[Auth::COOKIE] = $selector . '.' . str_repeat('a', 64);
        $_SESSION = [];
        [$second] = makeAuth($tampered);

        assertSame(null, $second->user(), 'refused');

        // Deliberately NOT revoked: an in-flight request that lost a rotation
        // race lands here, and signing the team out mid-shift for that is the
        // worse failure. It is recorded instead.
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = :u AND revoked_at IS NOT NULL',
            ['u' => $id]
        ));
        assertSame(1, (int) $db->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'auth_token_verifier_mismatch' AND actor_id = :u",
            ['u' => $id]
        ));
    });
});

test('an unknown selector clears the dead cookie', function (): void {
    inAuthRollback(function (Database $db): void {
        $jar = new ArrayCookies();
        $jar->jar[Auth::COOKIE] = str_repeat('b', 32) . '.' . str_repeat('c', 64);
        [$auth] = makeAuth($jar);

        assertSame(null, $auth->user());
        assertSame(null, $jar->get(Auth::COOKIE), 'cleared');
    });
});

test('a revoked or expired token stops working', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-112', '4821');
        [$auth, $jar] = makeAuth();
        $auth->attempt('m-112', '4821', true);

        $db->execute('UPDATE auth_token SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :u', ['u' => $id]);
        $_SESSION = [];
        [$after] = makeAuth($jar);
        assertSame(null, $after->user(), 'revoked');

        $db->execute(
            'UPDATE auth_token SET revoked_at = NULL, expires_at = :past WHERE user_id = :u',
            ['past' => gmdate('Y-m-d H:i:s', time() - 60), 'u' => $id]
        );
        $_SESSION = [];
        [$expired] = makeAuth($jar);
        assertSame(null, $expired->user(), 'expired');
    });
});

test('signing out revokes the token and clears the cookie', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-113', '4821');
        [$auth, $jar] = makeAuth();
        $auth->attempt('m-113', '4821', true);

        $auth->logout();

        assertSame(null, $jar->get(Auth::COOKIE));
        assertSame(1, (int) $db->value(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = :u AND revoked_at IS NOT NULL',
            ['u' => $id]
        ));
    });
});

// ---------------------------------------------------------------------------
// Changing a PIN (spec 3.3)
// ---------------------------------------------------------------------------

test('changing a PIN keeps this device and signs the others out', function (): void {
    inAuthRollback(function (Database $db): void {
        $id = makeUser($db, 'm-114', '1234');

        // Same person signed in on a second phone, and on a tablet.
        [$other] = makeAuth();
        $other->attempt('m-114', '1234', true);
        $_SESSION = [];
        [$third] = makeAuth();
        $third->attempt('m-114', '1234', true);

        $_SESSION = [];
        [$here] = makeAuth();
        $current = $here->attempt('m-114', '1234', true)->identity;

        $result = $here->changePin($current, '1234', '9876', '9876');
        assertTrue($result->ok, $result->message ?? '');

        // This device's token survives; the other two are revoked.
        assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = :u AND id = :keep AND revoked_at IS NOT NULL',
            ['u' => $id, 'keep' => $current->tokenId]
        ));
        assertSame(2, (int) $db->value(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = :u AND revoked_at IS NOT NULL',
            ['u' => $id]
        ));

        // And the new PIN is the one that works.
        $_SESSION = [];
        [$fresh] = makeAuth(ip: '203.0.113.55');
        assertTrue($fresh->attempt('m-114', '9876', false)->ok);
        assertTrue(!$fresh->attempt('m-114', '1234', false)->ok);
    });
});

test('a PIN change is refused without the right current PIN', function (): void {
    inAuthRollback(function (Database $db): void {
        makeUser($db, 'm-115', '1234');
        [$auth] = makeAuth();
        $me = $auth->attempt('m-115', '1234', false)->identity;

        assertTrue(!$auth->changePin($me, '0000', '9876', '9876')->ok, 'wrong current PIN');
        assertTrue(!$auth->changePin($me, '1234', '987', '987')->ok, 'not four digits');
        assertTrue(!$auth->changePin($me, '1234', '9876', '9875')->ok, 'confirmation differs');

        // None of those changed anything.
        $_SESSION = [];
        [$check] = makeAuth(ip: '203.0.113.56');
        assertTrue($check->attempt('m-115', '1234', false)->ok);
    });
});
