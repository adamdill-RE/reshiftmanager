/**
 * Registering the service worker (spec 10.3).
 *
 * The version in the URL is the whole deploy story. Files reach this host by
 * copy, so sw.js may be byte-identical after a deploy that changed every
 * stylesheet — and a byte-identical worker is one the browser does not bother
 * to reinstall. The version is derived server-side from the assets' own
 * mtimes, so a changed asset changes this URL, the browser sees a new worker,
 * and its activate step deletes the caches the old one wrote.
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    var body = document.body;
    var base = body.getAttribute('data-base') || '/';
    var version = body.getAttribute('data-shell-version');

    if (!version) {
        return;
    }

    // The scope is the mount point, never the domain root. A worker scoped to
    // "/" would claim every other page on reshiftmanager.com, and this app owns
    // /resm/ and nothing else (CLAUDE.md).
    navigator.serviceWorker.register(base + 'sw.js?v=' + encodeURIComponent(version), { scope: base })
        .catch(function (error) {
            // An app that works online and not offline is a smaller problem
            // than an app that does not load, so this is reported and
            // swallowed rather than thrown.
            if (window.console && console.warn) {
                console.warn('service worker did not register', error);
            }
        });

    /**
     * Sign-out has to reach the cache.
     *
     * Cached pages are rendered per user, and a phone on the tarmac is shared
     * far more often than it is not. Without this the next man to sign in
     * could pull the last man's shift status back by turning the signal off.
     * Sent before the form submits so the worker has the message in hand while
     * the page is still alive.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || typeof form.getAttribute !== 'function') {
            return;
        }

        // getAttribute, not form.action: a form's named controls shadow its
        // properties, and My Shift Status posts an <input name="action"> — so
        // form.action there is the input element, not the URL.
        var action = form.getAttribute('action') || '';
        if (action.indexOf(base + 'logout') === -1) {
            return;
        }

        if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'resm-signed-out' });
        }
    }, true);
}());
