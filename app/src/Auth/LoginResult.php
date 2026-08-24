<?php

declare(strict_types=1);

namespace Resm\Auth;

/**
 * The outcome of a sign-in or PIN change, with a message already phrased for
 * someone standing outside in the rain.
 */
final class LoginResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?Identity $identity,
        public readonly ?string $message,
        public readonly int $lockoutSeconds = 0,
    ) {
    }

    public static function success(Identity $identity): self
    {
        return new self(true, $identity, null);
    }

    /**
     * One message for a wrong Member ID and a wrong PIN alike. Saying which
     * was wrong would let anyone read off which Member IDs exist, and it helps
     * a legitimate user not at all — the fix is the same either way.
     */
    public static function invalid(): self
    {
        return new self(false, null, 'That Member ID and PIN did not match. Try again, or see an officer.');
    }

    public static function lockedOut(int $seconds): self
    {
        return new self(
            false,
            null,
            sprintf('Too many attempts. Try again in %d second%s.', $seconds, $seconds === 1 ? '' : 's'),
            $seconds
        );
    }

    public static function failed(string $message): self
    {
        return new self(false, null, $message);
    }

    public static function changed(): self
    {
        return new self(true, null, null);
    }
}
