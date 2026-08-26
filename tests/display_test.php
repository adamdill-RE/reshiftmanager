<?php

declare(strict_types=1);

/**
 * Theme and Large Text (spec 9.2, 9.3).
 *
 * These read the layout and the stylesheet as text, which is not how most of
 * this suite works and is deliberate. What they protect is an ORDERING, and
 * the symptom of breaking it is a white flash lasting one frame — invisible to
 * any assertion about behaviour, invisible in a screenshot taken after load,
 * and thoroughly visible to a man on a dark tarmac at 02:00, which is the
 * entire reason spec 9.2 makes the dark theme a requirement.
 */

function layoutSource(): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/app/views/layout.php');
}

function stylesheetSource(): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
}

test('theme.js is in the head and is not deferred', function (): void {
    $layout = layoutSource();
    $head = substr($layout, 0, strpos($layout, '</head>') ?: 0);

    assertTrue(str_contains($head, 'js/theme.js'), 'theme.js must be in the head');

    // Everything else in this app is deferred. This one cannot be: deferred
    // scripts run after the document is parsed, so a pinned dark theme would
    // be applied one frame after a white page had already been painted.
    $tag = substr($head, strpos($head, 'js/theme.js') - 200, 300);
    assertTrue(!str_contains($tag, 'defer'), 'theme.js must not be deferred — it would flash');
    assertTrue(!str_contains($tag, 'async'), 'theme.js must not be async — the timing is the point');
});

test('the deferred bundle does not include theme.js', function (): void {
    // The layout appends poll.js and freshness.js to $scripts, which are all
    // rendered with defer. theme.js going through that list is exactly how the
    // ordering above gets undone by a tidy-up.
    $layout = layoutSource();
    $tail = substr($layout, strpos($layout, '</head>') ?: 0);

    assertTrue(!str_contains($tail, 'js/theme.js'), 'theme.js must not be in the deferred list');
});

test('the stylesheet carries both pinned states and the device default', function (): void {
    $css = stylesheetSource();

    // Pinned, both directions. Dark alone is not enough: someone whose phone
    // is dark and who wants light has to be able to say so.
    assertTrue(str_contains($css, ':root[data-theme="dark"]'), 'pinned dark');
    assertTrue(str_contains($css, ':root:not([data-theme="light"])'), 'device default, losing to a pin');
    assertTrue(str_contains($css, 'prefers-color-scheme: dark'), 'follows the device');
    assertTrue(str_contains($css, ':root[data-text="large"]'), 'large text');
});

test('large text scales the base by about a quarter', function (): void {
    $css = stylesheetSource();

    // Spec 9.3: "scales the base by roughly 125%". The base is 106.25% of the
    // browser default, which is the 17px minimum the same section sets.
    assertTrue(
        (bool) preg_match('/--text-scale:\s*1\.2[0-9]*;/', $css),
        'Large Text must scale the base by roughly 1.25'
    );
    assertTrue(str_contains($css, 'calc(106.25% * var(--text-scale, 1))'), '17px base, scaled');
});

test('the hidden attribute outranks anything a component sets', function (): void {
    $css = stylesheetSource();

    // Controls that only work with JavaScript ship hidden and are revealed by
    // it. That contract is worthless if [hidden] loses on specificity, which
    // is what put a dead keypad on the login screen and an INSTALLED badge on
    // a page in a browser tab.
    assertTrue(
        (bool) preg_match('/\[hidden\]\s*\{\s*display:\s*none\s*!important/', $css),
        '[hidden] must be display:none !important'
    );
});
