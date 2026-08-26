/**
 * The polling layer (spec 10.2).
 *
 * CloudLinux LVE caps concurrent entry processes per account, so WebSockets and
 * SSE are out — thirty held-open connections would exhaust the allocation
 * (docs/hosting.md). Clients short-poll a version integer instead.
 *
 * This file is the transport and nothing else. It knows how to ask the server
 * whether a shift has moved and how often to ask; it knows nothing about the
 * status strip, the assign board, or any other screen. Screens subscribe:
 *
 *     var stop = Resm.poll.subscribe(function (event) { ... });
 *
 * That boundary is the point. Spec 10.2 wants this swappable for Web Push
 * later "without touching the screens", so the day a push message replaces the
 * setTimeout, every subscriber keeps working — the events it delivers are the
 * contract, not the fetch underneath them.
 *
 * Events, all carrying `at` (the moment of the last real server contact):
 *
 *     {kind: 'fresh',   at}        the server answered 304: nothing has moved
 *     {kind: 'changed', at, data}  new state, `data` is the endpoint's JSON
 *     {kind: 'offline', at}        the ask failed; `at` is the last good one
 *     {kind: 'stopped', at, why}   no more polls are coming
 *
 * `fresh` matters as much as `changed`. Spec 6.3 says a stale screen must
 * never be mistakable for a live one, and the only honest basis for that claim
 * is when the server last answered — not when the page was rendered, which is
 * what freshness.js had to guess from before this existed.
 */
