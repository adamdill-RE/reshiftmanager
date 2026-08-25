/**
 * Create Shifts: fill the times in when the type changes, and keep the
 * single/range choice honest.
 *
 * Progressive enhancement only. Without it the form still works — the times
 * are pre-filled server-side and the admin types over them, and the server
 * decides what "mode" means regardless of what is greyed out here.
 */
(function () {
    'use strict';

    var form = document.getElementById('shift-form');
    if (!form) {
        return;
    }

    var start = form.querySelector('#start_time');
    var end = form.querySelector('#end_time');
    var types = form.querySelectorAll('input[name="shift_type"]');
    var modes = form.querySelectorAll('input[name="mode"]');
    var single = form.querySelector('#date');
    var rangeFields = [form.querySelector('#from_date'), form.querySelector('#to_date')];
    var weekdays = form.querySelectorAll('input[name="weekdays[]"]');

    // Only overwrite times the administrator has not touched. Picking a type
    // after typing 17:30 should not throw the 17:30 away.
    var edited = false;
    [start, end].forEach(function (field) {
        if (field) {
            field.addEventListener('input', function () { edited = true; });
        }
    });

    Array.prototype.forEach.call(types, function (radio) {
        radio.addEventListener('change', function () {
            if (edited || !radio.checked || !start || !end) {
                return;
            }
            start.value = radio.getAttribute('data-start') || start.value;
            end.value = radio.getAttribute('data-end') || end.value;
        });
    });

    function currentMode() {
        for (var i = 0; i < modes.length; i++) {
            if (modes[i].checked) {
                return modes[i].value;
            }
        }
        return 'single';
    }

    function applyMode() {
        var range = currentMode() === 'range';

        if (single) {
            single.disabled = range;
        }
        rangeFields.forEach(function (field) {
            if (field) {
                field.disabled = !range;
            }
        });
        Array.prototype.forEach.call(weekdays, function (box) {
            box.disabled = !range;
        });
    }

    Array.prototype.forEach.call(modes, function (radio) {
        radio.addEventListener('change', applyMode);
    });

    applyMode();
}());
