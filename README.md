# Rodeo Express — Shift Management

Manages the committeemen who run Rodeo Express bus operations: who is on the
grounds, where each person is standing on the tarmac, and pushing that
assignment to their phone the moment it changes.

Server-rendered PHP 8 and MariaDB, no build step, deployed by file copy.
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

Migrations are **not** run automatically. Over SSH or cPanel Terminal:

```sh
cd ~/resm-app
php bin/migrate.php --status
php bin/migrate.php
```

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

**The position matrix is generated, not typed.** `db/migrations/002_seed_reference.sql`
comes from `bin/gen-position-seed.php`, which parses section 8.3 of the spec
and refuses to emit anything unless the counts still come to 98 positions, 157
position-phase records, 22 radio, 23 critical and 3 multi-assign. After
go-live, the Position Matrix Editor (spec 6.10.8) edits those tables directly.

**The database enforces the assignment rules.** Two officers will assign at the
same time. `assignment` carries two unique indexes over generated columns, so
one person cannot hold two positions in a phase and one position cannot hold
two people — except the three multi-assign Unload positions. The losing write
gets a duplicate-key error; it is never resolved by reading first.

**Nothing hard-codes `/resm/`** outside `config/config.php`. Build every URL
with `$app->url(…)` and `$app->asset(…)`.

**Escape every rendered value** with `e()`, and use bound parameters for every
query. There is no exception to either.

## Where this build stands

Phase 1 of the build sequence in spec 11.1 is partly done: schema, seed data
and the scaffolding are in place. **Authentication, the PIN and session model,
and role scaffolding are next** — the `auth_token` and `login_attempt` tables
are already there for it.

Open items in spec 11.5 that this build assumes an answer to, and which should
be confirmed before Phase 4:

- Criticality is seeded identically in both phases, because the spec gives one
  Critical column. Open item 4 asks whether it should differ per phase.
- Default active position groups per shift type are not seeded at all — open
  item 1. Without them the "open positions" count has nothing to scope to.
