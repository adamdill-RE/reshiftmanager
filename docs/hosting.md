# Hosting environment

Ahosting Reseller Gold, package 3000. Facts below are read from cPanel's
**Server Information** panel; the snapshot date is noted per section because
some values (service state, load, disk) are point-in-time and some (versions,
paths) only change on a host upgrade.

## Platform — snapshot 2026-08-23

| Item | Detail |
| --- | --- |
| Hosting package | 3000 |
| Server name | sh193 |
| cPanel version | 136.0 (build 35) |
| Web server | **LiteSpeed** (confirmed via `Server:` response header) |
| Apache version reported by cPanel | 2.4.68 — the Apache config LSWS reads, not the running server |
| Database | MariaDB 10.11.18 (`10.11.18-MariaDB-cll-lve`) — **this is the web server's MySQL, not the one the app uses**. See below. |
| Database host | **152.160.193.196** — a separate server, not this one. See below. |
| Architecture | x86_64 |
| Operating system | linux |
| Kernel | 5.14.0-570.19.1.el9_6.x86_64 (EL9 / CloudLinux) |
| Shared IP | 152.160.208.75 |
| Perl | 5.32.1 (`/usr/bin/perl`) |
| Sendmail | `/usr/sbin/sendmail` |

Not reported by this panel and still to be confirmed: the **PHP version**.
`CLAUDE.md` assumes PHP 8.x — check cPanel → MultiPHP Manager for the version
bound to the domain, and MultiPHP INI Editor for limits. The handler is
expected to be LSAPI (`lsphp`) given LiteSpeed; `phpinfo()`'s **Server API**
row reads `litespeed` when that is so.

## The database is on a different server — confirmed 2026-08-24

`db.host` is **152.160.193.196**, not `localhost` and not `127.0.0.1`. Ahosting
runs MySQL on separate hardware from the web server, which is invisible from
cPanel's Databases pages: they read exactly as they would on a single-server
account.

Point the app at this machine instead and you reach a MySQL instance that is
not yours. It answers:

```
SQLSTATE[HY000] [1524] Plugin 'unix_socket' is not loaded
```

That reads like a credentials problem and is not one. It is a different server
refusing an account it has never heard of. Two things follow, both of which
cost time before this was understood:

- **No password reset will fix it.** MariaDB rejects `SET PASSWORD` outright
  for an account whose plugin is `unix_socket`, so cPanel's Change Password
  reports success while changing nothing.
- **Neither will recreating the database user.** The account was never the
  problem, and a new one on the same wrong server behaves identically.

The host to use is the one cPanel shows under **Remote MySQL** — an IP
address here rather than a hostname.

### The engine is therefore unknown — assume either

Every version fact this panel reports about the database describes the MySQL
running on the *web* server. The application never talks to that instance, so
`10.11.18-MariaDB-cll-lve` says nothing about the engine behind
152.160.193.196. It has not been measured.

That is not a footnote. MariaDB and MySQL disagree on things this schema
depends on, and the first one bit already: a column that a **STORED** generated
column is computed from cannot carry `ON DELETE CASCADE` under MySQL — error
1215, the table simply will not create — while MariaDB accepts it. The
`assignment` table used exactly that shape, passed a MariaDB-only pipeline, and
failed on the real server.

Both keys are now `VIRTUAL`, which both engines accept, and CI runs the whole
suite against MariaDB 10.11 **and** MySQL 8.0. Until the real engine is
measured, treat portability between the two as a requirement rather than a
nicety. Reading `SELECT VERSION()` from the app against the real host would
settle it.

## What this constrains in the code

- **MariaDB 10.11** supports CTEs, window functions, `JSON` functions and
  `RETURNING`. It is not MySQL 8 — no `SELECT ... FOR UPDATE SKIP LOCKED`
  before 10.6 (available here), and `utf8mb4` collation defaults differ.
  Target `utf8mb4_unicode_ci` explicitly rather than relying on the default.
- **`-cll-lve`** in the version string confirms CloudLinux LVE. Per-account
  entry-process and CPU caps are real: they are the reason the spec forbids
  WebSockets and SSE, and the reason polling intervals must stay modest.
- **EL9 / kernel 5.14** means a modern OpenSSL and systemd userland, but
  nothing here is directly reachable — this is shared hosting, no root.

