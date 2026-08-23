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
| Apache version | 2.4.68 |
| Database | MariaDB 10.11.18 (`10.11.18-MariaDB-cll-lve`) |
| Architecture | x86_64 |
| Operating system | linux |
| Kernel | 5.14.0-570.19.1.el9_6.x86_64 (EL9 / CloudLinux) |
| Shared IP | 152.160.208.75 |
| Perl | 5.32.1 (`/usr/bin/perl`) |
| Sendmail | `/usr/sbin/sendmail` |

Not reported by this panel and still to be confirmed: the **PHP version and
handler**. `CLAUDE.md` assumes PHP 8.x — check cPanel → MultiPHP Manager for
the version actually bound to the domain, and MultiPHP INI Editor for limits.

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

`apache_php_fpm` down is commonly benign on a shared box — cPanel reports it
that way when no domain on the server is configured to use PHP-FPM. It matters
only if this app is meant to run under PHP-FPM; if pages 500 or PHP fails to
execute, check this first.

## Host capacity — snapshot 2026-08-23

Server load 2.03 across 40 CPUs; memory 20.13% used; swap 15.52%.
Disks: `/` 10%, `/tmp` 6%, `/boot` 36%, `/var/tmp` 6%, `/boot/efi` 1%.

These describe the shared host, not this account's LVE allowance. Account-level
limits (entry processes, CPU, I/O, inodes) are the ones that will bite — read
them from cPanel's **Resource Usage** page, not from here.

## Open question: web server

cPanel reports **Apache 2.4.68** with `httpd` up, while `CLAUDE.md` describes
the target as LiteSpeed with LSAPI. These are usually distinguishable: a
LiteSpeed box normally lists an `lshttpd` service and reports Apache's version
only for compatibility. Confirm which is serving before relying on either
server's specific behaviour — it changes `.htaccess` handling, how PHP is
invoked, and which performance directives do anything at all.
