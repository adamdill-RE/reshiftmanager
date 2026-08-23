# Rodeo Express Shift Management App

## Deployment target
Ahosting Reseller Gold — cPanel, LiteSpeed (LSAPI), CloudLinux+CageFS.
PHP 8.x, MySQL. NO Node build step. NO Composer deps unless unavoidable.
Code deploys by file copy — nothing may require a build to run.
Server sh193 · cPanel 136.0 · MariaDB 10.11.18 (cll-lve) · EL9 · x86_64.
LiteSpeed/LSAPI confirmed (SAPI reports `litespeed`); cPanel's "Apache 2.4.68"
is the config LSWS reads, not the server. PHP 8.2.33. No OPcache, no intl,
no sodium. Full runtime measurements: docs/hosting.md
Live URL: https://www.reshiftmanager.com/resm/
The app is served from the /resm/ subpath, not the domain root — public/ maps
to public_html/resm/. Never hard-code site-root paths: every internal link,
form action, redirect, asset URL and cookie path must be relative or built
from a configured base path of /resm/.

## Hard constraints
- No WebSockets or SSE — CloudLinux LVE caps entry processes. Polling only.
- No localStorage for auth state. Sessions are HttpOnly cookies.
  Host defaults are unsafe — cookie_httponly, cookie_secure, samesite and
  use_strict_mode are all off, and cookie_path is /. Set every one explicitly
  before session_start(); path must be /resm/.
- The 90-day "keep me signed in" cannot be a PHP session (gc_maxlifetime is
  1440s and GC is not ours on shared hosting). Use a DB-backed rotating token.
- Every DB call via PDO prepared statements. No string-built SQL, ever.
- Role + team scope enforced server-side on EVERY request.
- app/ is never web-accessible. Only public/ ships to public_html.
  DOCUMENT_ROOT is /home/reshiftmanager/public_html and the app lives at
  public_html/resm/, so app/ must sit OUTSIDE public_html entirely — a
  sibling like /home/reshiftmanager/resm-app/, reached by filesystem path.
- Server dirs 0755, files 0644. A 0700 dir yields 404, not 403.

## Design system
Rodeo Orange #EF7622 (accent only — 2.9:1 on white, never white text on it)
Action Orange #B85416 (buttons, white text OK)
Rodeo Brown #7F5E46 (body text) · Dust #C9B29B (surfaces) · Ink #2B2018
Min touch target 56px. PIN keypad 64px. Dark theme required.

## The spec
docs/spec-v2.md is authoritative. Read it before changing assignment logic.
Position matrix: 98 unique positions, 157 position-phase records, 10 groups.

## Commands
Local:  docker compose up -d ; open http://localhost:8080
Migrate: php bin/migrate.php
Deploy: git push, then Deploy HEAD Commit in cPanel
