<?php

declare(strict_types=1);

namespace Resm\Shift;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The shift visibility window (spec 5.3).
 *
 * A team can enter an assigned shift from 00:00 on the shift's start date
 * through 04:00 the following day. A shift running 16:45 on 1 March to 02:00
 * on 2 March is therefore reachable from 00:00 on 1 March to 04:00 on 2 March.
 *
 * The window is defined in local dates, not in elapsed hours, so it is
 * computed here in America/Chicago and compared as UTC instants. Not in SQL:
 * CONVERT_TZ needs the MySQL timezone tables, which are not loaded on this
 * host — measured — and a query that silently returns NULL for every row is
 * the worst way to find that out.
 */
final class Window
{
    /** Hours past midnight on the day after the start date. */
    private const CLOSES_AT_HOUR = 4;

    public function __construct(private DateTimeZone $local)
    {
    }

    /** When a shift starting at this instant becomes reachable. */
    public function opensFor(DateTimeImmutable $startsAtUtc): DateTimeImmutable
    {
        return $this->localMidnight($startsAtUtc);
    }

    /** When it stops being reachable. */
    public function closesFor(DateTimeImmutable $startsAtUtc): DateTimeImmutable
    {
        return $this->localMidnight($startsAtUtc)
            ->modify('+1 day')
            ->modify(sprintf('+%d hours', self::CLOSES_AT_HOUR));
    }

    public function contains(DateTimeImmutable $startsAtUtc, DateTimeImmutable $now): bool
    {
        return $now >= $this->opensFor($startsAtUtc) && $now <= $this->closesFor($startsAtUtc);
    }

    /**
     * How wide to cast the net when loading candidates.
     *
     * The window can begin nearly 24 hours before a shift starts and end 28
     * hours after that midnight, so a two-day reach either side of now finds
     * every shift that could possibly be in it — and a user has at most a
     * couple in that span. Filtering then happens in PHP, where the timezone
     * is real.
     *
     * @return array{0: string, 1: string} UTC bounds for a SQL BETWEEN
     */
    public static function searchBounds(DateTimeImmutable $now): array
    {
        return [
            $now->modify('-2 days')->format('Y-m-d H:i:s'),
            $now->modify('+2 days')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Midnight, local time, on the date this instant falls on locally.
     *
     * Built from the date parts rather than by subtracting a time-of-day: on
     * the night the clocks change the day is 23 hours long, and arithmetic
     * lands an hour out.
     */
    private function localMidnight(DateTimeImmutable $utc): DateTimeImmutable
    {
        $localDate = $utc->setTimezone($this->local)->format('Y-m-d');

        return new DateTimeImmutable($localDate . ' 00:00:00', $this->local);
    }
}
