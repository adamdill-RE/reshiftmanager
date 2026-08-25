/**
 * The freshness counter on the status widget (spec 6.3).
 *
 * "A stale screen must never look live." Until the polling layer lands in
 * phase 5 nothing on this page refreshes itself, so the honest thing is to
 * say how old it is and let it go amber.
 *
 * Without JavaScript the strip reads "just now", which is true at the moment
 * it renders and is the only claim the server can make on its own.
 */
(function () {
    'use strict';

    var el = document.querySelector('.widget__fresh');
    if (!el) {
        return;
    }

    function tick() {
        // Read the attribute every tick rather than once at load. Today it
        // never changes, but the polling layer in phase 5 refreshes this strip
        // in place and has to be able to reset its age — a counter that latched
        // its start time would then say a freshly updated screen was minutes
        // old, which is the exact lie 6.3 exists to prevent.
        var at = Date.parse(el.getAttribute('data-rendered-at'));
        if (isNaN(at)) {
            return;
        }

        var seconds = Math.max(0, Math.round((Date.now() - at) / 1000));

        if (seconds < 5) {
            el.textContent = 'just now';
        } else if (seconds < 60) {
            el.textContent = 'updated ' + seconds + 's ago';
        } else {
            var minutes = Math.round(seconds / 60);
            el.textContent = 'updated ' + minutes + 'm ago';
        }

        el.classList.toggle('widget__fresh--stale', seconds >= 60);
    }

    tick();
    setInterval(tick, 1000);
}());
