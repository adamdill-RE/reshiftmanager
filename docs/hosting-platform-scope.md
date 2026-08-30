# Hosting platform — scope for a new project

**Ahosting Reseller Gold, package 3000 · server sh193 · cPanel 136.0 · CloudLinux EL9 · LiteSpeed/LSAPI · PHP 8.2.33 · MySQL 8.0.41 on a separate host**

This document describes the *hosting account*, not any one application. It is
the environment RESM (Rodeo Express Shift Management,
`https://www.reshiftmanager.com/resm/`) was built against and measured on, and
the same environment RERM is developed against. Nothing here is application
logic, so a third application on the same reseller plan inherits all of it
unchanged.

Read it before choosing an architecture. Roughly a third of these facts
*remove* an option — no build step, no held-open connections, no shell — and
three of them (which server the database is on, which engine it runs, and the
0700 → 404 rule) each cost a day to discover the hard way.

## How to use this file

1. Copy it into the new repository as `docs/hosting.md` and make it the
   authority there.
2. Fill in the per-application blanks in [§11](#11-per-application-blanks):
   subpath, directories, database name, cookie name, config prefix.
3. Re-measure anything marked *point-in-time* and everything in
   [§12](#12-what-is-still-unverified) against the new app's own subpath and
   database before the design is fixed. A temporary diagnostic script placed
   under the new app's public directory and deleted afterwards is how every
   "Measured" row below was obtained — this account has no shell, so that is
   the only instrument available.

Every fact carries its provenance:

| Label | Means |
| --- | --- |
| **Measured** | Read from the running server, on the date given. Trust it. |
| **Reported** | Read from cPanel's Server Information panel. It describes the *web* server; §2 is where that actively misleads. |
| **Unverified** | Not tested here. Treat as a scoping question, not a fact. |

---

## 1. Platform at a glance

| Item | Value | Provenance |
| --- | --- | --- |
| Hosting package | Ahosting Reseller Gold, package 3000 | Reported 2026-08-23 |
| Server name | sh193 | Reported 2026-08-23 |
| cPanel | 136.0 (build 35) | Reported 2026-08-23 |
| OS / kernel | Linux, EL9 / CloudLinux, `5.14.0-570.19.1.el9_6.x86_64`, x86_64 | Reported 2026-08-23 |
| Web server | **LiteSpeed**, PHP over **LSAPI** | **Measured** 2026-08-23 (`Server:` header; `PHP_SAPI === 'litespeed'`) |
| PHP | **8.2.33** | **Measured** 2026-08-23 |
| Application database | **MySQL 8.0.41** at **152.160.193.196** — a different machine | **Measured** 2026-08-24 (`SELECT VERSION()`) |
| Document root | `/home/<account>/public_html` | **Measured** 2026-08-23 |
| PHP error log | `/home/<account>/logs/php.error.log` (`display_errors` off, `log_errors` on) | **Measured** 2026-08-23 |
| Server timezone | UTC | **Measured** 2026-08-23 |
| TLS | `HTTPS=on`, port 443, valid cert on the domain | **Measured** 2026-08-23 |
| Shared IP | 152.160.208.75 | Reported 2026-08-23 |
| Shell access | **None.** No SSH, no cPanel Terminal | **Measured** — the account has neither |
| Host capacity (point-in-time) | load 2.03 / 40 CPUs, memory 20% used, `/` 10% full | Reported 2026-08-23 |

Host capacity describes the shared machine, not this account. The limits that
actually bite are CloudLinux LVE's per-account caps (entry processes, CPU, I/O,
inodes), read from cPanel → **Resource Usage**. See §12.

---

## 2. Three things cPanel reports that are not true of your app

This is the most expensive section in the document. Each item below reads as a
plain fact in the control panel and is wrong in a way that costs a day.

### 2.1 The database is on a different server

`db.host` is **152.160.193.196** — never `localhost`, never `127.0.0.1`.
Ahosting runs MySQL on separate hardware from the web server, and cPanel's
Databases pages give no sign of it: they read exactly as they would on a
single-server account. The correct address is the one under **Remote MySQL**,
and on this plan it is an IP rather than a hostname.

Point an app at `localhost` and it reaches a real MySQL instance that has never
heard of your account. It answers:

```
SQLSTATE[HY000] [1524] Plugin 'unix_socket' is not loaded
```

That reads like a credentials problem and is not one. Two consequences, both of
which burn time before the cause is understood:

- **No password reset fixes it.** MariaDB refuses `SET PASSWORD` outright for
  an account whose plugin is `unix_socket`, so cPanel's Change Password reports
  success while changing nothing.
- **Neither does recreating the database user.** The account was never the
  problem; a new one on the same wrong server behaves identically.

**Scope consequence:** every statement is a network round trip to other
hardware. Design for statement count, not just query cost — see §9.

### 2.2 The engine is MySQL 8.0.41, not the MariaDB cPanel names

cPanel reports `10.11.18-MariaDB-cll-lve`. That is the database on the *web*
server, which the application never talks to. `SELECT VERSION()` from the app
against the real host returns **8.0.41**.

The two engines disagree on things a schema depends on, and the first
disagreement bit before anything had been measured: under MySQL, a column that
a **STORED** generated column is computed from cannot carry `ON DELETE
CASCADE` — error 1215, and the table simply will not create. MariaDB accepts
the same shape. A schema that passed a MariaDB-only pipeline failed on the real
server.

**Scope consequences:**

- No `RETURNING` — that is a MariaDB extension. An insert that needs its own
  row back takes a second statement.
- Available and worth designing around: CTEs, window functions, `JSON`
  functions, `SELECT … FOR UPDATE SKIP LOCKED`.
- Collation defaults differ (`utf8mb4_0900_ai_ci` here), so name
  `utf8mb4_unicode_ci` explicitly on every table and assert it in a test.
- Generated columns: prefer `VIRTUAL`, which both engines accept.
- CI must run against **MySQL 8.0**. Adding MariaDB 10.11 alongside costs
  almost nothing and is worth it on a reseller plan whose database host is not
  yours to control.

### 2.3 It is LiteSpeed, not Apache — and one "down" service is correct

cPanel reports Apache 2.4.68, shows `httpd` up, and shows `apache_php_fpm`
**down**. All three are artefacts of LSWS being a drop-in Apache replacement:
it parses Apache's `httpd.conf` (so cPanel reports that config's version), it
answers chkservd's probe (so `httpd` appears up), and it serves PHP over LSAPI
rather than Apache's FPM (so FPM has nothing to serve). The response header
`server: LiteSpeed` is what settles it.

