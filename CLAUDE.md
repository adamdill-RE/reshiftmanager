# Rodeo Express Shift Management App

## Deployment target
Ahosting Reseller Gold — cPanel, LiteSpeed (LSAPI), CloudLinux+CageFS.
PHP 8.x, MySQL. NO Node build step. NO Composer deps unless unavoidable.
Code deploys by file copy — nothing may require a build to run.

## Hard constraints
- No WebSockets or SSE — CloudLinux LVE caps entry processes. Polling only.
- No localStorage for auth state. Sessions are HttpOnly cookies.
- Every DB call via PDO prepared statements. No string-built SQL, ever.
- Role + team scope enforced server-side on EVERY request.
- app/ is never web-accessible. Only public/ ships to public_html.

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
