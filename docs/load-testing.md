# Load testing and hardening — Phase 6

Measured 27 August 2026, against the spec 10.6 targets. Method first, then
numbers, then what they mean for the real host.

## Method, and one honest caveat

The sandbox this ran in cannot reach the production database host
(152.160.193.196), so everything below was measured against MySQL 8.0 on
loopback. That flatters absolute latency — production pays a network round
trip per statement that loopback does not — so two kinds of numbers are
reported:

1. **Measured times** on loopback, which bound the PHP + query cost.
2. **Statement counts** per request, taken from the MySQL general log. These
   are the portable number: on the real host, each statement is one network
   round trip to separate hardware, so `time ≈ measured + statements × RTT`
   (plus one TCP + auth handshake per request — PHP reconnects per request;
   there is no connection pooling under LSAPI without persistent connections,
   which we deliberately do not use on shared hosting).

The layout was the server's, not the checkout's: the repo copied to a
`resm-app/` directory, `public/` copied to a sibling `public_html/resm/`,
served by `php -S` (8 workers) through a router that forwards only `/resm/`
and serves assets with real content types. Fixture: an active season, one
team, a shift running now in Bump and Run, 95 committeemen checked in and
assigned — one per position — which is spec 10.6's heaviest case.

## The polling endpoint — the hot path

Spec 10.2's design load is ~30 clients at 10 s foreground, about **3
requests/second**, nearly all answered 304.

| Path | Statements | Measured (c=3) | Sustained capacity |
| --- | --- | --- | --- |
| `GET /api/state` unchanged → 304, no body | **3** | p50 5 ms · p95 8 ms | ~550 req/s |
| `GET /api/state` changed → 200 + widget | 8 | p50 5 ms · p95 6 ms | ~590 req/s |

The three statements on the 304 path: resolve the session's identity (token +
user), load team ids, and the version read with the team check folded into
the same statement. At 3 req/s the endpoint uses roughly half a percent of
what one loopback box sustains; on the real host the same requests cost
3 round trips + handshake each, so even a 5 ms RTT to the database keeps a
poll around 25 ms — far inside the 10 s budget, and LVE's entry-process cap
is protected by the answers being instant.

Polling latency was also measured while two clients hammered the assign
board concurrently: p95 stayed at 6 ms. The session is closed
(`session_write_close`) before the version read, so polls do not serialise
behind the screen the user is actually tapping — that design decision holds
under load.

## The assign board — the heaviest render

Spec 10.6 target: render 95 positions in under 1 second.

| Page | Statements | Payload | Measured (c=2) |
| --- | --- | --- | --- |
| `officer/assign/bump_run`, 95 filled | 20 | 89 KB | p50 44 ms · p95 53 ms |
| `officer/board/bump_run` (read-only) | — | 49 KB | p50 18 ms |

With 20 statements the remote-DB surcharge is ~20 × RTT; at a 5 ms RTT that
is ~150 ms on top, still 6× inside the target.

## Application shell

Spec 10.6 target: under 150 KB gzipped. Measured: **~27 KB** gzipped for
`app.css` + all thirteen scripts + `sw.js` (8.3 KB CSS, 15.1 KB JS, 3.3 KB
service worker). The 2.0 s first-contentful-paint on 3G target is dominated
by that payload and one HTML round trip; at 27 KB the shell is not the
bottleneck.

## Hardening checks run over HTTP

Driven with curl against the server layout, as four principals — anonymous,
committeeman, officer, admin — against every Phase 6 screen
(`admin/export`, `admin/export/csv`, `admin/matrix`, `admin/matrix/edit`,
`admin/audit`):

- Anonymous → 302 to login; committeeman and officer → 403, on the CSV
  endpoint itself as well as the pages. Admin → 200.
- `POST /admin/audit` → 405: no write route for the audit screen exists.
- `POST /admin/matrix` without a CSRF token → 400, and with an officer's
  session → 403; both left the position table untouched (verified by count).
- Export CSV of a non-existent or out-of-retention shift → 404.
- Every free-text cell in the export passes `Csv::guard`, so a roster row
  named `=HYPERLINK(...)` displays as text in Excel instead of executing
  on the Admin's machine. Phone numbers keep their leading `+`.
- The CSV response carries `Cache-Control: no-store` (personal data does not
  belong in any cache, spec 10.5) and rides the same global headers as every
  response: CSP, nosniff, DENY, same-origin referrer.

## What was not measured, and how to measure it there

The two numbers that only the real host can produce:

1. **RTT to 152.160.193.196** from the web server. `/resm/status` already
   proves connectivity; the statement counts above turn any measured RTT
   into a latency estimate without re-running the suite.
2. **LVE behaviour at concurrency** — entry-process limits are CloudLinux's,
   not PHP's. The 304 path answering in single-digit milliseconds is the
   design defence: connections are released before the next poll arrives.
