<?php

declare(strict_types=1);

namespace Resm\Auth;

/**
 * The 4-digit PIN (spec 3.1).
 *
 * A 4-digit PIN is 10,000 possibilities — low entropy whatever the algorithm,
 * and no hash changes that. Rate limiting does the real work of stopping
 * guessing; the hash is what protects the roster if the database ever leaks.
 * That is the whole security argument, and it is worth being honest about
 * rather than implying bcrypt makes a PIN strong.
 *
 * bcrypt rather than argon2id, which is stronger but defaults to 64MB of
 * memory per hash against a 128MB memory_limit and a CloudLinux LVE cap on the
 * account. Shift start is exactly when 25-100 people sign in within a few
 * minutes, so concurrent verification is the expected case (docs/hosting.md).
 */
final class Pin
{
    public const LENGTH = 4;

    /**
     * Verified against when the Member ID does not exist, so that a wrong
     * username and a wrong PIN take about the same time to answer. Without it
     * the login form quietly reports which Member IDs are real.
     *
     * This is bcrypt cost 11 over a random string that was never recorded.
     */
    private const DUMMY_HASH = '$2y$11$/pUkp5IwNjQzOXpe6h8e9OFDx8R3kUL2K0p93lN5va4GjCMCKhs5G';

    /** Exactly four digits. Not "numeric" — "0123" and "1e3" are different things. */
    public static function isValid(string $pin): bool
    {
        return preg_match('/^\d{4}$/', $pin) === 1;
    }

    public static function hash(string $pin, int $cost): string
    {
        return password_hash($pin, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public static function verify(string $pin, string $hash): bool
    {
        return password_verify($pin, $hash);
    }

    /**
     * Burn roughly the time a real verification would take, for a Member ID
     * that does not exist. The result is discarded; only the elapsed time
     * matters.
     */
    public static function burnTime(string $pin): void
    {
        password_verify($pin, self::DUMMY_HASH);
    }

    /** True when the stored hash predates a change in algorithm or cost. */
    public static function needsRehash(string $hash, int $cost): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}