**Do not "fix" `apache_php_fpm`.** It is down because it is not in use.

**Scope consequences:**

- `.htaccess` is honoured — LSWS reads Apache-syntax rewrites.
- `php_value` / `php_flag` lines in `.htaccess` are **ignored**, and anything
  inside `<IfModule mod_php*>` never fires. PHP settings go through MultiPHP
  INI Editor, or `ini_set()` in code.
- LSAPI keeps PHP workers alive between requests, so process-level state
  persists within a worker's lifetime.

---

## 3. Hard constraints — what this platform removes from the design

These are the ones that change an architecture, not a line of code.

| Constraint | Why | What scope must assume |
| --- | --- | --- |
| **No WebSockets, no SSE** | CloudLinux LVE caps concurrent entry processes per account; a held-open connection occupies one for its lifetime | Short polling, answered fast (a 304 with no body). Design the transport so it can be swapped for Web Push later without touching screens |
| **No build step** | Deployment is a file copy; nothing on the server runs a bundler | No Node, no bundler, no transpile. Ship the files that run. Server-rendered HTML + plain CSS/JS |
| **No Composer dependencies** (unless unavoidable) | Nothing installs packages on the server | Own autoloader over a PSR-4-ish layout; standard library only |
| **No shell** | The account has neither SSH nor cPanel Terminal | Every administrative action needs a browser route or a cPanel UI path — migrations included. See §6.3 |
| **No root, no service control** | Shared hosting | Nothing may depend on a daemon, a systemd unit, or an installed binary |
| **No OPcache** | Not installed on this host (**Measured** 2026-08-23) | Every request recompiles every file it touches. Keep the per-request file count modest. Upside: a file-copy deploy takes effect on the next request with no revalidation lag |
| **No `intl`** | Extension absent | No `IntlDateFormatter`, `NumberFormatter`, `Collator`. Format with `DateTime`/`DateTimeImmutable` |
| **No `sodium`** | Extension absent | `random_bytes()` for tokens, `hash_hmac()`/`hash_equals()` for signing and comparison — all core |
| **No `RETURNING`** | MySQL, not MariaDB | Insert-then-select where a new id is needed beyond `lastInsertId()` |
| **Remote database** | Separate hardware | Latency scales with *statement count* per request |
| **App code must live outside `public_html`** | `DOCUMENT_ROOT` is `public_html` itself | A sibling directory reached by filesystem path. See §5 |
| **Long-lived login cannot be a PHP session** | `session.gc_maxlifetime` is 1440 s and GC belongs to the host | DB-backed rotating token. See §8.3 |

