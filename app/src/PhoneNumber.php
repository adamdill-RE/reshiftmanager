<?php

declare(strict_types=1);

namespace Resm;

/**
 * Phone numbers, normalised for tel: links (spec 6.10.3).
 *
 * Two values are kept for every number: whatever was typed or imported, shown
 * as-is so a committeeman recognises his own number, and an E.164 form for the
 * tap-to-call links an officer uses on the tarmac.
 *
 * Normalising is best-effort by design. A number this cannot make sense of
 * returns null rather than a guess: the original is still displayed, and a
 * tel: link that dials the wrong person is worse than no link at all.
 *
 * Assumes North America when no country code is present, which is what a
 * Houston volunteer roster contains.
 */
final class PhoneNumber
{
    /** Returns E.164 (+15551234567), or null when the input cannot be trusted. */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        // An extension makes a number undiallable as a single link. Drop it and
        // keep the main number, which is the part tap-to-call needs.
        $value = (string) preg_replace('/\s*(?:x|ext\.?|extension)\s*\d+\s*$/i', '', $value);

        $international = str_starts_with($value, '+');
        $digits = (string) preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return null;
        }

        if ($international) {
            // Already carries a country code; trust it within E.164's limits.
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
        }

        // 5551234567
        if (strlen($digits) === 10) {
            return self::isPlausibleNanp($digits) ? '+1' . $digits : null;
        }

        // 15551234567, or 1 (555) 123-4567
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $national = substr($digits, 1);

            return self::isPlausibleNanp($national) ? '+' . $digits : null;
        }

        // Anything else - a 7-digit local number, a typo, a note in the phone
        // column - is not something to guess at.
        return null;
    }

    /**
     * North American numbering: area code and exchange both start 2-9. This
     * rejects the placeholders that turn up in real rosters - 0000000000,
     * 1234567890 - rather than minting tel: links for them.
     */
    private static function isPlausibleNanp(string $tenDigits): bool
    {
        return preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $tenDigits) === 1;
    }
}
