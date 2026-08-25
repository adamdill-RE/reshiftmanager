<?php

declare(strict_types=1);

use Resm\ShiftClock;
use Resm\ShiftType;

/**
 * Local dates and times into stored UTC (spec 5.1).
 *
 * The March clock change is the whole reason this class exists, so it is what
 * most of these test. 2027's transition is 02:00 CST on Sunday 14 March, which
 * is inside the rodeo.
 */
function clock(): ShiftClock
{
    return new ShiftClock(new DateTimeZone('America/Chicago'));
}

test('an ordinary weeknight stores as UTC and reads back unchanged', function (): void {
    $r = clock()->resolve('2027-03-05', '16:45', '02:00');

    assertTrue($r['ok'], (string) $r['error']);
    assertSame('2027-03-05 22:45', $r['start']->format('Y-m-d H:i'));
    assertSame('2027-03-06 08:00', $r['end']->format('Y-m-d H:i'));
    assertSame('UTC', $r['start']->getTimezone()->getName());
    assertSame(null, $r['adjusted'], 'nothing was moved on an ordinary night');
});

test('an end time earlier than the start belongs to the next day', function (): void {
    // 16:45-02:00 is one night, not minus fifteen hours.
    $r = clock()->resolve('2027-03-05', '16:45', '02:00');

    assertSame(9 * 60 + 15, (int) round(($r['end']->getTimestamp() - $r['start']->getTimestamp()) / 60));
});

test('a weekend day shift stays inside one date', function (): void {
    $r = clock()->resolve('2027-03-06', '08:00', '18:00');

    assertTrue($r['ok']);
    assertSame('2027-03-06 14:00', $r['start']->format('Y-m-d H:i'), 'CST is UTC-6');
    assertSame('2027-03-07 00:00', $r['end']->format('Y-m-d H:i'));
});

test('the clock change is reported, not hidden', function (): void {
    // 02:00 does not exist on 14 March 2027 — the clock goes 01:59 to 03:00.
    // The shift still ends at the right instant; the board just reads 03:00,
    // which is not what the administrator typed.
    $r = clock()->resolve('2027-03-13', '16:45', '02:00');

    assertTrue($r['ok'], (string) $r['error']);
    assertSame('2027-03-14 08:00', $r['end']->format('Y-m-d H:i'));
    assertTrue($r['adjusted'] !== null, 'the roll must be reported');
    assertTrue(str_contains((string) $r['adjusted'], '03:00'), "got: {$r['adjusted']}");
});

test('the night of the clock change is the same length in UTC as any other', function (): void {
    // It loses an hour of wall clock and none of actual time, because 02:00
    // CST and 03:00 CDT are the same instant.
    $normal = clock()->resolve('2027-03-05', '16:45', '02:00');
    $springForward = clock()->resolve('2027-03-13', '16:45', '02:00');

    $length = static fn (array $r): int => $r['end']->getTimestamp() - $r['start']->getTimestamp();
    assertSame($length($normal), $length($springForward));
});

test('the day after the change is an hour different in UTC', function (): void {
    // The proof that a fixed -6 offset would be wrong for half the season.
    $before = clock()->resolve('2027-03-06', '08:00', '18:00');
    $after = clock()->resolve('2027-03-14', '08:00', '18:00');

    assertSame('2027-03-06 14:00', $before['start']->format('Y-m-d H:i'), 'CST');
    assertSame('2027-03-14 13:00', $after['start']->format('Y-m-d H:i'), 'CDT');
});

test('impossible dates and times are refused rather than rolled forward', function (): void {
    $clock = clock();

    foreach ([['2027-02-31', '16:45', '02:00'], ['not-a-date', '16:45', '02:00']] as [$d, $s, $e]) {
        assertTrue(!$clock->resolve($d, $s, $e)['ok'], "{$d} was accepted");
    }
    foreach (['24:00', '16:60', '4:45', '', 'evening'] as $bad) {
        assertTrue(!$clock->resolve('2027-03-05', $bad, '02:00')['ok'], "start '{$bad}' was accepted");
        assertTrue(!$clock->resolve('2027-03-05', '16:45', $bad)['ok'], "end '{$bad}' was accepted");
    }
});

test('a shift cannot start and end at the same moment', function (): void {
    // Equal times would otherwise be read as "next day" and silently become a
    // 24-hour shift.
    $r = clock()->resolve('2027-03-05', '16:45', '16:45');
    assertTrue($r['ok'], 'equal times mean a full day, which is legal if odd');
    assertSame(24 * 60, (int) round(($r['end']->getTimestamp() - $r['start']->getTimestamp()) / 60));
});

test('a date range yields only the weekdays asked for', function (): void {
    // 1-7 March 2027 is Monday to Sunday.
    $dates = clock()->datesInRange('2027-03-01', '2027-03-07', [6, 7], 400);

    assertSame(['2027-03-06', '2027-03-07'], $dates);
});

test('a date range spanning the clock change loses no day', function (): void {
    // Answered as calendar dates, not instants — in UTC the change would drop
    // or double one.
    $dates = clock()->datesInRange('2027-03-08', '2027-03-21', [], 400);

    assertCount(14, $dates);
    assertSame('2027-03-14', $dates[6], 'the short day is still one day');
});

test('a date range is capped and refuses a backwards one', function (): void {
    $clock = clock();

    assertCount(10, $clock->datesInRange('2027-01-01', '2027-12-31', [], 10));
    assertSame([], $clock->datesInRange('2027-03-10', '2027-03-01', [], 400));
});

test('shift types carry the hours and opening phase from spec 5.1', function (): void {
    assertSame('16:45', ShiftType::Weeknight->defaultStart());
    assertSame('02:00', ShiftType::Weeknight->defaultEnd());
    assertSame('unload', ShiftType::Weeknight->defaultPhase());

    assertSame('08:00', ShiftType::WeekendDay->defaultStart());
    assertSame('18:00', ShiftType::WeekendDay->defaultEnd());
    assertSame('unload', ShiftType::WeekendDay->defaultPhase());

    // The crowd departs early, so the team holds departure positions all night.
    assertSame('bump_run', ShiftType::WeekendNight->defaultPhase());
    assertSame('16:45', ShiftType::WeekendNight->defaultStart());
});

test('every shift type default is a time this class accepts', function (): void {
    foreach (ShiftType::all() as $type) {
        assertTrue(ShiftClock::isValidTime($type->defaultStart()), $type->value . ' start');
        assertTrue(ShiftClock::isValidTime($type->defaultEnd()), $type->value . ' end');
        assertTrue(clock()->resolve('2027-03-05', $type->defaultStart(), $type->defaultEnd())['ok']);
    }
});