## Service state — snapshot 2026-08-23

All services reported `up` and ok except one:

| Service | Status |
| --- | --- |
| `apache_php_fpm` | **down** — "is reporting warnings" |

Every other monitored service was up: `cpanel_php_fpm`, `cpanellogd`, `cpdavd`,
`cpsrvd`, `crond`, `dnsadmin`, `exim` (4.99.5-1.cp130~el9), `ftpd`,
`httpd` (2.4.68), `imap`, `ipaliases`, `lmtp`, `mailman`,
`mysql` (10.11.18-MariaDB-cll-lve), `named`, `nscd`, `p0f`, `pop`,
`queueprocd`, `rsyslogd`, `spamd`, `sshd`.

`apache_php_fpm` down is expected here, not a fault: LiteSpeed runs PHP through
LSAPI, so Apache's PHP-FPM has nothing to serve. Do not "fix" it.

## Host capacity — snapshot 2026-08-23

Server load 2.03 across 40 CPUs; memory 20.13% used; swap 15.52%.
Disks: `/` 10%, `/tmp` 6%, `/boot` 36%, `/var/tmp` 6%, `/boot/efi` 1%.

These describe the shared host, not this account's LVE allowance. Account-level
limits (entry processes, CPU, I/O, inodes) are the ones that will bite — read
them from cPanel's **Resource Usage** page, not from here.

## Web server: LiteSpeed — resolved 2026-08-23

`curl -sI https://www.reshiftmanager.com/resm/` returns `server: LiteSpeed`,
confirming the LiteSpeed/LSAPI target described in `CLAUDE.md`.

The cPanel panel reads as though this were Apache, and that is misleading in
three places at once. LSWS is a drop-in Apache replacement: it parses Apache's
`httpd.conf`, so cPanel reports **Apache 2.4.68** as the config version; it
answers chkservd's service probe, so **`httpd` shows up**; and it serves PHP
over LSAPI rather than Apache's FPM, which is why **`apache_php_fpm` is the one
service reported down**. Read together the panel looks like Apache; the
response header is what actually settles it.

Practical consequences:

- `.htaccess` is honoured — LSWS reads Apache-syntax rewrite rules — but
  LiteSpeed-specific and mod_php-specific directives are not interchangeable.
  Anything wrapped in `<IfModule mod_php*>` will never fire under LSAPI.
- PHP settings go through `php.ini` / MultiPHP INI Editor, not `php_value`
  lines in `.htaccess`, which LSAPI ignores.
- LSAPI keeps PHP workers alive between requests, so `opcache` and any
  process-level state persist across requests within a worker's lifetime.
  A file-copy deploy may not take effect until opcache revalidates.

## PHP runtime — measured 2026-08-23

Read from a temporary diagnostic script at `public_html/resm/`, since cPanel's
Server Information panel reports no PHP details. The script was removed after.

| | |
| --- | --- |
| PHP version | 8.2.33 |
| SAPI | `litespeed` — LSAPI confirmed, matching the LiteSpeed finding above |
| `SERVER_SOFTWARE` | LiteSpeed |
| Document root | `/home/reshiftmanager/public_html` |
| App directory | `/home/reshiftmanager/public_html/resm` |
| TLS | `HTTPS=on`, port 443 |
| Timezone | UTC |
| Error log | `/home/reshiftmanager/logs/php.error.log` (`display_errors` off, `log_errors` on) |

### Where application code must live

`DOCUMENT_ROOT` is `public_html` itself, and the app sits one level inside it at
`public_html/resm/`. Everything under `public_html` is therefore web-reachable,
including anything placed beside `resm/`. To satisfy the rule that `app/` is
never web-accessible, non-public code has to live **outside** `public_html`
entirely — a sibling such as `/home/reshiftmanager/resm-app/` — and be reached
by filesystem path from `public_html/resm/`. Putting `app/` inside `resm/` and
relying on `.htaccess` to hide it is strictly weaker and easy to get wrong.

### Extensions

Present: `pdo`, `pdo_mysql`, `mysqlnd`, `mbstring`, `json`, `openssl`,
`session`, `curl`, `fileinfo`, `zip`, `gd`.

Absent, and worth knowing before writing code against them:

