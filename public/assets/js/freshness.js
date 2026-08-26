/**
 * The status strip's subscriber (spec 6.3).
 *
 * "A stale screen must never look live." Before the polling layer existed
 * nothing on the page refreshed itself, so this file could only age the strip
 * by guesswork from the render time. It now ages it from the last moment the
 * server actually answered, which is the only thing that makes the claim true:
 * a 304 is as much a live contact as a changed board, and a fetch that failed
 * is not one however recently the page was drawn.
 *
 * It also swaps the strip's markup when the shift moves. The replacement HTML
 * is rendered by the same PHP view that drew it in the first place — there is
 * no second renderer here to drift out of step with the first.
 *
 * Without JavaScript the strip reads "just now", which is true at the moment
 * it renders and is the only claim the server can make on its own.
 */
(function () {
    'use strict';

    var strip = document.getElementById('status-strip');
    if (!strip || !window.Resm || !window.Resm.poll) {
        return;
    }

    var poll = window.Resm.poll;

    // Amber past a minute, red when the last ask failed (spec 6.3).
    var STALE_SECONDS = 60;

    var offline = false;
    var stoppedWhy = null;

    function label(seconds) {
        if (offline) {
            return 'offline — last updated ' + age(seconds);
        }
        if (stoppedWhy === 'window-closed') {
            return 'this shift has closed';
        }
        if (stoppedWhy === 'signed-out') {
            return 'signed out — reload to sign in';
        }
        if (seconds < 5) {
            return 'just now';
        }
        return 'updated ' + age(seconds);
    }

    function age(seconds) {
        if (seconds < 60) {
            return seconds + 's ago';
        }
        if (seconds < 3600) {
            return Math.round(seconds / 60) + 'm ago';
        }
        return Math.round(seconds / 3600) + 'h ago';
    }

    function tick() {
        // Re-queried every tick rather than held: the element is replaced
        // wholesale whenever the shift moves, and a cached reference would go
        // on updating a node that is no longer in the document — a strip
        // frozen at the moment it last changed, which is the exact lie 6.3
        // exists to prevent.
        var el = strip.querySelector('.widget__fresh');
        if (!el) {
            return;
        }

        var seconds = Math.max(0, Math.round((Date.now() - poll.lastContact()) / 1000));

        el.textContent = label(seconds);
        el.classList.toggle('widget__fresh--stale', !offline && seconds >= STALE_SECONDS);
        el.classList.toggle('widget__fresh--offline', offline);
    }

    poll.subscribe(function (event) {
        if (event.kind === 'offline') {
            offline = true;
        } else if (event.kind === 'stopped') {
            stoppedWhy = event.why;
        } else {
            offline = false;
        }

        if (event.kind === 'changed' && typeof event.data.widget === 'string') {
            // The server sends null for someone the strip does not apply to —
            // an officer running a board he is not checked into — and an empty
            // string for a strip that has gone away. Both mean "no strip", and
            // assigning either is correct.
            strip.innerHTML = event.data.widget;
        }

        tick();
    });

    tick();
    setInterval(tick, 1000);
}());