---

## 4. PHP runtime

**Measured 2026-08-23** from a temporary diagnostic script under the app's
public directory, removed afterwards.

| | |
| --- | --- |
| Version | 8.2.33 |
| SAPI | `litespeed` |
| `SERVER_SOFTWARE` | LiteSpeed |
| `date.timezone` | UTC |
| `default_charset` | UTF-8 |
| `display_errors` | off · `log_errors` on |

### Extensions

**Present:** `pdo`, `pdo_mysql`, `mysqlnd`, `mbstring`, `json`, `openssl`,
`session`, `curl`, `fileinfo`, `zip`, `gd`.

**Absent:** `intl`, `sodium`, OPcache. (See §3 for what each removes.)

### Limits

| Setting | Value | Relevance to scope |
| --- | --- | --- |
| `memory_limit` | 128M | Interacts with argon2id — see §8.4 |
| `max_execution_time` | 30 s | Any import, export or report must finish well inside it, or be chunked |
| `post_max_size` | 8M | |
| `upload_max_filesize` | 2M | Fine for a CSV or an SVG; not for photo upload |
| `max_input_vars` | **1000** | **PHP truncates silently past this** — no error, just missing fields. A bulk form posting ~100 rows of several fields each will exceed it. Submit per-row, or chunk the form |
| `session.gc_maxlifetime` | 1440 s | See §8.3 |
| `session.save_path` | `/var/cpanel/php/sessions/ea-php82` (cPanel-wide) | Point the app at a private path outside the document root instead |

### Time

Server timezone is UTC. **Store and compare in UTC; convert for display only.**
Houston observes DST, so anything crossing 02:00 in March must be converted
with a real timezone (`America/Chicago`), never a fixed offset. Pin the
*database connection's* time zone too, or `NOW()` defaults record whatever the
server feels like:

```php
PDO::MYSQL_ATTR_INIT_COMMAND =>
    "SET SESSION time_zone = '+00:00', "
    . "sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
```

---

## 5. Filesystem layout, and where code is allowed to live

### 5.1 `DOCUMENT_ROOT` is `public_html` itself

Everything under `public_html` is reachable by URL, including anything placed
*beside* the app's directory. The proven layout:

```
/home/<account>/public_html/<app>/     ← the ONLY directory that reaches the web
                                          index.php, .htaccess, assets/, sw.js
/home/<account>/<app>-app/             ← application code, OUTSIDE public_html
                                          app/ db/ bin/ config/ var/
```

Hiding code inside the document root behind an `.htaccess` rule is strictly
weaker and easy to get wrong. Have the app *check* this at runtime and report
it on a status page.

The front controller finds its application root by probing, so the same file
works locally and on the server with nothing to configure:

```php
$candidates[] = getenv('APP_ROOT');                 // explicit override
$candidates[] = dirname(__DIR__);                   // <repo>/        (local)
$candidates[] = dirname(__DIR__, 2) . '/<app>-app'; // ~/<app>-app/   (server)
// first one containing app/bootstrap.php wins
```

### 5.2 The app is served from a subpath, not the domain root

RESM is at `https://www.reshiftmanager.com/resm/`. **Nothing may hard-code a
site-root path.** Every internal link, form action, redirect, asset URL and
cookie path is built from one configured `base_path` (`/resm/`, trailing slash
kept) via helpers — `$app->url(…)`, `$app->asset(…)` — and the value appears in
exactly one file.

Keep this rule even if the new app *does* get the domain root: it costs nothing
up front and makes a later move a config change instead of a grep.

