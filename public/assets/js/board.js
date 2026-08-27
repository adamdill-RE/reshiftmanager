/**
 * "Someone else has changed this board" (spec 10.2, 10.4).
 *
 * Two officers will assign at the same time. The server is the sole authority
 * and the unique indexes on assignment settle who wins, so the losing write is
 * already refused — this is the other half: telling an officer whose board has
 * moved underneath him BEFORE he taps into a slot that is no longer vacant.
 *
 * It offers rather than acts. Reloading the page on its own would be correct
 * and hostile: an officer three taps into placing a man would lose the sheet
 * he is standing in. His own writes redirect and re-render anyway, so the only
 * change that ever reaches this is somebody else's.
 */
(function () {
    'use strict';

    var bar = document.querySelector('[data-board-moved]');
    if (!bar || !window.Resm || !window.Resm.poll) {
        return;
    }

    var button = bar.querySelector('[data-board-refresh]');

    window.Resm.poll.subscribe(function (event) {
        if (event.kind !== 'changed') {
            return;
        }

        bar.hidden = false;
    });

    if (button) {
        button.addEventListener('click', function () {
            window.location.reload();
        });
    }
}());
