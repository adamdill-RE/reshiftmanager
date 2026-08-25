<?php

declare(strict_types=1);

namespace Resm;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turning a local date and two times of day into the UTC instants a shift is
 * stored as.
 *
 * This is its own class because it is the one piece of shift creation that is
 * genuinely subtle, and it is wrong in a way nobody notices until a shift runs.
 * Three things it has to get right:
 *
 *   A shift that ends earlier in the day than it starts ends the NEXT day.
 *   16:45–02:00 is one night, not a negative fifteen hours.
 *
 *   The season crosses the second Sunday in March. On that night the local
 *   clock jumps 02:00 to 03:00, so 02:00 does not exist and a weeknight shift
 *   ends at the instant the clock reads 03:00. That is operationally correct —
 *   the buses stop at the same moment either way — but the board will read a
 *   time the administrator did not type, so it is reported rather than hidden.
 *
 *   Everything is stored UTC. Never a fixed offset: half the season is CST and
 *   half is CDT, and a -6 baked into a query is a shift an hour out of place
 *   for the back half of March.
 */
final class ShiftClock
{
    public function __construct(private DateTimeZone $local)
    {
    }

    /**
     * @return array{
     *     ok: bool, error: ?string,
     *     start: ?DateTimeImmutable, end: ?DateTimeImmutable, adjusted: ?string
     * } start and end in UTC; adjusted names a time the clock change moved.
     */
    public function resolve(string $date, string $startTime, string $endTime): array
    {
        $start = $this->localMoment($date, $startTime);
        if ($start === null) {
            return self::fail('That start date or time is not a real one.');
        }

        $end = $this->localMoment($date, $endTime);
        if ($end === null) {
            return self::fail('That end time is not a real one.');
        }

        // 16:45 to 02:00 is one night. Comparing the requested times rather
        // than the resolved instants keeps this decision away from the DST
        // roll below, which could otherwise turn 02:00 into 03:00 first and
        // change the answer.
        if (self::minutes($endTime) <= self::minutes($startTime)) {
            $end = $this->localMoment($date, $endTime, plusDays: 1);
            if ($end === null) {
                return self::fail('That end time is not a real one.');
            }
        }

        // A local time inside the spring-forward gap does not exist, and PHP
        // resolves it to the same instant one hour later rather than failing.
        // Say so: the administrator typed 02:00 and the board will say 03:00.
        $adjusted = null;
        foreach ([[$startTime, $start], [$endTime, $end]] as [$wanted, $moment]) {
            $got = $moment->setTimezone($this->local)->format('H:i');
            if ($got !== $wanted) {
                $adjusted = sprintf('%s becomes %s', $wanted, $got);
            }
        }

        if ($end <= $start) {
            return self::fail('A shift has to end after it starts.');
        }

        return [
            'ok' => true,
            'error' => null,
            'start' => $start->setTimezone(new DateTimeZone('UTC')),
            'end' => $end->setTimezone(new DateTimeZone('UTC')),
            'adjusted' => $adjusted,
        ];
    }

    /** Format a stored UTC timestamp the way the tarmac reads it. */
    public function display(DateTimeImmutable $utc, string $format = 'D j M, H:i'): string
    {
        return $utc->setTimezone($this->local)->format($format);
    }

    /**
     * Every date in an inclusive range whose weekday is wanted.
     *
     * Weekdays are ISO numbers, 1 (Monday) to 7 (Sunday). Dates, not instants:
     * "every Tuesday in March" is a calendar question, and answering it in UTC
     * would drop or double a day around the clock change.
     *
     * @param array<int, int> $weekdays
     * @return array<int, string> Y-m-d
     */
    public function datesInRange(string $from, string $to, array $weekdays, int $limit): array
    {
        $start = self::parseDate($from);
        $end = self::parseDate($to);
        if ($start === null || $end === null || $end < $start) {
            return [];
        }

        $wanted = array_flip(array_map('intval', $weekdays));
        $dates = [];
        $cursor = $start;

        while ($cursor <= $end && count($dates) < $limit) {
            if ($wanted === [] || isset($wanted[(int) $cursor->format('N')])) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    public static function parseDate(string $date): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        // createFromFormat turns 2099-02-31 into 2099-03-03 without complaint,
        // so the parse is compared against its own round trip.
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    public static function isValidTime(string $time): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    /** Minutes since local midnight, for ordering two times of day. */
    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function localMoment(string $date, string $time, int $plusDays = 0): ?DateTimeImmutable
    {
        if (!self::isValidTime($time)) {
            return null;
        }

        $day = self::parseDate($date);
        if ($day === null) {
            return null;
        }
        if ($plusDays !== 0) {
            $day = $day->modify(sprintf('%+d day', $plusDays));
        }

        $moment = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $day->format('Y-m-d') . ' ' . $time,
            $this->local
        );

        return $moment === false ? null : $moment;
    }

    /** @return array{ok: false, error: string, start: null, end: null, adjusted: null} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'start' => null, 'end' => null, 'adjusted' => null];
    }
}
