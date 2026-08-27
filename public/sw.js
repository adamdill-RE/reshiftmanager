/**
 * The service worker (spec 10.3).
 *
 * Served from /resm/, not the domain root, so its default scope is exactly the
 * application and nothing wider. That is why this file sits in public/ next to
 * index.php rather than under assets/ — a worker at /resm/assets/sw.js would
 * only ever control /resm/assets/, and would appear to install perfectly while
 * controlling none of the app.
 *
 * Two caches, two strategies, and the difference between them is whether the
 * thing being cached can go out of date without its URL changing.
 *
 *   Assets — cache-first. App::asset stamps every URL with the file's mtime,
 *   so an asset URL names one immutable byte sequence. A deploy produces new
 *   URLs, not new contents at old URLs, which is what makes cache-first safe
 *   here rather than a way to serve last week's stylesheet.
 *
 *   Pages — network-first. These are server-rendered per user and per shift;
 *   there is no version in the URL and no way for one to be added. Cached only
 *   as a fallback for a phone with no signal, and never preferred over an
 *   answer the server is willing to give.
 *
 * The one thing that must never be cached in any form is the polling endpoint.
 * Its entire job is answering "is my copy current", and a cached answer to
 * that is the lie spec 6.3 exists to prevent.
 */
'use strict';

// The registration URL carries ?v=<version> (see sw-register.js), which is
// what makes the browser see a byte-different worker after a deploy. Reading
// it back here is what makes the cache names change with it.
var VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
var ASSET_CACHE = 'resm-assets-' + VERSION;
var PAGE_CACHE = 'resm-pages-' + VERSION;

// Resolved at install from the manifest, which PHP generates so that nothing
// here has to know where the app is mounted.
var MANIFEST = new URL('sw-manifest.json?v=' + VERSION, self.location.href).href;

var scope = new URL(self.registration.scope).pathname;
var never = [];
var pages = [];

self.addEventListener('install', function (event) {
    event.waitUntil(
        fetch(MANIFEST, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('no manifest: ' + response.status);
                }
                return response.json();
            })
            .then(function (manifest) {
                never = manifest.never || [];
                pages = manifest.pages || [];

                return caches.open(ASSET_CACHE).then(function (cache) {
                    // addAll is all-or-nothing, which is what is wanted: a
                    // half-populated shell cache is worse than none, because
                    // it looks installed and then misses whichever file the
                    // screen actually needed.
                    return cache.addAll(manifest.assets || []);
                });
            })
            // A failed install leaves the previous worker in place and the app
            // online-only, which is the correct failure. Nothing here is
            // allowed to take down a working app to install an offline copy
            // of it.
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                // Anything from an older version. This is the deploy story:
                // the version is derived from the assets' own mtimes, so a
                // deploy renames every cache and this deletes what came
                // before it. Nothing survives to be served stale.
                var mine = name === ASSET_CACHE || name === PAGE_CACHE;
                if (!mine && name.indexOf('resm-') === 0) {
                    return caches.delete(name);
                }
                return null;
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

/** Signed out, or told to forget: every cached page belongs to one person. */
function forgetPages() {
    return caches.keys().then(function (names) {
        return Promise.all(names.map(function (name) {
            return name.indexOf('resm-pages-') === 0 ? caches.delete(name) : null;
        }));
    });
}

self.addEventListener('message', function (event) {
    if (!event.data || event.data.type !== 'resm-signed-out') {
        return;
    }

    // A shared phone on the tarmac is the ordinary case, not the exotic one.
    // The next man to sign in must not be able to pull the last man's shift
    // status out of a cache by turning aeroplane mode on.
    event.waitUntil(forgetPages());
});

function isNeverCached(url) {
    for (var i = 0; i < never.length; i++) {
        if (url.pathname.indexOf(never[i]) === 0) {
            return true;
        }
    }

    // Belt and braces for the case that matters most: if the manifest failed
    // to load, `never` is empty and every rule above silently stops applying.
    // The polling endpoint is not allowed to depend on that.
    return url.pathname.indexOf(scope + 'api/') === 0;
}

function isPage(url) {
    for (var i = 0; i < pages.length; i++) {
        if (url.pathname === pages[i]) {
            return true;
        }
    }
    return false;
}

self.addEventListener('fetch', function (event) {
    var request = event.request;

    // Only GET, only this origin, only inside the app. A POST is a write and
    // is never replayed from here — the offline queue owns that, and it owns
    // it in the page where the user can see it happen.
    if (request.method !== 'GET') {
        return;
    }

    var url = new URL(request.url);
    if (url.origin !== self.location.origin || url.pathname.indexOf(scope) !== 0) {
        return;
    }

    if (isNeverCached(url)) {
        return;
    }

    if (url.pathname.indexOf(scope + 'assets/') === 0) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Every navigation inside the app, not only the ones worth saving. What
    // differs is whether the answer is KEPT: only the pages the manifest names
    // are stored, because a cached page is per-user and each one is something
    // that has to be cleared on sign-out. Handling the rest buys the app's own
    // "not saved yet" page instead of the browser's error screen, at the cost
    // of caching nothing extra.
    if (request.mode === 'navigate') {
        event.respondWith(networkFirst(request, isPage(url)));
    }
});

function cacheFirst(request) {
    return caches.match(request).then(function (hit) {
        if (hit) {
            return hit;
        }

        return fetch(request).then(function (response) {
            // Opaque and error responses are not worth keeping; a cached 404
            // is a file that stays missing until the next deploy.
            if (response.ok && response.type === 'basic') {
                var copy = response.clone();
                caches.open(ASSET_CACHE).then(function (c) { c.put(request, copy); });
            }
            return response;
        });
    });
}

function networkFirst(request, keep) {
    return fetch(request).then(function (response) {
        // Spec 10.3: the user's own screen is cached on every SUCCESSFUL
        // fetch. A redirect to the login screen is a successful fetch of
        // something else entirely, and caching it would hand the next visitor
        // a login page that never goes away.
        if (keep && response.ok && response.type === 'basic' && !response.redirected) {
            var copy = response.clone();
            caches.open(PAGE_CACHE).then(function (c) { c.put(request, copy); });
        }
        return response;
    }).catch(function () {
        return caches.match(request).then(function (hit) {
            if (hit) {
                return hit;
            }

            // Nothing cached and no network. Answering with the shell of
            // another page would be worse than saying so plainly.
            return new Response(
                '<!doctype html><meta charset="utf-8">'
                + '<meta name="viewport" content="width=device-width, initial-scale=1">'
                + '<title>No connection</title>'
                + '<body style="font:17px system-ui;margin:2rem;background:#14100D;color:#F6EFE8">'
                + '<h1>No connection</h1>'
                + '<p>This screen has not been saved on this phone yet. '
                + 'Open it once with a signal and it will be here next time.</p>',
                { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
            );
        });
    });
}
