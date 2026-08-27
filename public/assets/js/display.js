/**
 * The Theme and Large Text controls (spec 9.2, 9.3).
 *
 * theme.js does the applying and the storing — it runs in <head>, before
 * paint, because a pinned dark theme applied afterwards is a white flash in
 * the face of someone who came here to avoid one. This file is only the
 * controls, so it is deferred with everything else.
 *
 * The section it lives in ships hidden and is revealed here. Both settings
 * live on the device, so neither does anything without JavaScript, and an
 * inert Dark button is worse than no Dark button at all.
 */
(function () {
    'use strict';

    var section = document.querySelector('[data-display]');
    var display = window.Resm && window.Resm.display;

    if (!section || !display) {
        return;
    }

    section.hidden = false;

    var warning = section.querySelector('[data-display-warning]');

    /**
     * Mark the live option in a group.
     *
     * aria-pressed rather than a class alone: these are toggle buttons, and a
     * screen reader has no way to tell which one is on from the colour that
     * says so to everybody else.
     */
    function mark(attribute, current) {
        var buttons = section.querySelectorAll('[' + attribute + ']');

        for (var i = 0; i < buttons.length; i++) {
            var on = buttons[i].getAttribute(attribute) === current;
            buttons[i].classList.toggle('choice__option--on', on);
            buttons[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
    }

    function wire(attribute, apply, read) {
        var buttons = section.querySelectorAll('[' + attribute + ']');

        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function (event) {
                var stored = apply(event.currentTarget.getAttribute(attribute));

                // The change always takes effect; only remembering it can
                // fail, on a phone set to block site data. Saying so is the
                // difference between a setting that looks broken and one the
                // user knows the limits of.
                if (warning && !stored) {
                    warning.hidden = false;
                }

                mark(attribute, read());
            });
        }

        mark(attribute, read());
    }

    wire('data-theme-choice', display.setTheme, display.theme);
    wire('data-text-choice', display.setText, display.text);
}());
