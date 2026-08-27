# Rodeo Express — Shift Management

Manages the committeemen who run Rodeo Express bus operations: who is on the
grounds, where each person is standing on the tarmac, and pushing that
assignment to their phone the moment it changes.

Server-rendered PHP 8.2 and MySQL 8.0, no build step, deployed by file copy.
`docs/spec-v2.md` is authoritative — read it before changing assignment logic.

## Getting started

```sh
docker compose up -d
docker compose exec web php bin/migrate.php
open http://localhost:8080/
```

The app serves from `/resm/`, and `http://localhost:8080/` redirects there. The
local environment mirrors the server deliberately: `public/` is mounted inside
the document root at `/resm/`, application code is mounted at a *sibling* of
the document root, and `docker/php/php.ini` reproduces production's limits. A
constraint that will bite on the server bites here first.

Run the tests with `php tests/run.php` (or
`docker compose exec web php tests/run.php`). Tests that need a database skip
with an explanation rather than failing when there isn't one.

## Layout

| Path | What it is |
| --- | --- |
| `app/` | Application code. **Never web-accessible** — see below. |
| `app/src/` | Classes, autoloaded as `Resm\…` (no Composer). |
| `app/src/Auth/` | Sign-in, PINs, tokens, rate limiting, the permission matrix. |
| `app/views/` | Plain PHP templates. |
| `app/routes.php` | The route table. |
| `bin/` | CLI entry points: migrations, seed generation. |
| `config/` | `config.php` is committed; `config.local.php` holds credentials and is not. |
| `db/migrations/` | Numbered `.sql` files, applied once each in order. |
| `public/` | The only directory that reaches the web server. |
| `tests/` | A small runner and the suite. |
| `docs/` | The spec and the measured hosting environment. |

### Why `app/` sits outside the document root

`DOCUMENT_ROOT` on the server is `public_html` itself and the app is served
from `public_html/resm/`, so everything under `public_html` is reachable by
URL — including anything placed beside `resm/`. Application code therefore
lives in a sibling directory, `/home/reshiftmanager/resm-app/`, and is reached
by filesystem path. `public/index.php` finds it by probing, so the same file
works locally and on the server with nothing to configure.

Hiding code inside the document root behind an `.htaccess` rule would be
strictly weaker and easy to get wrong. `/status` checks this on every deploy.

## Deploying

`git push`, then **Deploy HEAD Commit** in cPanel. `.cpanel.yml` copies
`public/` into `public_html/resm/` and the rest into `~/resm-app/`, then fixes
modes to 0755/0644.

### No shell access

This account has neither SSH nor cPanel Terminal, so `bin/migrate.php` and
`bin/set-admin-pin.php` cannot be reached. `/resm/setup` does both from a
browser instead: it shows the database connection, applies pending migrations,
and sets an administrator's PIN.

It is guarded by `app.setup_key` in `config.local.php` rather than by a login,
because before the migrations run there is no user table to log in against.
Whoever holds that key can take the master admin account, so **remove the
`setup_key` line once the app is running** — with no key configured the route
does not exist, and that is the state to leave it in.

Migrations are **not** run automatically, and on this account the browser
route is the only way to apply them. Check what is pending with
`/resm/status?key=…`, which needs only the `status_key` that stays configured;
then add `setup_key` back to `config.local.php` through cPanel File Manager,
visit `/resm/setup?key=…`, apply, and remove the line again.

**`config.local.php` is PHP, and a typo in it takes the whole site down.** It
is the one file edited by hand on a server with no shell to lint it. Every
entry needs a trailing comma — including the last one — and a missing comma is
a parse error, not a warning:

```php
'status_key' => 'abc',   ← this comma is not optional
'debug' => false,
```

Get it wrong and every page returns 500. The application catches that case and
prints which line to fix, so read the page rather than the error log; if even
that does not appear, the file is unreadable or the app directory is missing.

The CLI equivalents — `php bin/migrate.php --status` and `php bin/migrate.php`
from `~/resm-app` — are the better path on any host that has a shell. This one
does not, so they are for local development and for a future move.