- **`intl` — not available.** No `IntlDateFormatter`, `NumberFormatter` or
  `Collator`. Format dates and times with `DateTime`/`DateTimeImmutable` and
  `IntlChar`-free code.
- **`sodium` — not available.** No `sodium_crypto_*`. Use `random_bytes()` for
  token generation and `hash_hmac()`/`hash_equals()` for signing and
  comparison; both are core and always present.
- **OPcache — not installed.** Two consequences, one good and one not: a
  file-copy deploy takes effect on the very next request with no revalidation
  lag, and every request recompiles every PHP file it touches. Worth asking
  the host to enable `ea-php82-php-opcache` before the season; if it is
  enabled later, `opcache.validate_timestamps` becomes a deploy concern.

### Password and PIN hashing

`bcrypt`, `argon2i` and `argon2id` are all available; `PASSWORD_DEFAULT` is
bcrypt (`2y`).

Argon2id is the stronger algorithm but its PHP default `memory_cost` is 64 MB
**per hash operation**, against a `memory_limit` of 128 MB and a CloudLinux LVE
cap on the account as a whole. Shift start is precisely when 25–100 people log
in within a few minutes, so concurrent hash verifications are the expected
case, not the edge case. Either use bcrypt at cost 11–12, or argon2id with
`memory_cost` reduced to 16–32 MB. Measure before committing to a value.

Note separately that a numeric PIN is low-entropy whatever the hash: rate
limiting and lockout do the real work here, and the hash only protects the
database if it leaks.

### Session configuration — defaults are unsafe, override every one

The stock settings on this host conflict with the spec in five places:

| Setting | Host default | Required |
| --- | --- | --- |
| `session.cookie_httponly` | **off** | on — the spec mandates HttpOnly |
| `session.cookie_secure` | **0** | 1 — the site is HTTPS-only |
| `session.cookie_samesite` | **unset** | `Lax` |
| `session.cookie_path` | **`/`** | `/resm/` — scope the cookie to the app |
| `session.use_strict_mode` | **0** | 1 — rejects attacker-supplied session ids |

Set them explicitly with `session_set_cookie_params()` and `ini_set()` before
`session_start()`; do not rely on host configuration, which can change under
you on a shared box.

`session.save_path` is `/var/cpanel/php/sessions/ea-php82`, a cPanel-wide
directory. CageFS isolates accounts from each other, but pointing the app at a
private path outside the document root is cheap insurance and makes the
lifetime ours to control.

### The 90-day session cannot be a PHP session

`session.gc_maxlifetime` is 1440 seconds — 24 minutes. The spec's "keep me
signed in" issues a 90-day rolling session, and no PHP session will survive
that: garbage collection on a shared host is not ours to govern, and raising
`gc_maxlifetime` on a shared save path does not reliably extend anything.

Implement persistent login as a separate DB-backed token: a random
`random_bytes(32)` selector plus verifier, stored hashed, in a long-lived
cookie scoped to `/resm/` with `HttpOnly`, `Secure` and `SameSite=Lax`,
rotated on each use. The PHP session then stays short and carries only the
live request's identity.

### Limits

| Setting | Value | Relevance |
| --- | --- | --- |
| `memory_limit` | 128M | Interacts with argon2id above |
| `max_execution_time` | 30s | Roster imports must stay well inside it |
| `post_max_size` | 8M | |
| `upload_max_filesize` | 2M | Fine for a roster CSV and an SVG site map |
| `max_input_vars` | **1000** | A bulk form posting all 98 positions at once can exceed this, and PHP **truncates silently** — no error, just missing fields. Assign per-position, or chunk the form |
| `default_charset` | UTF-8 | |

### Time

`date.timezone` is UTC. Store and compare in UTC; convert to
`America/Chicago` only for display. Houston observes DST, so a shift crossing
02:00 in March needs the conversion done with a real timezone, never a fixed
offset.

### File permissions on this host

Directories **0755**, files **0644**. `public_html` itself is cPanel's
`0750` and should be left alone.

A directory at 0700 produces a **404, not a 403**, on files inside it — the
web server cannot traverse in, and LiteSpeed declines to reveal whether the
target exists. This cost an hour once; if a file you can see in File Manager
404s, check the directory's mode first. Watch what modes arrive after a
cPanel "Deploy HEAD Commit".
