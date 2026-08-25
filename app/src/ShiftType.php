<?php

declare(strict_types=1);

namespace Resm;

/**
 * The three shift types from spec 5.1, with the hours and opening phase each
 * one runs by default.
 *
 * Times are local (America/Chicago) times of day, not instants. Turning them
 * into stored UTC is ShiftClock's job, because the season crosses the March
 * DST change and 16:45–02:00 is not the same number of hours on every night.
 */
enum ShiftType: string
{
    case Weeknight = 'weeknight';
    case WeekendDay = 'weekend_day';
    case WeekendNight = 'weekend_night';

    public function label(): string
    {
        return match ($this) {
            self::Weeknight => 'Weeknight',
            self::WeekendDay => 'Weekend Day',
            self::WeekendNight => 'Weekend Night',
        };
    }

    /** Local time of day, HH:MM. */
    public function defaultStart(): string
    {
        return match ($this) {
            self::Weeknight, self::WeekendNight => '16:45',
            self::WeekendDay => '08:00',
        };
    }

    /** Local time of day, HH:MM. Earlier than the start means the next day. */
    public function defaultEnd(): string
    {
        return match ($this) {
            self::Weeknight, self::WeekendNight => '02:00',
            self::WeekendDay => '18:00',
        };
    }

    /**
     * The phase the shift opens in (spec 5.1).
     *
     * Weekend Night goes straight to Bump and Run: the crowd departs early, so
     * the team eats on arrival and holds departure positions all night. The
     * toggle is never hard-locked afterwards — weather does what it wants.
     */
    public function defaultPhase(): string
    {
        return $this === self::WeekendNight ? 'bump_run' : 'unload';
    }

    public function summary(): string
    {
        return sprintf(
            '%s–%s, opens in %s',
            $this->defaultStart(),
            $this->defaultEnd(),
            $this->defaultPhase() === 'bump_run' ? 'Bump and Run' : 'Unload'
        );
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