(function () {
    'use strict';

    var root = window.Resm = window.Resm || {};

    var body = document.body;
    var shift = parseInt(body.getAttribute('data-poll-shift'), 10);

    // Every screen loads the same bundle; only the ones the server gave a
    // shift actually poll. No shift, no timer, no requests.
    if (isNaN(shift) || shift <= 0) {
        root.poll = inert();
        return;
    }

    var base = body.getAttribute('data-base') || '/';
    var version = parseInt(body.getAttribute('data-poll-version'), 10);
    var foreground = seconds(body.getAttribute('data-poll-foreground'), 10);
    var background = seconds(body.getAttribute('data-poll-background'), 60);
    var closesAt = Date.parse(body.getAttribute('data-poll-closes') || '');

    var subscribers = [];
    var timer = null;
    var stopped = false;
    var inFlight = false;

    // The last time the server actually answered. Seeded with the page render,
    // which is a true statement about freshness at that instant.
    var lastContact = Date.now();

    // Consecutive failures. A phone that has walked behind a hangar should not
    // keep firing at the same rate — it drains a battery that has to last
    // until 02:00 and the answers are not going to improve.
    var failures = 0;
    var MAX_BACKOFF = 5;

    function seconds(raw, fallback) {
        var n = parseInt(raw, 10);
        return (isNaN(n) || n < 1) ? fallback * 1000 : n * 1000;
    }

    function inert() {
        return {
            subscribe: function () { return function () {}; },
            refreshNow: function () {},
            version: function () { return null; },
            lastContact: function () { return Date.now(); },
            isStopped: function () { return true; }
        };
    }

    function emit(kind, extra) {
        var event = {kind: kind, at: lastContact};
        if (extra) {
            for (var key in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, key)) {
                    event[key] = extra[key];
                }
            }
        }

        // A throwing subscriber must not take the others down with it, and must
        // not kill the timer — the strip going quiet is how a screen starts
        // looking live when it is not.
        for (var i = 0; i < subscribers.length; i++) {
            try {
                subscribers[i](event);
            } catch (e) {
                if (window.console && console.error) {
                    console.error('poll subscriber failed', e);
                }
            }
        }
    }

    function halt(why) {
        if (stopped) {
            return;
        }
        stopped = true;
        clearTimeout(timer);
        emit('stopped', {why: why});
    }

    /**
     * Spec 10.2: paused entirely when the shift window is closed. The closing
     * time came down with the page, so a client can park itself without asking
     * — which is what keeps the server's side of this one indexed lookup.
     */
    function windowClosed() {
        return !isNaN(closesAt) && Date.now() > closesAt;
    }

    function interval() {
        var base = document.hidden ? background : foreground;

        // Exponential, capped. At 10s foreground the ceiling is about five
        // minutes, which is soon enough that walking back into signal is
        // noticed without the phone having spent the gap retrying.
        return base * Math.pow(2, Math.min(failures, MAX_BACKOFF));
    }

    function schedule() {
        clearTimeout(timer);
        if (stopped) {
            return;
        }
        if (windowClosed()) {
            halt('window-closed');
            return;
        }
        timer = setTimeout(ask, interval());
    }

    function ask() {
        if (stopped || inFlight) {
            return;
        }

        // Spec 10.2: paused entirely when offline. navigator.onLine only ever
        // reports a link, never reachability, so it is used to skip a request
        // that is certain to fail and never to conclude that one will succeed.
        if (navigator.onLine === false) {
            emit('offline');
            failures = Math.min(failures + 1, MAX_BACKOFF);
            schedule();
            return;
        }

        inFlight = true;

        var url = base + 'api/state?shift=' + encodeURIComponent(shift)
            + (version === null || isNaN(version) ? '' : '&v=' + encodeURIComponent(version));

        fetch(url, {
            method: 'GET',
            headers: {'Accept': 'application/json'},
            // The session cookie is the authorisation; there is no token in a
            // header to forge and none in localStorage to steal (CLAUDE.md).
            credentials: 'same-origin',
            // Never a cached answer. The question is literally "is my copy
            // current", so a cached reply is the one answer that cannot be.
            cache: 'no-store'
        }).then(function (response) {
            inFlight = false;

            if (response.status === 401) {
                halt('signed-out');
                return;
            }

            if (response.status === 404) {
                // The shift stopped being visible to this user — it ended and
                // fell out of the 5.3 window, or he came off the team.
                halt('gone');
                return;
            }

            if (response.status === 304) {
                failures = 0;
                lastContact = Date.now();
                emit('fresh');
                schedule();
                return;
            }

            if (!response.ok) {
                throw new Error('poll failed: ' + response.status);
            }

            return response.json().then(function (data) {
                failures = 0;
                lastContact = Date.now();

                if (typeof data.version === 'number') {
                    version = data.version;
                }
                if (data.closes_at) {
                    closesAt = Date.parse(data.closes_at);
                }

                emit('changed', {data: data});
                schedule();
            });
        }).catch(function () {
            inFlight = false;
            failures = Math.min(failures + 1, MAX_BACKOFF);

            // lastContact deliberately not touched: the strip should keep
            // ageing from the last time the server actually spoke.
            emit('offline');
            schedule();
        });
    }

    root.poll = {
        /** @return {function()} unsubscribe */
        subscribe: function (fn) {
            subscribers.push(fn);

            // A subscriber that arrives after the first answer would otherwise
            // sit with no state until the next tick.
            try {
                fn({kind: 'fresh', at: lastContact});
            } catch (e) { /* see emit */ }

            return function () {
                var i = subscribers.indexOf(fn);
                if (i !== -1) {
                    subscribers.splice(i, 1);
                }
            };
        },

        /** Ask now. Used after a write, per spec 10.4 — the client re-reads
            shift state after every write rather than computing it locally. */
        refreshNow: function () {
            failures = 0;
            clearTimeout(timer);
            ask();
        },

        version: function () { return version; },
        lastContact: function () { return lastContact; },
        isStopped: function () { return stopped; }
    };

    // Coming back to the app is the moment the answer is most likely to have
    // changed and the moment the user is most likely to trust what he sees.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            root.poll.refreshNow();
        } else {
            schedule();
        }
    });

    window.addEventListener('online', function () {
        failures = 0;
        root.poll.refreshNow();
    });

    window.addEventListener('offline', function () {
        emit('offline');
    });

    schedule();
}());