**The database is not on the web server.** Ahosting runs it separately, so
`db.host` is the address cPanel shows under Remote MySQL — an IP rather than a
hostname — not `localhost` and not `127.0.0.1`. Point it at this machine and you reach a different MySQL
instance, which answers `SQLSTATE[HY000] [1524] Plugin 'unix_socket' is not
loaded`. That reads like a credentials problem and is not one — it is the
local instance refusing an account it has never heard of. No amount of
password resetting will fix it; MariaDB refuses `SET PASSWORD` on such an
account anyway.

`config.local.php` is the one file on the server the deploy does not own — it
holds the database password, is not in git, and must survive every deploy. The
deploy therefore never chmods `config/` recursively; it sets `config.php` and,
if `config.local.php` is present, tightens it to 0600.

Set a `status_key` in `config/config.local.php` (see
`config.local.php.example`) and visit `/resm/status?key=…` afterwards. It
reports the runtime, whether the session cookie really is HttpOnly/Secure/
SameSite=Lax scoped to `/resm/`, whether code ended up inside the document
root, and whether migrations are pending. Without the key it returns 404, not
403, so it gives nothing away.

## Working on this

**Migrations are immutable once applied.** The runner records a checksum and
refuses to run if an applied file has changed. Add a new migration instead.
A migration that is pure data can opt into a transaction with a `-- resm:atomic`
line; schema migrations cannot, because MySQL commits implicitly on DDL.

**The position matrix is generated, not typed — and editable after go-live.**
`db/migrations/002_seed_reference.sql` comes from `bin/gen-position-seed.php`,
which parses section 8.3 of the spec and refuses to emit anything unless the
counts still come to 98 positions, 157 position-phase records, 22 radio, 39
critical and 3 multi-assign. The Position Matrix Editor (spec 6.10.8) now
edits those tables directly, which ends that guarantee for the live data: what
replaces it is visibility — every editor write lands in the audit log with its
before and after, and the editor's header shows the live counts beside the
seed baseline so drift is announced. The generator still guards the seed
migrations, which stay the immutable day-one record.

**The database enforces the assignment rules.** Two officers will assign at the
same time. `assignment` carries two unique indexes over generated columns, so
one person cannot hold two positions in a phase and one position cannot hold
two people — except the three multi-assign Unload positions. The losing write
gets a duplicate-key error; it is never resolved by reading first.

**Nothing hard-codes `/resm/`** outside `config/config.php`. Build every URL
with `$app->url(…)` and `$app->asset(…)`.

**Escape every rendered value** with `e()`, and use bound parameters for every
query. There is no exception to either. Note that a named placeholder cannot be
reused within one statement — emulated prepares are off, so PDO maps each name
to a single positional marker.

**Every POST checks a CSRF token** with `Csrf::check()`, and every handler that
needs a user asks for one itself. Reaching a route proves nothing about
permission.

## How signing in works

Two things hold a session together, and they do different jobs.

The **PHP session** is short — `gc_maxlifetime` is 1440s here and collection
belongs to the host — so it holds exactly one thing: the id of an `auth_token`
row.

The **`auth_token` row** is the real session. Because it lives server-side, a
session can be revoked, which is what makes "changing a PIN signs out your
other devices" take effect immediately. Every sign-in creates one; "keep me
signed in" only decides its lifetime and whether a cookie is issued.

The cookie is `selector.verifier`. The selector is the indexed lookup key and
is useless alone; only a SHA-256 of the verifier is stored, compared with
`hash_equals`. Resuming from the cookie rotates both and pushes the expiry out,
which is what makes the 90 days rolling.

Rotation is a **compare-and-swap**, and only the request that wins the swap
sends a new cookie. Two requests arriving together — a page load and a poll,
after the app was backgrounded — both read the same valid row; one rotation
lands and the other affects no rows and leaves the cookie alone. The browser
therefore always ends up holding what the database holds, whichever response
arrives last.

