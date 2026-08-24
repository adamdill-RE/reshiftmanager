<?php

declare(strict_types=1);

namespace Resm\Auth;

use Resm\App;
use Resm\AuditLog;
use Resm\Csrf;
use Resm\Database;
use Resm\Http\CookieStore;
use Resm\Http\Cookies;
use Resm\Http\Request;
use Resm\Session;

/**
 * Sign-in, sign-out, and resolving who is making this request.
 *
 * Two things hold the session together, and they do different jobs:
 *
 *   The PHP session is short. gc_maxlifetime here is 1440 seconds and garbage
 *   collection belongs to the host, so nothing durable can live in it. It
 *   holds one thing: the id of the auth_token row for this sign-in.
 *
 *   The auth_token row is the real session. Because it is server-side, a
 *   session can be revoked — which is what makes "changing a PIN invalidates
 *   all other sessions" (spec 3.3) take effect immediately rather than
 *   whenever PHP happens to collect a session file. Every sign-in gets one,
 *   whether or not "keep me signed in" was ticked; that box decides the
 *   lifetime and whether a cookie is issued, nothing else.
 */
final class Auth
{
    /** Scoped to /resm/ with the same flags as the session cookie. */
    public const COOKIE = 'resm_remember';

    private ?Identity $identity = null;
    private bool $resolved = false;

    private Database $db;
    private RateLimiter $limiter;
    private AuditLog $audit;
    private CookieStore $cookies;

    public function __construct(
        private App $app,
        private Request $request,
        ?CookieStore $cookies = null,
    ) {
        $this->cookies = $cookies ?? new Cookies(
            $app->basePath(),
            $app->config->bool('session.secure', true)
        );
        $this->db = $app->db();
        $this->audit = new AuditLog($this->db);
        $this->limiter = new RateLimiter(
            $this->db,
            $app->config->int('auth.lockout_attempts', 10),
            $app->config->int('auth.lockout_window_seconds', 900),
            $app->config->int('auth.lockout_seconds', 60),
        );
    }

    // -----------------------------------------------------------------------
    // Who is asking
    // -----------------------------------------------------------------------