In `.htaccess`, omit `RewriteBase` deliberately. In a per-directory
`.htaccess`, a relative substitution resolves against the directory the file
sits in, so the same rules work at any mount point with no second copy of the
path to keep in sync:

```apache
Options -Indexes
DirectoryIndex index.php
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.php [L]
```

### 5.3 Permissions — and the 0700 → 404 trap

Directories **0755**, files **0644**. `public_html` itself is cPanel's `0750`;
leave it alone.

**A web-reachable directory at 0700 produces a 404, not a 403,** on files
inside it: the web server cannot traverse in, and LiteSpeed declines to reveal
whether the target exists. If a file you can see in File Manager 404s, check
the directory's mode first. This cost an hour once.

0700 *is* correct for directories outside `public_html` (session storage,
import staging) — nothing serves them, and the tighter mode keeps session files
off the cPanel-wide save path shared with every other account's app.

---

## 6. Deployment

### 6.1 The model

`git push` to GitHub, then **Deploy HEAD Commit** in cPanel → Git Version
Control. The server pulls and runs the tasks in `.cpanel.yml`. There is no
build, no artefact, no staging copy of the application.

### 6.2 `.cpanel.yml`, and a parser stricter than most

The file is parsed by the host. **A file it rejects disables deployment
entirely rather than failing loudly**, so validate it in CI. The rules that
bite:

- ASCII only — non-ASCII is rejected.
- No tab characters.
- Tasks must be plain strings.
- **No `{` or `}`** anywhere in a task — they are YAML flow indicators. This
  rules out `find -exec … {} \;`.

What the deploy must do, in order:

1. `mkdir -p` both the app directory and the web directory.
2. `rm -rf` the code directories *before* copying, so a file deleted in git is
   deleted on the server.
3. Copy `app/ db/ bin/` to the app directory, `public/.` to the web directory.
4. Copy the committed config only — **never** the local credentials file.
5. Fix modes in one pass: `chmod -R u=rwX,go=rX <dirs>`. Capital `X` sets the
   execute bit on directories only, so one pass does both and it repairs a
   directory a previous deploy left untraversable. This is only correct
   because every tracked file is git mode `100644` — **have CI assert that**,
   or a file that lands executable is deployed executable and the 0755/0644
   rule silently stops holding.
6. Handle `config/` **non-recursively**: it holds the credentials file the
   deploy did not create and must not loosen. Set the committed config to
   0644, and tighten the local one to 0600 *if present* (guard it — on a first
   deploy it does not exist, and a failing task fails the whole deployment).
7. Create private `var/` directories at 0700.

### 6.3 No shell means migrations run from a browser

`bin/migrate.php` cannot be reached on this account. The pattern that works:

- **`/status?key=…`** — reports the runtime, whether the session cookie really
  is HttpOnly/Secure/SameSite scoped to the subpath, whether any code ended up
  inside the document root, and which migrations are pending. Guarded by a
  `status_key` that stays configured. **Without the key it returns 404, not
  403**, so it gives nothing away.
- **`/setup?key=…`** — applies pending migrations and sets the first
  administrator's credential, because before the migrations run there is no
  user table to log in against. This is a genuine administrative credential:
  whoever holds it can take the master admin account. Add `setup_key` through
  cPanel File Manager only for as long as the migration takes, then **remove
  the line**. With no key configured the route does not exist, and that is the
  state to leave it in.

Migrations are never applied automatically by the deploy.

### 6.4 The credentials file is the one file the deploy does not own

It is gitignored, holds the database password, lives outside `public_html`, and
must survive every deploy.

**It is PHP, and it is edited by hand on a server with no shell to lint it.** A
missing trailing comma is a parse error, and every page returns 500. Have the
bootstrap catch that one class of error and print *which file and which line to
fix* on screen — an administrator who cannot read a log has no other way to
find out why the site went blank. The message must never echo a credential.

---

## 7. Database access

Connection facts are in §2.1–2.2. The connection options that proved right:

```php
new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user, $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // real server-side prepares
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION time_zone = '+00:00', sql_mode = '…'",
    ]
);
```

Notes that matter for scope:

- **Every call via prepared statements with bound parameters. No string-built
  SQL, ever.** With emulation off, a named placeholder **cannot be reused**
  within one statement — PDO maps each name to a single positional marker. Bind
  twice under different names.