One deliberate departure from the textbook: a **known selector with a wrong
verifier does not revoke the token family.** That is the classic theft signal,
but an in-flight request that lost a rotation race lands in exactly the same
branch, and signing a team out mid-shift over a race they cannot see is the
worse failure. The cookie is refused for that request and the mismatch is
written to the audit log. This is the same trade the spec makes for the login
rate limit, and it is a decision to revisit if the threat model changes.

## Where this build stands

All six phases of the build sequence in spec 11.1 are complete: schema and
seed data, authentication, the Admin Menu, the committeeman experience, the
Officer Menu, the PWA shell with its service worker, offline queue and polling
layer, and now the Phase 6 screens — Export Roster, the Audit Log, and the
Position Matrix Editor — with the hardening and load-testing pass
(`docs/load-testing.md`). Broadcast was listed under phase 6 and shipped with
Phase 4, because the Officer Menu is where an officer reaches for it.

**Retention is answered** (spec 11.5 #7): five years, in configuration as
`retention.seasons_years`. It bounds what the audit log and the export range
over and never deletes anything — `audit_log` is append-only and is evidence,
and nothing in the application will offer to remove a row from it.

**The export round-trips.** Export Roster writes the spec 6.10.4 columns plus
Phone, Email and Team, so the file it produces goes straight back through
Import Roster. Every free-text cell passes `Csv::guard` on the way out — a
roster row named `=HYPERLINK(...)` must not execute on the machine of exactly
the person the export is for.

The permission matrix from spec 2.2 is encoded once, in `Resm\Auth\Capability`
and `Resm\Auth\Access`, and transcribed a second time in `tests/access_test.php`
so a change has to be made in both places on purpose. A team-scoped capability
requires a named team: asking whether an officer may assign positions without
saying which team is not a question with an answer, and it denies.

### Live updates and offline

There are no WebSockets and no SSE anywhere, and there never will be: LVE caps
concurrent entry processes per account, so held-open connections are not
available on this host (`docs/hosting.md`). Clients short-poll `GET /api/state`
instead. Almost every call is answered "nothing changed", so that path is one
indexed lookup and a 304 with no body — and the team check is folded into the
same statement as the version read, because an authorisation done separately
would double the cost of the one path that has to stay free.

`public/assets/js/poll.js` is the transport and knows nothing about any screen.
Spec 10.2 wants it swappable for Web Push without touching the screens, so the
events it publishes are the contract and the `setTimeout` underneath them is
not.

**A stale screen must never look live.** `poll.js` seeds its freshness clock
from the server's own render time, not from when the script ran — the two are
the same on a page from the network and hours apart on one the service worker
served from cache. Anything that caches a page has to keep that true.

**The service worker's cache version comes from the assets' own mtimes**, so a
deploy renames every cache and `activate` deletes what came before. `sw.js` is
served from `public/` (not `assets/`) so its scope is the whole app, and
`public/.htaccess` exempts it from the one-year expiry every other script gets.
Both failures are silent, and both are tested.

**Only three writes may be recorded late** — check in, check out, lunch —
and a form is queued only if it carries `data-offline`. Officer assignment
writes are never optimistic (spec 10.3, 10.4): two officers assigning at once
is resolved by the server and by the unique indexes on `assignment`, and a
phone in a pocket cannot take part in that. `tests/replay_test.php` pins the
complete list of queueable forms.

**A queued event keeps the moment of the tap**, not the moment it synced.
`occurred_at` is the device's clock, `recorded_at` is the server's, and the gap
between them is the only thing that exposes a handset set wrong. A claimed time
is clamped to the shift and to now, and the raw claim goes to the audit log, so
neither the counts nor the record lies.

### Still owed by Rodeo Express

The tarmac map SVG has the longest lead time of anything outstanding — 98
positions have to be marked on a site plan first. The viewer is built and ships
a plainly-labelled placeholder; the drawing drops in at
`public/assets/map/tarmac.svg` with each position's element id matching its
`map_ref`, and nothing else changes. There is deliberately no drawn-in tarmac:
a made-up layout would look finished and send a new committeeman confidently to
the wrong place.

Also owed: the "What's this?" position definitions (`position.definition`) and
the Rodeo Information copy (spec 6.8), both of which the screens already make
room for.
