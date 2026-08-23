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
| Database | MariaDB 10.11.18 (`10.11.18-MariaDB-cll-lve`) |
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