- **No persistent connections.** Under LSAPI they would hold a remote-DB
  connection per worker against an account whose entry processes are capped.
  PHP reconnects per request: budget one TCP + auth handshake per request.
- Catch and **re-throw connection failures without the DSN** — it names the
  database and user, and a stack trace in a log should not carry them.
- Charset `utf8mb4` named in the DSN, collation named per table (§2.2).
- **Let the database enforce concurrency rules.** Two people will write at
  once; unique indexes turn that into a duplicate-key error on the losing
  write. Never resolve it by reading first.
- Migrations: numbered `.sql` files, applied once each in order, **immutable
  once applied** (record a checksum and refuse to run if a file changed). MySQL
  commits implicitly on DDL, so only pure-data migrations can be wrapped in a
  transaction.

---

## 8. Sessions and authentication

### 8.1 Every host default is unsafe — override all of them

**Measured 2026-08-23.** Do not inherit any of these; set them explicitly with
`session_set_cookie_params()` and `ini_set()` *before* `session_start()`. They
can also change under you on a shared box.

| Setting | Host default | Required |
| --- | --- | --- |
| `session.cookie_httponly` | **off** | on |
| `session.cookie_secure` | **0** | 1 (HTTPS only; 0 for local http dev) |
| `session.cookie_samesite` | **unset** | `Lax` |
| `session.cookie_path` | **`/`** | the app's subpath, e.g. `/resm/` |
| `session.use_strict_mode` | **0** | 1 — rejects an attacker-supplied session id |
| `session.use_only_cookies` | — | 1, with `use_trans_sid` 0 |
| `session.save_path` | cPanel-wide dir | a private 0700 dir outside `public_html` |

Do **not** set `session.sid_length` / `sid_bits_per_character`: the defaults are
already a secure length and both are deprecated as of PHP 8.4 — setting them
buys nothing and emits a deprecation notice the day the host upgrades.

### 8.2 No auth state in `localStorage`

Sessions are HttpOnly cookies. Nothing that grants access may be readable by
script.

### 8.3 A long-lived login must be a DB-backed rotating token

`gc_maxlifetime` is 1440 s (24 minutes) and garbage collection on a shared host
is not yours to govern; raising it on a shared save path does not reliably
extend anything. So:

- The **PHP session** is short and holds one thing: the id of a server-side
  token row.
- The **token row** is the real session. Because it lives server-side it can be
  revoked — which is what makes "changing your PIN signs out your other
  devices" take effect immediately.
- The cookie is `selector.verifier`: the selector is the indexed lookup key and
  is useless alone; only a SHA-256 of the verifier is stored, compared with
  `hash_equals`. `random_bytes(32)`, `HttpOnly`, `Secure`, `SameSite=Lax`,
  scoped to the subpath. Rotate both on use and push the expiry out — that is
  what makes the window rolling.
- Rotation is a **compare-and-swap**, and only the request that wins the swap
  sends a new cookie. A page load and a poll arriving together both read the
  same valid row; one rotation lands, the other affects no rows and leaves the
  cookie alone, so the browser always ends up holding what the database holds.
- One deliberate departure from the textbook, worth deciding *in scope*: a
  known selector with a wrong verifier **does not** revoke the token family. It
  is the classic theft signal, but an in-flight request that lost a rotation
  race lands in the same branch, and signing a team out mid-shift over a race
  they cannot see is the worse failure. The cookie is refused for that request
  and the mismatch is audited. Revisit if the threat model changes.

### 8.4 Password/PIN hashing: bcrypt, or argon2id with a reduced memory cost

`bcrypt`, `argon2i` and `argon2id` are all available; `PASSWORD_DEFAULT` is
bcrypt (`2y`). Argon2id is stronger, but its PHP default `memory_cost` is
**64 MB per hash operation** against a `memory_limit` of 128 MB and an LVE cap
on the account as a whole. If the app has a login spike — RESM's is 25–100
people signing in within a few minutes at shift start — concurrent hash
verifications are the expected case, not the edge case.

Use **bcrypt at cost 11–12**, or argon2id with `memory_cost` reduced to
16–32 MB. Measure before committing.

