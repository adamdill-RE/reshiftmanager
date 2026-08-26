/**
 * The offline queue (spec 10.3, 6.4).
 *
 * Check in, check out and lunch changes are written to IndexedDB when the
 * server cannot be reached, and replayed when it can — each carrying the
 * ORIGINAL moment of the tap, not the moment it eventually synced.
 *
 * Three things about the shape of this are deliberate.
 *
 * It intercepts by opting in, not by opting out. Only a form carrying
 * data-offline is diverted, so officer assignment writes are untouched BY
 * CONSTRUCTION rather than by a rule someone has to remember. Spec 10.3 is
 * flat about it: an assignment is never optimistic, because two officers
 * assigning at once is resolved by the server and by the unique indexes on
 * assignment, and a phone in a pocket cannot take part in that.
 *
 * It always goes through fetch, not only when the phone says it is offline.
 * navigator.onLine reports a link, never reachability, and the tarmac case is
 * precisely a handset showing a bar of signal that cannot reach anything. So
 * the request is attempted, and it is the FAILURE that queues.
 *
 * A queued item is deleted only when the server has confirmed it. That means
 * an answer lost on the way back is sent again, which is why the endpoint is
 * idempotent — the unique indexes from migration 007 make the second arrival
 * land on the first.
 */
(function () {
    'use strict';

    var forms = document.querySelectorAll('form[data-offline]');
    var badge = document.querySelector('[data-pending]');

    if (!forms.length && !badge) {
        return;
    }

    var body = document.body;
    var base = body.getAttribute('data-base') || '/';

    var DB_NAME = 'resm';
    var DB_VERSION = 1;
    var STORE = 'queue';

    // ---------------------------------------------------------------------
    // IndexedDB, wrapped just enough to be readable
    // ---------------------------------------------------------------------

    function open() {
        return new Promise(function (resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('no indexeddb'));
                return;
            }

            var request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function () {
                var db = request.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
                }
            };

            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    }

    function withStore(mode, work) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE, mode);
                var result = work(tx.objectStore(STORE));

                tx.oncomplete = function () { resolve(result && result.value); };
                tx.onerror = function () { reject(tx.error); };
                tx.onabort = function () { reject(tx.error); };
            });
        });
    }

    function enqueue(item) {
        return withStore('readwrite', function (store) {
            var request = store.add(item);
            var out = {};
            request.onsuccess = function () { out.value = request.result; };
            return out;
        });
    }

    function all() {
        return withStore('readonly', function (store) {
            var request = store.getAll();
            var out = {};
            request.onsuccess = function () { out.value = request.result || []; };
            return out;
        });
    }

    function drop(id) {
        return withStore('readwrite', function (store) {
            store.delete(id);
            return {};
        });
    }

    // ---------------------------------------------------------------------
    // The badge (spec 6.4: a visible "1 pending" until it syncs)
    // ---------------------------------------------------------------------

    // What the badge last showed. Also what stops the poll subscriber below
    // opening IndexedDB every ten seconds for the whole of a shift, on a
    // battery that has to last until 02:00.
    var pending = 0;

    function refreshBadge() {
        return all().then(function (items) {
            pending = items.length;

            if (badge) {
                badge.hidden = pending === 0;
                badge.textContent = pending === 1 ? '1 pending' : pending + ' pending';
            }

            return pending;
        }).catch(function () {
            // A phone with IndexedDB blocked has no queue to report on. Saying
            // nothing is right; claiming zero pending would be a claim.
            pending = 0;
            if (badge) {
                badge.hidden = true;
            }
            return 0;
        });
    }

    // ---------------------------------------------------------------------
    // Sending
    // ---------------------------------------------------------------------

    /**
     * A token the server will still accept.
     *
     * The one in the page may have been rendered into it before a cache and a
     * session rotation, so it is refreshed from the polling endpoint — which
     * is authenticated, already exists, and answers with the live token.
     */
    function freshToken() {
        var shift = body.getAttribute('data-poll-shift');
        if (!shift) {
            return Promise.resolve(null);
        }

        return fetch(base + 'api/state?shift=' + encodeURIComponent(shift), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (d) {
            return d && d.csrf ? d.csrf : null;
        }).catch(function () {
            return null;
        });
    }

    function send(item, token) {
        var form = new URLSearchParams();
        form.set('_csrf', token);
        form.set('kind', item.kind);
        form.set('shift', item.shift);
        form.set('at', item.at);
        form.set('client_id', String(item.id));
        if (item.type) { form.set('type', item.type); }
        if (item.state) { form.set('state', item.state); }

        return fetch(base + 'api/sync', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: form.toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            }).catch(function () {
                return { status: response.status, data: {} };
            });
        });
    }

    var draining = false;

    function drain() {
        if (draining) {
            return Promise.resolve();
        }
        draining = true;

        var applied = 0;

        return all().then(function (items) {
            if (!items.length) {
                return null;
            }

            return freshToken().then(function (token) {
                if (!token) {
                    // No reachable server, or no shift to ask about. Leave
                    // everything queued and try again on the next signal.
                    return null;
                }

                // Sequential, not parallel. These are events about one man on
                // one shift and their order is their meaning — an "out"
                // applied before the "in" it followed would leave him checked
                // in for the rest of the night.
                return items.reduce(function (chain, item) {
                    return chain.then(function () {
                        return send(item, token).then(function (answer) {
                            if (answer.data.ok) {
                                applied++;
                            }

                            // Landed, or gone for good. Either way it leaves
                            // the queue: a badge that never clears is one
                            // people stop reading, which costs more than the
                            // event it was hiding.
                            if (answer.data.ok || answer.data.retry === false) {
                                return drop(item.id);
                            }
                            return null;
                        }).catch(function () {
                            // Network gone again mid-drain. Keep it.
                            return null;
                        });
                    });
                }, Promise.resolve());
            });
        }).then(function () {
            draining = false;
            return refreshBadge().then(function () { return settled(applied); });
        }).catch(function () {
            draining = false;
            return refreshBadge();
        });
    }

    /**
     * Something that was queued has now actually happened, so the screen
     * describing it is out of date.
     *
     * Spec 10.4: the client re-reads shift state after a write rather than
     * computing it locally. The status strip refreshes itself through the
     * polling layer, but the page beneath it does not — and the page beneath
     * it is where the big CHECK OUT button lives. Left alone it would go on
     * offering to do the thing that has just been done.
     *
     * Reloading is heavy-handed anywhere else and is right here: it happens
     * once, only when the queue actually emptied onto the server, and the
     * alternative is a primary action that lies.
     */
    function settled(applied) {
        if (applied > 0) {
            window.location.reload();
        }
    }

    // ---------------------------------------------------------------------
    // Intercepting the three forms that may be recorded late
    // ---------------------------------------------------------------------

    function queueFrom(form) {
        var data = new FormData(form);
        var kind = form.getAttribute('data-offline');

        return {
            kind: kind,
            shift: String(data.get('shift_id') || body.getAttribute('data-poll-shift') || ''),
            type: kind === 'check' ? String(data.get('type') || '') : '',
            state: kind === 'lunch' ? String(data.get('state') || '') : '',
            // The device's own clock at the moment of the tap. The server
            // keeps it as occurred_at and stamps its own recorded_at beside
            // it; the gap between the two is what exposes a handset whose
            // clock is wrong (migration 001).
            at: new Date().toISOString()
        };
    }

    /**
     * Where a form posts to.
     *
     * NOT form.action. A form's named controls are exposed as properties on
     * the form itself, so <input name="action"> — which My Shift Status has,
     * to say whether the post is a check or a lunch change — shadows the
     * action property entirely and hands back the INPUT ELEMENT. Passing that
     * to fetch stringifies it to "[object HTMLInputElement]" and posts the tap
     * to a URL that does not exist.
     *
     * The attribute is never shadowed, and resolving it against the document
     * keeps the /resm/ mount point right without anything hard-coding it.
     */
    function actionOf(form) {
        var raw = form.getAttribute('action') || '';
        return new URL(raw, window.location.href).href;
    }

    function announce(form, message) {
        var note = form.querySelector('[data-offline-note]');
        if (note) {
            note.textContent = message;
            note.hidden = false;
        }
    }

    for (var i = 0; i < forms.length; i++) {
        forms[i].addEventListener('submit', function (event) {
            var form = event.currentTarget;

            // A submit button that was pressed carries its own name and value
            // — the lunch controls are three buttons in one form — and
            // FormData does not include it. Captured on the way past.
            var item = queueFrom(form);
            if (event.submitter && event.submitter.name) {
                if (item.kind === 'check' && event.submitter.name === 'type') {
                    item.type = event.submitter.value;
                }
                if (item.kind === 'lunch' && event.submitter.name === 'state') {
                    item.state = event.submitter.value;
                }
            }

            event.preventDefault();

            var data = new FormData(form);
            if (event.submitter && event.submitter.name) {
                data.set(event.submitter.name, event.submitter.value);
            }

            var action = actionOf(form);

            fetch(action, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                body: data,
                redirect: 'follow'
            }).then(function (response) {
                if (!response.ok && response.status >= 500) {
                    throw new Error('server error');
                }

                // The server answered — including with a validation failure,
                // which is an answer and not something to queue. Follow it the
                // way a normal form post would have.
                window.location.assign(response.url || action);
            }).catch(function () {
                // No answer. THIS is the offline case — not what
                // navigator.onLine claims.
                enqueue(item).then(function () {
                    return refreshBadge();
                }).then(function () {
                    announce(
                        form,
                        'Saved on this phone at ' + new Date().toLocaleTimeString()
                        + '. It will be sent when you have signal.'
                    );
                }).catch(function () {
                    announce(form, 'This phone could not save that. Try again when you have signal.');
                });
            });
        });
    }

    // ---------------------------------------------------------------------
    // When to try again
    // ---------------------------------------------------------------------

    refreshBadge().then(function (pending) {
        if (pending > 0) {
            drain();
        }
    });

    window.addEventListener('online', drain);

    /**
     * Its own retry, while and only while something is waiting.
     *
     * The poller backs off exponentially when it cannot reach the server,
     * which is right when it has nothing to say — it is a battery that has to
     * last until 02:00. It is wrong here: a queued check-out is a man the
     * board still shows on the tarmac, and leaving it for up to five minutes
     * of backoff is five minutes of an officer chasing somebody who has gone
     * home. So when there is something to send, this asks on its own schedule,
     * and when the queue is empty it costs nothing at all.
     */
    var RETRY_WHILE_PENDING_MS = 30000;

    setInterval(function () {
        if (pending > 0) {
            drain();
        }
    }, RETRY_WHILE_PENDING_MS);

    // The poller is the honest signal that the server is reachable — more
    // honest than the online event, which fires on a link coming up whether or
    // not anything is behind it.
    if (window.Resm && window.Resm.poll) {
        window.Resm.poll.subscribe(function (event) {
            if (pending > 0 && (event.kind === 'fresh' || event.kind === 'changed')) {
                drain();
            }
        });
    }
}());
