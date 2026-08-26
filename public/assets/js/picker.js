/*
 * Team and shift selectors submit on change (spec 6.9).
 *
 * Progressive enhancement, and it has to be: the Content-Security-Policy is
 * script-src 'self' with no unsafe-inline, so an onchange attribute would be
 * blocked by the browser and the selector would silently stop working. The
 * markup ships a real submit button; this file wires the change event and then
 * hides the button, so a phone with JavaScript off keeps a working screen.
 */
(function () {
    'use strict';

    var selects = document.querySelectorAll('select[data-autosubmit]');
    if (selects.length === 0) {
        return;
    }

    Array.prototype.forEach.call(selects, function (select) {
        select.addEventListener('change', function () {
            if (select.form) {
                select.form.submit();
            }
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.picker__go'), function (button) {
        button.hidden = true;
    });
}());