A short numeric PIN is low-entropy whatever the hash: **rate limiting and
lockout do the real work**, and the hash only protects the database if it
leaks. Pick lockout numbers against the operational cost of a false positive —
RESM deliberately runs loose (10 attempts / 15 min window / 60 s lockout)
because a locked-out officer at 17:00 is worse than a brute-force attempt on an
internal tool.

### 8.5 Baseline request security

Enforced server-side on **every** request, not by reaching a route: role and
scope checks, `CSRF` token on every POST, output escaping on every rendered
value, and global headers — CSP, `nosniff`, `X-Frame-Options: DENY`,
same-origin referrer, `Cache-Control: no-store` on anything carrying personal
data. Guard CSV exports against formula injection (`=`, `+`, `-`, `@` leading a
cell), or an exported row executes on the machine of the person the export is
for.

---

## 9. Performance envelope

**Measured 2026-08-27** against MySQL 8.0 on loopback, in the server's own
directory layout (app code in a sibling of the document root, `public/` served
from a subpath). Loopback flatters absolute latency, so two numbers are given
and only the second is portable:

- **Measured time** bounds the PHP + query cost.
- **Statement count** per request is the portable number. On the real host each
  statement is one network round trip to separate hardware, so
  `time ≈ measured + statements × RTT`, plus one TCP + auth handshake per
  request (no pooling — see §7).

| Path | Statements | Measured | Sustained (loopback) |
| --- | --- | --- | --- |
| Poll endpoint, unchanged → 304, no body | **3** | p50 5 ms · p95 8 ms | ~550 req/s |
| Poll endpoint, changed → 200 + widget | 8 | p50 5 ms · p95 6 ms | ~590 req/s |
| Heaviest board render (95 rows, 89 KB) | 20 | p50 44 ms · p95 53 ms | — |
| Read-only board (49 KB) | — | p50 18 ms | — |

At a 5 ms RTT the 3-statement poll lands near 25 ms and the 20-statement render
near 200 ms. **Design to the statement count and this arithmetic survives the
move to the real host.**

Patterns that produced those numbers, and are worth carrying over:

- Fold the authorisation check into the same statement as the data read on the
  hot path. An auth query done separately doubles the cost of the one path that
  has to stay free.
- `session_write_close()` before the version read, so polls do not serialise
  behind the screen the user is actually tapping.
- Poll cadence: 10 s foreground, 60 s background, answered 304 with no body.
  ~30 clients ≈ 3 req/s. LVE's entry-process cap is protected by the answers
  being *instant*, not by the interval alone.
- Client shell budget: RESM ships ~27 KB gzipped (CSS + 13 scripts + service
  worker) against a 150 KB target, so the shell is not the bottleneck on a 2 s
  first-contentful-paint on 3G.
- Assets carry a `?v=<mtime>` stamp, so a one-year `Expires` is safe and a
  changed file busts itself. **Exempt the service worker** — it is served from
  the app root (not `assets/`) so its scope is the whole app, and a year-frozen
  worker would keep serving a shell nobody can replace.

---

## 10. CI, given there is no staging

There is no staging copy of the application on this plan: **a bad migration is
discovered on the live site.** CI is the only safety net, so it must reproduce
the host rather than a convenient approximation.

- **PHP 8.2**, matching production. A newer PHP hides a deprecation until it
  reaches the server.
- **MySQL 8.0** as the primary matrix leg (production, measured); MariaDB 10.11
  alongside is nearly free insurance on a plan whose DB host is not yours.
- **No Composer** — nothing to install, nothing to cache.
- Validate `.cpanel.yml` against the host parser's rules (§6.2): ASCII, no
  tabs, plain-string tasks, no braces.
- Assert **every tracked file is git mode `100644`** (§6.2 step 5).
- `php -l` over every PHP file.
- Apply migrations, then apply them again and assert "up to date" — idempotence
  is what makes a browser-run migration safe to retry.
- Run the suite `--strict`.

Locally, mirror the server layout rather than the repository's: `public/`
mounted inside a document root at the app's subpath, application code mounted
at a *sibling*, and a `php.ini` reproducing production's limits. **A constraint
that will bite on the server should bite locally first.**

---

## 11. Per-application blanks

Fill these in for the new app. Anything not listed is account-wide and
inherited from the sections above.