    public function user(): ?Identity
    {
        if ($this->resolved) {
            return $this->identity;
        }
        $this->resolved = true;

        $tokenId = Session::get('auth_token_id');
        if (is_int($tokenId)) {
            $this->identity = $this->identityForToken($tokenId);
            if ($this->identity !== null) {
                return $this->identity;
            }
            // The token was revoked or expired under us — a PIN change on
            // another device, or simply time.
            Session::forget('auth_token_id');
        }

        $this->identity = $this->resumeFromCookie();

        return $this->identity;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    // -----------------------------------------------------------------------
    // Signing in
    // -----------------------------------------------------------------------

    public function attempt(string $memberId, string $pin, bool $remember): LoginResult
    {
        $ip = $this->request->ipBinary();

        $locked = $this->limiter->lockedFor($ip);
        if ($locked > 0) {
            return LoginResult::lockedOut($locked);
        }

        $memberId = trim($memberId);

        // A malformed PIN still counts as an attempt. Otherwise the limit is
        // trivially avoided by sending rubbish until the real guessing starts.
        if ($memberId === '' || !Pin::isValid($pin)) {
            Pin::burnTime($pin);
            $this->limiter->record($ip, $memberId === '' ? null : $memberId, false);

            return LoginResult::invalid();
        }

        $row = $this->db->one(
            'SELECT id, member_id, first_name, last_name, role, is_active, pin_hash
             FROM `user` WHERE member_id = :member_id',
            ['member_id' => $memberId]
        );

        // An unknown Member ID and a deactivated account both answer exactly
        // as a wrong PIN does, after spending the same time.
        if ($row === null || (int) $row['is_active'] !== 1) {
            Pin::burnTime($pin);
            $this->limiter->record($ip, $memberId, false);

            return LoginResult::invalid();
        }

        if (!Pin::verify($pin, (string) $row['pin_hash'])) {
            $this->limiter->record($ip, $memberId, false);

            return LoginResult::invalid();
        }

        $userId = (int) $row['id'];
        $cost = $this->app->config->int('auth.pin_cost', 11);

        // Picks up a cost increase without anyone having to reset PINs.
        if (Pin::needsRehash((string) $row['pin_hash'], $cost)) {
            $this->db->execute(
                'UPDATE `user` SET pin_hash = :hash WHERE id = :id',
                ['hash' => Pin::hash($pin, $cost), 'id' => $userId]
            );
        }

        $this->limiter->record($ip, $memberId, true);

        // A new session id and a new CSRF token, so nothing issued before the
        // user was known carries over into their signed-in session.
        Session::regenerate();
        Csrf::rotate();

        $identity = $this->startSession($userId, $remember);

        $this->audit->record($userId, 'login', 'user', $userId, null, [
            'persistent' => $remember,
        ]);

        return LoginResult::success($identity);
    }

    public function logout(): void
    {
        $identity = $this->user();

        if ($identity !== null) {
            $this->db->execute(
                'UPDATE auth_token SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL',
                ['now' => gmdate('Y-m-d H:i:s'), 'id' => $identity->tokenId]
            );
            $this->audit->record($identity->id, 'logout', 'user', $identity->id);
        }

        $this->clearCookie();
        Session::destroy($this->app->config);

        $this->identity = null;
        $this->resolved = true;
    }

    // -----------------------------------------------------------------------
    // Changing a PIN
    // -----------------------------------------------------------------------

    /**
     * Spec 3.3: changing a PIN invalidates every other session for that
     * account and keeps the current device signed in. Someone who suspects a
     * shoulder-surfer changes their PIN and is done, without an officer and
     * without losing the phone in their hand.
     */
    public function changePin(Identity $user, string $current, string $new, string $confirm): LoginResult
    {
        $row = $this->db->one('SELECT pin_hash FROM `user` WHERE id = :id', ['id' => $user->id]);
        if ($row === null) {
            return LoginResult::failed('That account is no longer available.');
        }

        if (!Pin::verify($current, (string) $row['pin_hash'])) {
            return LoginResult::failed('Your current PIN was not right.');
        }

        if (!Pin::isValid($new)) {
            return LoginResult::failed('A PIN is exactly four digits.');
        }

        if (!hash_equals($new, $confirm)) {
            return LoginResult::failed('The two new PINs did not match.');
        }

        $cost = $this->app->config->int('auth.pin_cost', 11);
        $now = gmdate('Y-m-d H:i:s');

        $this->db->transaction(function (Database $db) use ($user, $new, $cost, $now): void {
            $db->execute(
                'UPDATE `user` SET pin_hash = :hash, pin_changed_at = :now WHERE id = :id',
                ['hash' => Pin::hash($new, $cost), 'now' => $now, 'id' => $user->id]
            );

            $db->execute(
                'UPDATE auth_token SET revoked_at = :now
                 WHERE user_id = :user_id AND id <> :keep AND revoked_at IS NULL',
                ['now' => $now, 'user_id' => $user->id, 'keep' => $user->tokenId]
            );
        });

        Csrf::rotate();
        $this->audit->record($user->id, 'pin_change', 'user', $user->id);

        return LoginResult::changed();
    }

    // -----------------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------------

    private function startSession(int $userId, bool $persistent): Identity
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $now = time();

        $lifetime = $persistent
            ? $this->app->config->int('auth.remember_days', 90) * 86400
            // Not persistent: the token is reachable only through the PHP
            // session, which the host collects long before this. A shift's
            // length is a generous ceiling.
            : 12 * 3600;

        $this->db->execute(
            'INSERT INTO auth_token
                (user_id, selector, verifier_hash, is_persistent, issued_at, last_used_at, expires_at, user_agent, ip)
             VALUES (:user_id, :selector, :verifier_hash, :is_persistent, :issued_at, :last_used_at, :expires_at, :user_agent, :ip)',
            [
                'user_id'       => $userId,
                'selector'      => $selector,
                'verifier_hash' => self::hashVerifier($verifier),
                'is_persistent' => $persistent ? 1 : 0,
                // Named placeholders are not reusable: emulated prepares are
                // off, so PDO maps each name to one positional marker.
                'issued_at'     => gmdate('Y-m-d H:i:s', $now),
                'last_used_at'  => gmdate('Y-m-d H:i:s', $now),
                'expires_at'    => gmdate('Y-m-d H:i:s', $now + $lifetime),
                'user_agent'    => $this->request->userAgent(),
                'ip'            => $this->request->ipBinary(),
            ]
        );

        $tokenId = $this->db->lastInsertId();
        Session::set('auth_token_id', $tokenId);

        if ($persistent) {
            $this->sendCookie($selector, $verifier, $now + $lifetime);
        }

        $identity = $this->identityForToken($tokenId);
        if ($identity === null) {
            // Only reachable if the account was deactivated between the PIN
            // check and here.
            throw new AccessDenied('the account became unavailable during sign-in');
        }

        return $identity;
    }

