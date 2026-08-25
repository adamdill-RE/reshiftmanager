<?php

declare(strict_types=1);

use Resm\PhoneNumber;

test('common written forms all normalise to the same number', function (): void {
    foreach ([
        '713-555-0142',
        '(713) 555-0142',
        '713.555.0142',
        '7135550142',
        ' 713 555 0142 ',
        '1-713-555-0142',
        '+1 713 555 0142',
    ] as $written) {
        assertSame('+17135550142', PhoneNumber::normalise($written), "from '{$written}'");
    }
});

test('an extension is dropped, keeping a diallable number', function (): void {
    // A tel: link cannot dial an extension, and the original is still shown.
    assertSame('+17135550142', PhoneNumber::normalise('713-555-0142 x204'));
    assertSame('+17135550142', PhoneNumber::normalise('713-555-0142 ext. 204'));
});

test('an international number keeps its own country code', function (): void {
    assertSame('+442071234567', PhoneNumber::normalise('+44 20 7123 4567'));
});

test('nonsense returns null rather than a guess', function (): void {
    // The original is still displayed; a tel: link to the wrong person is
    // worse than no link.
    foreach (['', '   ', 'none', 'ask Bob', '555-0142', '12345', null] as $bad) {
        assertSame(null, PhoneNumber::normalise($bad), 'from ' . var_export($bad, true));
    }
});

test('placeholder numbers are refused', function (): void {
    // These turn up in real rosters and must not become tap-to-call links.
    assertSame(null, PhoneNumber::normalise('000-000-0000'));
    assertSame(null, PhoneNumber::normalise('123-456-7890'), 'area codes do not start with 1');
    assertSame(null, PhoneNumber::normalise('713-155-0142'), 'exchanges do not start with 1');
});
