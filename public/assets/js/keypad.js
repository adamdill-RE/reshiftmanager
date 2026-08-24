/*
 * The PIN keypad (spec 3.2).
 *
 * "PIN entry uses a large on-screen numeric keypad — not the system keyboard.
 * Buttons are a minimum of 64px tall, which is the single most important
 * accommodation for cold hands."
 *
 * Progressive enhancement: the form ships with a working number field and
 * submits fine without JavaScript. This replaces that field with the keypad
 * only once it has successfully built one, so a script failure degrades to a
 * usable login rather than an unusable one.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-keypad]');
    if (!form) {
        return;
    }

    var input = form.querySelector('input[name="pin"]');
    var fallback = form.querySelector('[data-pin-fallback]');
    var pad = form.querySelector('[data-keypad-buttons]');
    var dots = form.querySelector('[data-pin-dots]');
    if (!input || !pad || !dots) {
        return;
    }

    var maxLength = parseInt(input.getAttribute('maxlength'), 10) || 4;
    var entered = '';

    function render() {
        input.value = entered;

        var children = dots.children;
        for (var i = 0; i < children.length; i++) {
            children[i].classList.toggle('pin-dot--filled', i < entered.length);
        }

        // Announced rather than shown: the digits themselves stay secret, but
        // "3 of 4 entered" has to reach someone using a screen reader.
        dots.setAttribute('aria-label', entered.length + ' of ' + maxLength + ' digits entered');
    }

    function press(value) {
        if (value === 'clear') {
            entered = '';
        } else if (value === 'back') {
            entered = entered.slice(0, -1);
        } else if (entered.length < maxLength) {
            entered += value;
        }
        render();
    }

    pad.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-key]');
        if (!button) {
            return;
        }
        // Deliberately no auto-submit on the fourth digit. A mis-tap would
        // spend one of ten attempts before the user could look at it.
        event.preventDefault();
        press(button.getAttribute('data-key'));
    });

    // A physical keyboard still works — useful for the Admin on a desktop.
    form.addEventListener('keydown', function (event) {
        if (event.key >= '0' && event.key <= '9') {
            event.preventDefault();
            press(event.key);
        } else if (event.key === 'Backspace') {
            event.preventDefault();
            press('back');
        }
    });

    // Swap the number field for the keypad now that one exists.
    input.type = 'hidden';
    if (fallback) {
        fallback.hidden = true;
    }
    pad.hidden = false;
    dots.hidden = false;
    render();
}());