| Value | RESM | New app |
| --- | --- | --- |
| Public URL | `https://www.reshiftmanager.com/resm/` | |
| cPanel account / home | `/home/reshiftmanager` | |
| Web directory | `/home/reshiftmanager/public_html/resm` | |
| App directory (outside `public_html`) | `/home/reshiftmanager/resm-app` | |
| `app.base_path` | `/resm/` | |
| Session cookie name | `RESMSESS` | |
| Session cookie path | `/resm/` | |
| Database host | `152.160.193.196` (account-wide) | same, unless the new app is on a different account |
| Database name / user | `reshiftmanager_resm` (cPanel prefixes both with the account name) | |
| Env var prefix | `RESM_` | |
| Error log | `/home/reshiftmanager/logs/php.error.log` | |
| Status / setup keys | `status_key`, `setup_key` in the gitignored local config | |

**If the new app gets its own cPanel account** under the same reseller plan,
the home directory and the database-name prefix change with it, and it may be
served at that account's domain root. The platform facts (§1–§4, §7–§10) are
unchanged; §5's "app code outside `public_html`" rule still applies, and the
`base_path` discipline is still worth keeping (§5.2).

**If the new app shares this account**, it is another directory beside `resm/`
under the same `public_html`, with its own sibling app directory — and it needs
its own database, its own session cookie name and path, and its own
`.cpanel.yml` in its own repository.

---

## 12. What is still unverified

Each of these is a scoping question. None is answered by anything above, and
guessing at any of them is how §2 happened.

| Question | Why it matters | How to answer it |
| --- | --- | --- |
| **LVE limits for this account** — entry processes, CPU, I/O, inodes | The real ceiling on concurrency; the reason polling is capped | cPanel → **Resource Usage**. Do not infer from server load |
| **RTT from web server to 152.160.193.196** | Turns every statement count in §9 into a real latency | A timed connect + `SELECT 1` from a temporary diagnostic route on the deployed app |
| **Cron availability** | Whether anything scheduled is possible at all — with no shell, cPanel's Cron Jobs would be the only route | cPanel → **Cron Jobs**. `crond` is up on the host, but per-account availability on this plan is untested |
| **Outbound mail** | Whether the app can send anything (resets, notifications) | `exim 4.99.5` runs and `/usr/sbin/sendmail` exists (**Reported**), but no send has been tested from an app here. Test before scoping any email feature |
| **Outbound HTTP** | Whether any third-party API is reachable at all | `curl` is present; egress rules untested |
| **OPcache** | Would remove the per-request recompile | Ask the host to enable `ea-php82-php-opcache`. If it is enabled later, `opcache.validate_timestamps` becomes a deploy concern — a file-copy deploy may not take effect until revalidation |
| **A second database on the same account** | Whether the new app gets its own, and its quota | cPanel → MySQL Databases + Remote MySQL |
| **Whether the new app shares this cPanel account or gets its own** | Decides everything in §11 | Reseller WHM |
| **Backups / restore path** | There is no staging; restore is the recovery plan | cPanel → Backup, and confirm what the host retains |
| Point-in-time rows in §1 (load, disk, service state) | Snapshot only | Re-read at scoping time |

---

## 13. Provenance

| Section | Source | Date |
| --- | --- | --- |
| §1 platform, §2.3 web server | cPanel Server Information + `curl -sI` response header | 2026-08-23 |
| §2.1–2.2 database host and engine | The application itself, against the real host | 2026-08-24 |
| §4 PHP runtime, extensions, limits; §8.1 session defaults | Temporary diagnostic script under the app's public directory, removed after | 2026-08-23 |
| §5.3 permissions and the 0700 → 404 rule | Observed on the live server | 2026-08 |
| §9 performance | Load test in the server's directory layout, MySQL 8.0 on loopback | 2026-08-27 |
| §6 deployment, §7 database access, §8 auth, §10 CI | The RESM implementation as shipped — `.cpanel.yml`, `.github/workflows/ci.yml`, `app/src/{Database,Session}.php`, `public/{index.php,.htaccess}` | 2026-08 |

Fuller narrative versions of §2, §4, §5 and §8 live in RESM's `docs/hosting.md`;
the measurement method and the full load-test tables in `docs/load-testing.md`.