    private function resumeFromCookie(): ?Identity
    {
        $raw = $this->cookies->get(self::COOKIE);
        if ($raw === null || substr_count($raw, '.') !== 1) {
            return null;
        }

        [$selector, $verifier] = explode('.', $raw, 2);
        if (strlen($selector) !== 32 || ctype_xdigit($selector) === false) {
            return null;
        }

        $row = $this->db->one(
            'SELECT id, user_id, verifier_hash, is_persistent, expires_at, revoked_at
             FROM auth_token WHERE selector = :selector',
            ['selector' => $selector]
        );

        // No such token: this cookie is dead for certain, so clear it.
        if ($row === null) {
            $this->clearCookie();

            return null;
        }

        if ($row['revoked_at'] !== null || strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            $this->clearCookie();

            return null;
        }

        if (!hash_equals((string) $row['verifier_hash'], self::hashVerifier($verifier))) {
            // A known selector with the wrong verifier is the textbook theft
            // signal, and the textbook response is to revoke the whole family.
            // Not here. A request already in flight when another rotated the
            // cookie lands in exactly this branch, and revoking would sign the
            // team out mid-shift for a race the user cannot see or avoid.
            // Given a 4-digit PIN and a volunteer shift tool, an availability
            // failure at 17:00 is the worse of the two — the same trade the
            // spec makes for the login rate limit. Refuse this cookie for this
            // request, keep it, and note it.
            $this->audit->record(
                (int) $row['user_id'],
                'auth_token_verifier_mismatch',
                'auth_token',
                (int) $row['id']
            );

            return null;
        }

        $tokenId = (int) $row['id'];
        $identity = $this->identityForToken($tokenId);
        if ($identity === null) {
            $this->clearCookie();

            return null;
        }

        $this->rotate($tokenId, (string) $row['verifier_hash'], (bool) $row['is_persistent']);

        // The cookie has just established a new signed-in session, so give it
        // a fresh session id rather than adopting whatever id arrived.
        Session::regenerate();
        Session::set('auth_token_id', $tokenId);

        return $identity;
    }

    /**
     * Roll the selector and verifier, and push the expiry out — this is what
     * makes the 90 days rolling rather than fixed.
     *
     * The update is a compare-and-swap on the old verifier, and only the
     * request that wins sets a cookie. Two requests arriving together after
     * the app has been backgrounded both read the same valid row; one rotation
     * lands, the other affects no rows and quietly leaves the cookie alone.
     * The browser therefore always ends up holding whatever the database
     * holds, whichever response arrives last.
     */
    private function rotate(int $tokenId, string $currentVerifierHash, bool $persistent): void
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $now = time();

        $lifetime = $persistent
            ? $this->app->config->int('auth.remember_days', 90) * 86400
            : 12 * 3600;

        $updated = $this->db->execute(
            'UPDATE auth_token
             SET selector = :selector, verifier_hash = :verifier_hash,
                 last_used_at = :now, expires_at = :expires_at
             WHERE id = :id AND verifier_hash = :current AND revoked_at IS NULL',
            [
                'selector'      => $selector,
                'verifier_hash' => self::hashVerifier($verifier),
                'now'           => gmdate('Y-m-d H:i:s', $now),
                'expires_at'    => gmdate('Y-m-d H:i:s', $now + $lifetime),
                'id'            => $tokenId,
                'current'       => $currentVerifierHash,
            ]
        );

        if ($updated === 1 && $persistent) {
            $this->sendCookie($selector, $verifier, $now + $lifetime);
        }
    }

    private function identityForToken(int $tokenId): ?Identity
    {
        $row = $this->db->one(
            'SELECT t.id AS token_id, u.id, u.member_id, u.first_name, u.last_name, u.role, u.is_active
             FROM auth_token t
             JOIN `user` u ON u.id = t.user_id
             WHERE t.id = :id AND t.revoked_at IS NULL AND t.expires_at > :now',
            ['id' => $tokenId, 'now' => gmdate('Y-m-d H:i:s')]
        );

        if ($row === null || (int) $row['is_active'] !== 1) {
            return null;
        }

        $teams = $this->db->all(
            'SELECT team_id FROM team_member WHERE user_id = :user_id',
            ['user_id' => (int) $row['id']]
        );

        return new Identity(
            id: (int) $row['id'],
            memberId: $row['member_id'] === null ? null : (string) $row['member_id'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            role: Role::from((string) $row['role']),
            isActive: true,
            tokenId: (int) $row['token_id'],
            teamIds: array_map(static fn (array $t): int => (int) $t['team_id'], $teams),
        );
    }

    /** SHA-256, not password_hash: the verifier is 32 random bytes, not a
     *  memorable secret, so there is nothing for a slow hash to defend and
     *  bcrypt on every request would cost real time at shift start. */
    private static function hashVerifier(string $verifier): string
    {
        return hash('sha256', $verifier);
    }

    private function sendCookie(string $selector, string $verifier, int $expiresAt): void
    {
        $this->cookies->set(self::COOKIE, $selector . '.' . $verifier, $expiresAt);
    }

    private function clearCookie(): void
    {
        $this->cookies->forget(self::COOKIE);
    }
}
