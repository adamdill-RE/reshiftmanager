# RODEO EXPRESS

Shift Management Application  
**Functional & Technical Specification**  
Version 2.0  ·  August 2026

| Field | Value |
| --- | --- |
| Document version | 2.0 — supersedes v1.0 "Team A Rodeo Shift Management App" |
| Target platform | Mobile-first Progressive Web App (PWA) |
| Hosting | Ahosting Reseller — Gold (WHM/cPanel, LiteSpeed, CloudLinux, free SSL, dedicated IP) |
| Stack | PHP 8.x + MySQL/MariaDB, server-rendered, vanilla JS, no build step |
| Target availability | Operational build within 30 days; production-hardened before Rodeo season |
| Peak concurrency | ~30 active clients (8 officers + 10–20 committeemen) |
| Roster scale | Up to 100 committeemen per team |
| Position records | 98 unique positions · 157 position-phase records · 10 groups |

## Contents

- [1. Purpose & Operating Context](#1-purpose--operating-context)
  - [1.1 What this application does](#11-what-this-application-does)
  - [1.2 The operating environment drives every design decision](#12-the-operating-environment-drives-every-design-decision)
  - [1.3 Shift phases](#13-shift-phases)
- [2. Users, Roles & Permissions](#2-users-roles--permissions)
  - [2.1 Roles](#21-roles)
  - [2.2 Permission matrix](#22-permission-matrix)
- [3. Authentication & Session Model](#3-authentication--session-model)
  - [3.1 Credentials](#31-credentials)
  - [3.2 Login screen](#32-login-screen)
  - [3.3 Session behaviour](#33-session-behaviour)
- [4. Data Model](#4-data-model)
  - [4.1 Time handling](#41-time-handling)
- [5. Shift Model](#5-shift-model)
  - [5.1 Shift types](#51-shift-types)
  - [5.2 Phase toggling — resolved rule](#52-phase-toggling--resolved-rule)
  - [5.3 Shift visibility window](#53-shift-visibility-window)
  - [5.4 Active groups per shift](#54-active-groups-per-shift)
- [6. Screen Specifications](#6-screen-specifications)
  - [6.1 Login](#61-login)
  - [6.2 Main Menu](#62-main-menu)
  - [6.3 Status widget (persistent)](#63-status-widget-persistent)
  - [6.4 Check In / Out](#64-check-in--out)
  - [6.5 My Shift Status](#65-my-shift-status)
  - [6.6 My Shifts](#66-my-shifts)
  - [6.7 Tools](#67-tools)
  - [6.8 Rodeo Information](#68-rodeo-information)
  - [6.9 Officer Menu](#69-officer-menu)
  - [6.10 Admin Menu](#610-admin-menu)
- [7. Skills & Certifications](#7-skills--certifications)
- [8. Position Matrix](#8-position-matrix)
  - [8.1 Group summary](#81-group-summary)
  - [8.2 Attribute definitions](#82-attribute-definitions)
  - [8.3 Full position list](#83-full-position-list)
- [9. Visual Design System](#9-visual-design-system)
  - [9.1 Colour palette and contrast](#91-colour-palette-and-contrast)
  - [9.2 Dark theme](#92-dark-theme)
  - [9.3 Touch and typography](#93-touch-and-typography)
- [10. Technical Architecture](#10-technical-architecture)
  - [10.1 Platform](#101-platform)
  - [10.2 Live updates](#102-live-updates)
  - [10.3 Offline behaviour](#103-offline-behaviour)
  - [10.4 Concurrency](#104-concurrency)
  - [10.5 Security](#105-security)
  - [10.6 Performance targets](#106-performance-targets)
- [11. Scope, Deliverables & Open Items](#11-scope-deliverables--open-items)
  - [11.1 Build sequence](#111-build-sequence)
  - [11.2 Explicitly out of scope for v1](#112-explicitly-out-of-scope-for-v1)
  - [11.3 Deliverables owed by Rodeo Express](#113-deliverables-owed-by-rodeo-express)
  - [11.4 Tarmac map — what to supply](#114-tarmac-map--what-to-supply)
  - [11.5 Open items requiring a decision](#115-open-items-requiring-a-decision)

*(In Word: right-click the field above and choose "Update Field" to populate.)*

## 1. Purpose & Operating Context

### 1.1 What this application does

Rodeo Express transports guests to and from the Rodeo grounds using coach-style buses and Metro buses. This application manages the people who run that operation: it creates teams, loads their rosters, tracks who is physically on the grounds, assigns each person to a specific physical position on the tarmac, and pushes that assignment to their phone the moment it changes.

A shift runs 25–100 committeemen. The application replaces clipboards, radio roll-calls, and shouted position changes.

### 1.2 The operating environment drives every design decision

This is not an office application. Three environmental facts govern the entire design and should be treated as hard requirements, not preferences:

- **Everyone is outdoors, in weather.** Houston in February and March means cold, wind, and rain. Users will be operating this with numb fingers, wet screens, and possibly gloves. Every interactive target must be large, high-contrast, and forgiving of imprecise taps.
- **Everyone is on a phone.** 99% of all usage — committeeman and officer alike — is mobile. Desktop is a convenience for the Admin, not a design target. Officer assignment screens in particular must be genuinely usable one-handed on a phone.
- **The network will fail.** With 70,000+ people on the grounds, cellular capacity saturates. The application must remain useful with zero bars: a committeeman must always be able to see where he is standing, and an officer must never lose work to a dropped connection.

**Design test:**  If a feature cannot be operated in the dark, in the rain, with cold hands, on one bar of signal, it is not finished.

### 1.3 Shift phases

Work is organized into two operational phases. Every position assignment belongs to exactly one phase, and a committeeman holds one assignment per phase.

| Phase | Description |
| --- | --- |
| Unload | Guests are arriving. Assignments are coarse — the Unload group holds multiple people per position. The rest of the tarmac is staffed one-to-one. |
| Bump and Run | Guests are departing en masse. Assignments are granular: one committeeman per position across all active groups. |

## 2. Users, Roles & Permissions

### 2.1 Roles

| Role | Scope | Summary |
| --- | --- | --- |
| Committeeman | Self only | Standard user. Checks in and out, views own assignment, group, lunch status, map, and officer contacts. |
| Officer | Assigned team(s) | Full Officer Menu for teams they are assigned to. Sees officer functions only for the current day's shift, on the same window rules as committeemen. With no shift today and past 04:00, sees the next assigned shift. |
| Admin | All teams, always | All Officer functions on every team plus the Admin Menu. No shift-window restriction. |

Officers and Admins may be assigned to multiple teams. A committeeman may also belong to more than one team, though in practice teams stay together and work their own shifts.

### 2.2 Permission matrix

| Capability | Committeeman | Officer | Admin |
| --- | --- | --- | --- |
| Check self in / out | Yes | Yes | Yes |
| View own assignment & group | Yes | Yes | Yes |
| Toggle own lunch status | Yes | Yes | Yes |
| Change own PIN | Yes | Yes | Yes |
| View team roster & phone numbers | No | Own teams | All teams |
| Edit certified skills | No | Own teams | All teams |
| Check others in / out | No | Own teams | All teams |
| Assign positions (both phases) | No | Own teams | All teams |
| Toggle operational phase | No | Own teams | All teams |
| Copy assignments from prior shift | No | Own teams | All teams |
| Reset a committeeman PIN | No | Own teams | All teams |
| Send broadcast message | No | Own teams | All teams |
| Add walk-on committeeman | No | Own teams | All teams |
| Create / rename teams | No | No | Yes |
| Import / export roster | No | No | Yes |
| Create shifts | No | No | Yes |
| Create Officer / Admin users | No | No | Yes |
| Edit position matrix | No | No | Yes |
| View audit log | No | No | Yes |

## 3. Authentication & Session Model

### 3.1 Credentials

| Element | Rule |
| --- | --- |
| Username | Member ID. Numeric, stable, unique. Email is stored for contact and recovery only — never used to log in. |
| Password | 4-digit PIN. Default 1234 for every newly created or imported account. |
| PIN change | Any user may change their own PIN under Tools. Officers and Admins may reset any committeeman on their team back to 1234. |
| Storage | PIN is stored as a salted hash (PHP password_hash, bcrypt). Plaintext PINs are never stored and cannot be displayed. |
| Recovery | No self-service reset. A committeeman who is locked out asks any officer, who resets to 1234 in two taps. |

**Change from v1:**  v1 specified that officers could view committeemen passwords, which requires storing them in recoverable form. That is replaced with officer-initiated reset. The operational outcome is identical — an officer can get a locked-out man onto the tarmac in seconds — without ever storing a recoverable credential.

### 3.2 Login screen

- Two fields only: Member ID and PIN.
- PIN entry uses a large on-screen numeric keypad — not the system keyboard. Buttons are a minimum of 64 px tall, which is the single most important accommodation for cold hands.
- "Keep me signed in" is checked by default and issues a 90-day rolling session, so a committeeman logs in once at the start of the season.
- No "forgot username" link. A "Forgot PIN?" link displays instructions to see an officer.
- Rate limiting: 10 failed attempts from one IP within 15 minutes triggers a 60-second lockout. Deliberately loose — a locked-out officer at 17:00 is a worse outcome than a brute-force attempt on an internal shift tool.

### 3.3 Session behaviour

- Sessions are cookie-based, HttpOnly, Secure, SameSite=Lax, with a rotating token stored server-side so a session can be revoked.
- Sessions survive app closure, phone restart, and network loss.
- Changing a PIN invalidates all other sessions for that account but keeps the current device signed in.

## 4. Data Model

Entities below are the minimum viable schema. Every table carries created_at, updated_at, and where relevant created_by.

| Entity | Key fields | Notes |
| --- | --- | --- |
| season | id, name, start_date, end_date, is_active | Wraps all operational data so 2027 does not mix with 2026. Rosters, shifts, assignments and check-ins all hang off a season. |
| team | id, season_id, name, is_active | Created by Admin. |
| user | id, member_id, last_name, first_name, phone, email, pin_hash, role, is_active, is_walkon | member_id is the natural key for imports and login. role ∈ {committeeman, officer, admin}. |
| team_member | user_id, team_id, season_id | Many-to-many. Supports a committeeman on more than one team and officers over several teams. |
| skill | id, code, label | Seeded: forklift, golfcart, radio, starter, crosswalk_middle, computer, runner, counter. |
| user_skill | user_id, skill_id | Persistent across shifts. Not season-scoped — a certification carries forward. |
| position_group | id, code, label, sort_order | 10 groups. See Section 8. |
| position | id, group_id, label, is_radio, sort_order, is_active | One row per unique physical position. |
| position_phase | position_id, phase, multi_assign, carry_forward, is_critical | Per-phase attributes. A position may exist in one phase or both, with different criticality in each. |
| shift | id, season_id, team_id, shift_type, starts_at, ends_at, current_phase, phase_changed_at | shift_type ∈ {weeknight, weekend_day, weekend_night}. |
| shift_group | shift_id, group_id, is_active | Which position groups are staffed on this shift. Drives the "open positions" count. |
| check_event | id, shift_id, user_id, type, occurred_at, recorded_by, source | Append-only. type ∈ {in, out}. source ∈ {self, officer, offline_sync}. |
| assignment | id, shift_id, phase, position_id, user_id, assigned_by, assigned_at, is_current | Append-only history; is_current flags the live row. Never updated in place. |
| lunch_event | id, shift_id, user_id, state, occurred_at, recorded_by | state ∈ {not_yet, at_lunch, done}. |
| broadcast | id, shift_id, body, created_by, created_at, expires_at | Pinned message shown in the status widget. |
| state_version | shift_id, version, updated_at | Single integer bumped on any change. The polling endpoint compares against this. |
| audit_log | id, actor_id, action, entity, entity_id, before, after, occurred_at | Append-only. Written on every assignment, phase flip, check event, PIN reset and import. |

### 4.1 Time handling

- All timestamps stored in UTC. All display in America/Chicago.
- Rodeo season spans the US daylight-saving transition in mid-March. Storing UTC and converting on display is what makes a shift spanning that night behave correctly.
- Offline check-ins record the original device timestamp, not the sync time, and are flagged as offline_sync so a suspicious clock can be spotted.

## 5. Shift Model

### 5.1 Shift types

| Type | Hours | Default phase | Behaviour |
| --- | --- | --- | --- |
| Weeknight | 16:45 – 02:00 | Unload | Unload with coarse assignments, then meal, then a manual flip to Bump and Run for the departure rush. |
| Weekend Day | 08:00 – 18:00 | Unload | Predominantly Unload. Meal breaks rotate through the team. A manual flip to Bump and Run near the end hands the tarmac to the night crew. |
| Weekend Night | 16:45 – 02:00 | Bump and Run | Crowd departs early. Team eats on arrival and goes straight to Bump and Run positions, held for the whole shift. No Unload phase in normal operation. |

### 5.2 Phase toggling — resolved rule

Your answers on this point pulled in two directions: Weekend Night should be able to fall back to Unload if weather delays the show, but a shift should not normally reverse out of Bump and Run. The rule below satisfies both.

- **The toggle is never hard-locked, for any shift type.** Weather does what it wants and the officer on the ground needs the control.
- **Moving forward (Unload → Bump and Run) is a single tap.**
- **Moving backward (Bump and Run → Unload) requires a confirmation step** reading "Switch back to Unload? All committeemen will immediately see their Unload assignment." This makes reversal deliberate rather than accidental.
- **Assignments in each phase persist independently.** Toggling back and forth never destroys work — the Bump and Run board is exactly as it was when you return to it.
- **Weekend Night shifts open in Bump and Run** with Unload assignments empty. If a fallback to Unload is needed, the carry-forward rule runs in reverse and pre-populates from the Bump and Run board.

### 5.3 Shift visibility window

A team can enter an assigned shift from 00:00 on the shift start date through 04:00 the following day. A shift starting 16:45 on 1 March and ending 02:00 on 2 March is therefore accessible 00:00 on 1 March through 04:00 on 2 March.

- The window governs Check In/Out, the status widget, and My Shift Status.
- All other menu options remain visible at all times, subject to role.
- An officer with no shift today, past 04:00, sees data for their next assigned shift.
- Admins are never window-restricted.

*Note: v1 wrote this as "12:01am". The specification uses 00:00 to avoid a one-minute dead zone.*

### 5.4 Active groups per shift

**Answered.** All ten groups are active by default on every shift type, and a shift still carries its own set so an officer can trim it during the shift.

Shift type turned out to decide almost nothing here. The phase matrix already filters positions per phase — the Unload group exists only in Unload, and OST, West Loop, Monroe and Maxey only in Bump and Run — and Rodeo Express confirmed that every group present in Unload except the Unload group itself is also staffed in Bump and Run, and that the four route stops are always open. What is left for a per-shift set to express is weather and closures, which is a during-shift decision, not a creation-time one.

Short staffing does not trim groups either. It thins them: one runner at Reed Road instead of four. The matrix already encodes that order — the numbered siblings within a group are the depth beyond its critical core — so shortfall needs no configuration at all.

That leaves the counter, which was the real problem behind this section. "70 open of 95" is worthless whether or not groups are trimmed. Two figures replace it: **critical coverage**, which is the staffing floor and is allowed to read red because on a short night it is telling the truth, and **placed against present**, which says how many people who have checked in are standing without a position. Raw open counts survive only as a per-group breakdown an officer can open on purpose.

## 6. Screen Specifications

### 6.1 Login

The default landing page for any unauthenticated request. Member ID field, large numeric PIN keypad, "Keep me signed in" (default on), "Forgot PIN?" help text. On success, redirect to Main Menu.

### 6.2 Main Menu

A single vertical column of full-width tiles, each a minimum of 72 px tall with an icon and a label. Order is fixed; tiles the user cannot access are not rendered.

| Tile | Visible to | Destination |
| --- | --- | --- |
| Admin Menu | Admin | Section 6.10 |
| Officer Menu | Officer, Admin | Section 6.9 |
| Check In / Out | All | Section 6.4 |
| My Shift Status | All | Section 6.5 |
| My Shifts | All | Section 6.6 |
| Rodeo Information | All | Section 6.8 — "Coming Soon" in v1 |
| Tools | All | Section 6.7 |

### 6.3 Status widget (persistent)

Once a user has checked in or out, a compact strip is fixed to the top of every screen in the application. It is the single most-viewed element in the product and must be legible at arm's length in sunlight.

- Status — Checked In or Checked Out, colour-coded.
- Current assignment for the active phase, with a "What's this?" link opening the position definition.
- Lunch status — Not yet / At lunch / Done.
- Active broadcast message, if any, pinned beneath the strip.
- Freshness indicator — "Updated 12s ago", turning amber past 60 seconds and red when offline. A stale screen must never look live.
- For officers only: the coverage counter described in 6.9.2.

### 6.4 Check In / Out

- One large primary button that reflects the opposite of the current state: "CHECK IN" when out, "CHECK OUT" when in.
- Honour system — no QR code, no geofence.
- Confirmation shows the recorded timestamp so a mis-tap is immediately visible.
- Works fully offline. The event is queued locally with its original timestamp and syncs automatically, with a visible "1 pending" badge until it does.
- Checking out vacates the user's positions in both phases.

### 6.5 My Shift Status

The committeeman's home base during a shift.

- Checked in/out status with an inline toggle — no need to navigate away.
- Current assignment in large type, with "What's this?" definition link.
- Everyone else in the same position group, each showing their specific position and a tap-to-call button using the stored phone number.
- Lunch status with a three-state toggle.
- Quick link to the Tarmac Map, with the user's own position highlighted.
- All officers assigned to this shift, each with a tap-to-call button.
- Entire screen is cached for offline viewing. When offline it renders the last known state with a clear staleness banner.

### 6.6 My Shifts

A chronological list of every shift assigned to the user in the active season: date, type, hours, team, and their check-in/out times for shifts already worked. Past shifts collapse below a "Show past shifts" divider.

### 6.7 Tools

- Change my PIN — current PIN, new PIN, confirm.
- Toggle Large Text mode.
- Toggle Dark / Light / Auto theme.
- Sign out.
- "Install this app on your phone" instructions, platform-aware.

### 6.8 Rodeo Information

Placeholder in v1 — a styled "Coming Soon" page. The content structure is a deliverable owed (Section 11).

### 6.9 Officer Menu

The operational core of the application. An Admin viewing these screens gets a team selector at the top; an officer assigned to multiple teams gets the same selector limited to their teams.

#### 6.9.1 Phase control

A prominent segmented control showing Unload | Bump and Run with the active phase filled in Rodeo Orange. Rules per Section 5.2. Changing the phase immediately changes what every committeeman on the shift sees in their widget.

#### 6.9.2 Coverage counter

A persistent bar visible to Officers and Admins only, on every Officer Menu screen. Each figure is tappable and filters the relevant list.

| Metric | Definition |
| --- | --- |
| Checked in | Roster members with an active check-in on this shift. |
| Not checked in | Roster members with no check-in event on this shift. Equivalent to Absent. |
| Assigned | Checked-in members currently holding a position in the active phase. |
| Open | Vacant positions in the active phase, counted only across the shift's active groups (Section 5.4). |
| Critical covered | Filled ÷ total positions flagged is_critical for the active phase, within active groups. Renders red when any critical position is vacant — which on a short night is the truth and not an error, since Bump and Run has 37 critical positions and a shift can run 25 people. |

*Example rendering: 58 in · 12 out · 51 assigned · Critical 35/37*

#### 6.9.3 View Roster

- List of the team, sorted Last Name, First Name.
- Phone number column rendered as a tap-to-call link.
- Certified skills displayed beneath each name as compact chips.
- Edit button per person opens a checkbox sheet for the eight skills. Skills persist across shifts and seasons.
- Check in / Check out button per person — officers do this often, for dead phones and for people who leave sick.
- Reset PIN button per person, resetting to 1234 with a confirmation toast.
- "Add walk-on" button captures last name, first name, phone and optional Member ID in under 20 seconds, creating an active member for this season.

#### 6.9.4 Assign Unload / Assign Bump and Run

These two screens share one interaction model, differing only in which positions and rules apply. This is the screen that determines whether the application succeeds.

**No drag and drop.** It is the intuitive choice and the wrong one — it is unreliable with gloves, on wet glass, and one-handed. Every assignment is two taps.

Two interchangeable modes, toggled by a control at the top:

- **Position-first:** tap a vacant position → a sheet of eligible checked-in committeemen opens → tap a name. Best for filling holes.
- **Roster-first:** tap an unassigned committeeman → a sheet of vacant positions opens → tap a position. Best for clearing a list of 60 people at the start of a shift.

Rules enforced by the server on every write:

1. A person occupies at most one position per phase. Assigning someone who is already placed automatically vacates their prior position, in one atomic transaction. There is no way to double-book a person.
2. The same person may hold different positions in Unload and Bump and Run.
3. Only the Unload group accepts multiple people on one position. All other positions are one-to-one in both phases.
4. Positions may be left vacant, and a filled position may be vacated at any time to free that person for another spot.
5. Only checked-in committeemen appear as available.
6. A person marked At Lunch is vacated from their position and returns to the available pool.

Officer aids on both screens:

- Skill filter — a chip row (Radio, Starter, Computer, Counter, Runner, Crosswalk Middle, Forklift, Golf Cart) narrowing the available list. Filters are optional and advisory.
- Skill mismatch produces a soft warning ("Not radio certified — assign anyway?"), never a block.
- Search by last name.
- Collapsible group sections so a 95-position board stays navigable on a phone.
- Vacant critical positions are pinned to the top of the board and outlined in red.

#### 6.9.5 Carry-forward from Unload to Bump and Run

Positions in the General, Naomi Crosswalk, Holly Hall Crosswalk, Gold Badge / LT, and Reed Road groups are stateful across phases. Assigning someone to one of these positions in Unload automatically places the same person in the same position in Bump and Run.

- The carried assignment is marked as inherited in the officer's view.
- An officer may override any inherited assignment on the Bump and Run screen. Once overridden, that position stops inheriting and no longer tracks Unload changes.
- Positions in the OST, West Loop, Monroe, Maxey, and Unload groups never carry forward.

#### 6.9.6 Copy From Previous Shift

The single largest time-saver in the application, and new in v2. Largely the same people stand in the same places night after night.

1. Officer selects a prior shift from a list of the team's recent shifts.
2. The application shows a preview: how many assignments will be applied, how many people from that shift are not checked in tonight, and which positions will be left open as a result.
3. On confirmation, assignments are applied only for people currently checked in. Everyone else's position is left vacant and flagged.
4. The officer then fills the flagged holes manually. A twenty-minute job becomes a three-minute one.

#### 6.9.7 View Unload / View Bump and Run

Read-only, condensed board grouped by position group: position name on the left, assigned person on the right, vacancies rendered in muted italic. Designed to be scanned in a few seconds, and cached for offline reference.

#### 6.9.8 View Checked In / View Absent

View Checked In lists everyone with an active check-in, their check-in time, current assignment, and lunch state. View Absent lists roster members with no check-in event on this shift, with tap-to-call buttons — the practical use is chasing down who has not shown up.

#### 6.9.9 Lunch Management

A roster view filtered to lunch state, with counts across the three states. Officers can move people between states in bulk. Moving someone to At Lunch vacates their position; moving them to Done returns them to the available pool without automatically restoring their old position — the officer places them deliberately.

#### 6.9.10 Broadcast Message

A short free-text message pinned to the status widget of every committeeman on the shift, with an optional expiry. Intended for "bump and run in 15 minutes", "Reed lane closed, use Employee", "buses running 20 minutes behind". New in v2 — v1 stated communication as an objective but provided no mechanism.

#### 6.9.11 Reset PINs

Replaces v1's "Change Passwords" screen. A searchable roster list with a Reset to 1234 action per person. Passwords are never displayed because they are never stored in recoverable form.

### 6.10 Admin Menu

#### 6.10.1 Manage Seasons

Create and activate a season. All rosters, shifts, assignments and check-in history are scoped to a season, so prior years archive cleanly rather than accumulating.

#### 6.10.2 Manage Teams

Create, rename, and deactivate teams within the active season.

#### 6.10.3 Import Roster

CSV import in the format: Lastname, Firstname, Member_ID, Phone, Email, Team.

**Dry run first.** The import never commits on upload. It parses, validates, and presents a summary — for example "42 new · 18 updated · 3 skipped (officer/admin) · 2 errors" — with a downloadable error report. Nothing is written until the Admin confirms.

Matching and update rules:

- Records match on Member_ID, not email. Emails are shared between spouses and are not a safe key.
- Existing committeeman records are updated with the imported values.
- Existing Officer and Admin records are never modified by import. They are reported as skipped.
- New records are created as Committeeman with PIN 1234.
- Leading and trailing whitespace is stripped from every field.

Parser must tolerate:

- Quoted fields containing commas (e.g. "Smith, Jr.").
- UTF-8 byte-order marks from Excel exports.
- Both CRLF and LF line endings.
- Phone numbers in any common format, normalised to E.164 for tel: links while preserving the original for display.
- Duplicate Member_IDs within the same file — last row wins, with a warning.

#### 6.10.4 Export Roster

Shift selector at the top, then CSV export containing: Lastname, Firstname, Member ID, Shift Day, Check In Timestamp, Check Out Timestamp, Last Assigned Position (Unload), Last Assigned Position (Bump and Run), Assigned Skills.

#### 6.10.5 Create Shifts

- Assign a shift to a team with a type, start datetime and end datetime.
- Select which position groups are active for the shift (Section 5.4), pre-filled from the shift type's defaults.
- Bulk creation for a date range, since shift patterns repeat across the season.
- Warning on overlapping shifts for the same team.

#### 6.10.6 Create Officer / Admin User

Full name, Member ID, phone, email, role, and multi-select team assignment. Officers and Admins assigned to a team with an active shift appear in the officer contact list on My Shift Status for that shift.

#### 6.10.7 Create Committeeman User

Same fields as an import row, entered manually, with team assignment. PIN defaults to 1234.

#### 6.10.8 Position Matrix Editor

New in v2. Tarmac layouts change between seasons and occasionally mid-season. This screen lets an Admin add, rename, reorder, retire, and re-flag positions without a code change.

- Per position: group, label, radio flag, active flag, sort order.
- Per position per phase: present in phase, multi-assign, carry-forward, critical.
- Retiring a position preserves historical assignment records.

#### 6.10.9 Audit Log

Filterable, read-only view of every assignment change, phase flip, check event, PIN reset, and import. Answers "who moved Johnson off Curve 2 and when".

## 7. Skills & Certifications

Eight certifications, persistent from shift to shift and season to season once set. Editable by Officers and Admins on their own teams.

| Skill | Suggested position mapping | Status |
| --- | --- | --- |
| Radio | Every position flagged is_radio (22 positions) | Mapped |
| Starter | All positions containing "Starter" | Mapped |
| Computer | All positions containing "Computer" | Mapped |
| Counter | All positions containing "Counter" | Mapped |
| Runner | All positions containing "Runner" | Mapped |
| Crosswalk Middle | Naomi Center Starter, Holly Hall Center | Needs confirmation |
| Forklift | No position in the current matrix requires it | Needs confirmation |
| Golf Cart | No position in the current matrix requires it | Needs confirmation |

**Open question:**  Forklift and Golf Cart are tracked as certifications but no position in the matrix corresponds to either. Are there forklift or cart positions missing from the list, or are these certifications tracked purely for reference and reporting?

## 8. Position Matrix

Ten position groups spanning 98 unique positions. Because the four shared groups appear in both phases, this produces 157 position-phase records: 62 in Unload and 95 in Bump and Run.

### 8.1 Group summary

| Group | Positions | Unload | B&R | Carry-forward | Critical |
| --- | --- | --- | --- | --- | --- |
| General | 16 | 16 | 16 | Yes | 4 |
| Naomi Crosswalk | 13 | 13 | 13 | Yes | 5 |
| Holly Hall Crosswalk | 6 | 6 | 6 | Yes | 4 |
| Reed Road | 15 | 15 | 15 | Yes | 4 |
| Gold Badge / LT | 9 | 9 | 9 | Yes | 4 |
| Unload | 3 | 3 | — | No | 2 |
| OST | 11 | — | 11 | No | 4 |
| West Loop | 9 | — | 9 | No | 4 |
| Monroe | 9 | — | 9 | No | 4 |
| Maxey | 7 | — | 7 | No | 4 |
| TOTAL | 98 | 62 | 95 |  | 39 |

Criticality does not vary by phase: one list, applied to whichever phases a position exists in. That gives **23 critical positions in Unload and 37 in Bump and Run** — the floor below which a shift runs but barely.

**Restored in v2:**  The Holly Hall Crosswalk group was referenced in v1's carry-forward rule but its positions were never listed. It is now defined as Holly Hall Center (Radio) plus Holly Hall 1 through 5, present in both phases and carrying forward.

### 8.2 Attribute definitions

| Attribute | Meaning |
| --- | --- |
| Radio | Position requires a radio. Drives the radio skill filter and soft warning. |
| Multi | More than one committeeman may hold this position simultaneously. True only for the three Unload group positions. |
| Carry | Assignment in Unload auto-populates the same person into the same position in Bump and Run, until overridden. |
| Critical | Counts toward the "Critical covered" figure. Vacant critical positions are pinned to the top of the assign board in red. Configurable per position per phase. |

*Critical flags below are confirmed by Rodeo Express (open item 4). The shape is one Starter, one Computer, one Counter and one Runner per lane or stop, the four General leads, and the crosswalk Centers with their inner perimeter. Woodlands and Special Events Starters are deliberately not critical — an adjacent worker covers them when people are short. Reed Road carries one Computer and one Counter for the group, not one per gate. The Position Matrix Editor makes all of this changeable at any time.*

**Critical positions: 39 of 98 — 23 in Unload, 37 in Bump and Run.**

### 8.3 Full position list

| Group | Position | Phase(s) | Radio | Multi | Carry | Critical |
| --- | --- | --- | --- | --- | --- | --- |
| General | Main Committee Gate Lead | Unload + B&R | Y |  | Y | Y |
| General | Main Committee Gate 2 | Unload + B&R |  |  | Y |  |
| General | Tent Entrance/Overheads Lead | Unload + B&R | Y |  | Y | Y |
| General | Tent Entrance/Overheads 2 | Unload + B&R |  |  | Y |  |
| General | Tent Entrance/Overheads 3 | Unload + B&R |  |  | Y |  |
| General | Tent Entrance/Overheads 4 | Unload + B&R |  |  | Y |  |
| General | Tent Entrance/Overheads 5 | Unload + B&R |  |  | Y |  |
| General | Tent Entrance/Overheads 6 | Unload + B&R |  |  | Y |  |
| General | Bus Caller 1 | Unload + B&R | Y |  | Y | Y |
| General | Bus Caller 2 | Unload + B&R | Y |  | Y |  |
| General | Curve 1 | Unload + B&R | Y |  | Y | Y |
| General | Curve 2 | Unload + B&R | Y |  | Y |  |
| General | Woodlands Starter | Unload + B&R | Y |  | Y |  |
| General | Woodlands Runner | Unload + B&R |  |  | Y |  |
| General | Special Events Starter | Unload + B&R | Y |  | Y |  |
| General | Special Events Runner | Unload + B&R |  |  | Y |  |
| Naomi Crosswalk | Center Starter | Unload + B&R | Y |  | Y | Y |
| Naomi Crosswalk | Naomi Crosswalk Perimeter 1 | Unload + B&R |  |  | Y | Y |
| Naomi Crosswalk | Naomi Crosswalk Perimeter 2 | Unload + B&R |  |  | Y | Y |
| Naomi Crosswalk | Naomi Crosswalk Perimeter 3 | Unload + B&R |  |  | Y | Y |
| Naomi Crosswalk | Naomi Crosswalk Perimeter 4 | Unload + B&R |  |  | Y | Y |
| Naomi Crosswalk | Naomi Crosswalk Perimeter 5 | Unload + B&R |  |  | Y |  |
| Naomi Crosswalk | Naomi Crosswalk Perimeter 6 | Unload + B&R |  |  | Y |  |
| Naomi Crosswalk | Naomi Bridge 1 | Unload + B&R |  |  | Y |  |
| Naomi Crosswalk | Naomi Bridge 2 | Unload + B&R |  |  | Y |  |
| Naomi Crosswalk | Naomi Bridge 3 | Unload + B&R |  |  | Y |  |
| Naomi Crosswalk | Naomi Bridge 4 | Unload + B&R |  |  | Y |  |
| Naomi Crosswalk | Naomi Bridge 5 | Unload + B&R |  |  | Y |  |
| Naomi Crosswalk | Naomi Bridge 6 | Unload + B&R |  |  | Y |  |
| Holly Hall Crosswalk | Holly Hall Center | Unload + B&R | Y |  | Y | Y |
| Holly Hall Crosswalk | Holly Hall 1 | Unload + B&R |  |  | Y | Y |
| Holly Hall Crosswalk | Holly Hall 2 | Unload + B&R |  |  | Y | Y |
| Holly Hall Crosswalk | Holly Hall 3 | Unload + B&R |  |  | Y | Y |
| Holly Hall Crosswalk | Holly Hall 4 | Unload + B&R |  |  | Y |  |
| Holly Hall Crosswalk | Holly Hall 5 | Unload + B&R |  |  | Y |  |
| Reed Road | Reed Starter 1 | Unload + B&R | Y |  | Y | Y |
| Reed Road | Reed Starter 2 | Unload + B&R | Y |  | Y |  |
| Reed Road | Reed Computer | Unload + B&R |  |  | Y | Y |
| Reed Road | Employee Computer | Unload + B&R |  |  | Y |  |
| Reed Road | Reed Counter 1 | Unload + B&R |  |  | Y | Y |
| Reed Road | Reed Counter 2 | Unload + B&R |  |  | Y |  |
| Reed Road | Employee Counter 1 | Unload + B&R |  |  | Y |  |
| Reed Road | Employee Counter 2 | Unload + B&R |  |  | Y |  |
| Reed Road | Reed/Employee Runner 1 | Unload + B&R |  |  | Y | Y |
| Reed Road | Reed/Employee Runner 2 | Unload + B&R |  |  | Y |  |
| Reed Road | Reed/Employee Runner 3 | Unload + B&R |  |  | Y |  |
| Reed Road | Reed/Employee Runner 4 | Unload + B&R |  |  | Y |  |
| Reed Road | Reed/Employee Back Gate 1 | Unload + B&R |  |  | Y |  |
| Reed Road | Reed/Employee Back Gate 2 | Unload + B&R |  |  | Y |  |
| Reed Road | Reed/Employee Back Gate 3 | Unload + B&R |  |  | Y |  |
| Gold Badge / LT | GB/LT Starter 1 | Unload + B&R | Y |  | Y | Y |
| Gold Badge / LT | GB/LT Starter 2 | Unload + B&R | Y |  | Y |  |
| Gold Badge / LT | GB/LT Computer | Unload + B&R |  |  | Y | Y |
| Gold Badge / LT | GB/LT Counter 1 | Unload + B&R |  |  | Y | Y |
| Gold Badge / LT | GB/LT Counter 2 | Unload + B&R |  |  | Y |  |
| Gold Badge / LT | GB/LT Runner 1 | Unload + B&R |  |  | Y | Y |
| Gold Badge / LT | GB/LT Runner 2 | Unload + B&R |  |  | Y |  |
| Gold Badge / LT | GB/LT Runner 3 | Unload + B&R |  |  | Y |  |
| Gold Badge / LT | GB/LT Back of Tent | Unload + B&R |  |  | Y |  |
| Unload | Unload Starter | Unload only | Y | Y |  | Y |
| Unload | Unload Computer | Unload only |  | Y |  | Y |
| Unload | Unload Helper/Crowd Control | Unload only |  | Y |  |  |
| OST | OST Starter 1 | B&R only | Y |  |  | Y |
| OST | OST Starter 2 | B&R only | Y |  |  |  |
| OST | OST Computer | B&R only |  |  |  | Y |
| OST | OST Counter 1 | B&R only |  |  |  | Y |
| OST | OST Counter 2 | B&R only |  |  |  |  |
| OST | OST Runner 1 | B&R only |  |  |  | Y |
| OST | OST Runner 2 | B&R only |  |  |  |  |
| OST | OST Runner 3 | B&R only |  |  |  |  |
| OST | OST Runner 4 | B&R only |  |  |  |  |
| OST | OST Back Gate 1 | B&R only |  |  |  |  |
| OST | OST Back Gate 2 | B&R only |  |  |  |  |
| West Loop | WL Starter 1 | B&R only | Y |  |  | Y |
| West Loop | WL Starter 2 | B&R only | Y |  |  |  |
| West Loop | WL Computer | B&R only |  |  |  | Y |
| West Loop | WL Counter 1 | B&R only |  |  |  | Y |
| West Loop | WL Counter 2 | B&R only |  |  |  |  |
| West Loop | WL Runner 1 | B&R only |  |  |  | Y |
| West Loop | WL Runner 2 | B&R only |  |  |  |  |
| West Loop | WL Back Gate 1 | B&R only |  |  |  |  |
| West Loop | WL Back Gate 2 | B&R only |  |  |  |  |
| Monroe | Monroe Starter 1 | B&R only | Y |  |  | Y |
| Monroe | Monroe Starter 2 | B&R only | Y |  |  |  |
| Monroe | Monroe Computer | B&R only |  |  |  | Y |
| Monroe | Monroe Counter 1 | B&R only |  |  |  | Y |
| Monroe | Monroe Counter 2 | B&R only |  |  |  |  |
| Monroe | Monroe Runner 1 | B&R only |  |  |  | Y |
| Monroe | Monroe Runner 2 | B&R only |  |  |  |  |
| Monroe | Monroe Back Gate 1 | B&R only |  |  |  |  |
| Monroe | Monroe Back Gate 2 | B&R only |  |  |  |  |
| Maxey | Maxey Starter | B&R only | Y |  |  | Y |
| Maxey | Maxey Computer | B&R only |  |  |  | Y |
| Maxey | Maxey Counter 1 | B&R only |  |  |  | Y |
| Maxey | Maxey Counter 2 | B&R only |  |  |  |  |
| Maxey | Maxey Runner 1 | B&R only |  |  |  | Y |
| Maxey | Maxey Runner 2 | B&R only |  |  |  |  |
| Maxey | Maxey Back Gate | B&R only |  |  |  |  |

## 9. Visual Design System

### 9.1 Colour palette and contrast

Rodeo Orange is the brand anchor, but at #EF7622 it measures roughly 2.9:1 against white — below the 4.5:1 WCAG AA threshold for body text, and below even the 3:1 large-text threshold when used with white text. In an application read in direct Houston sunlight this is a practical legibility problem, not merely a compliance one. The palette below preserves the brand read while meeting contrast.

| Token | Hex | Contrast vs white | Use |
| --- | --- | --- | --- |
| Rodeo Orange | #EF7622 | ≈2.9:1 | Brand accent, borders, large headings, active-state fills with dark text on top. Never white text on this. |
| Action Orange | #B85416 | ≈4.9:1 ✓ | Primary buttons with white text. A darkened derivative that still reads as Rodeo Orange. |
| Rodeo Brown | #7F5E46 | ≈5.9:1 ✓ | Body text, secondary headings, icons. |
| Rodeo Dust | #C9B29B | — | Card surfaces, dividers, table borders. Never used for text. |
| Dust Light | #F2EAE2 | — | Alternating rows, callout backgrounds, page surfaces. |
| Ink | #2B2018 | ≈14:1 ✓ | Primary text, text on orange fills. |

### 9.2 Dark theme

A dark theme is a requirement, not a nicety. Weeknight and weekend night shifts end at 02:00 and a white screen destroys night vision on a dark tarmac. The theme follows the device setting by default, with a manual override in Tools, and inverts to a warm near-black surface with Rodeo Orange retained as the accent.

### 9.3 Touch and typography

- Minimum interactive target 56 px; primary actions and the PIN keypad 64 px or larger.
- Minimum 8 px spacing between adjacent targets so a mis-tap does not trigger the wrong action.
- Body text 17 px minimum; assignment names and status values 22 px or larger.
- Large Text mode in Tools scales the base by roughly 125%.
- Primary actions anchored to the bottom of the viewport, within thumb reach one-handed.
- No hover-dependent behaviour anywhere. No small dismiss controls — sheets close by tapping outside or by a full-width Close button.
- Every destructive action requires confirmation; every non-destructive action is immediate with an undo toast.

## 10. Technical Architecture

### 10.1 Platform

| Layer | Choice |
| --- | --- |
| Hosting | Ahosting Reseller Gold — WHM/cPanel, LiteSpeed Web Server with LSCache, CloudLinux with CageFS and LVE isolation, free SSL per account, dedicated IP. |
| Runtime | PHP 8.1 or later via LiteSpeed LSAPI. ASP/.NET/JSP are not supported by the host, which settles the language choice. |
| Database | MySQL / MariaDB via PDO with prepared statements throughout. |
| Front end | Server-rendered HTML with progressive vanilla JavaScript. No SPA framework and no build step — the application deploys by uploading files, which matters for a volunteer-maintained system. |
| Delivery | Progressive Web App: manifest, service worker, installable to the home screen. HTTPS is mandatory for this and is included with the plan. |

### 10.2 Live updates

CloudLinux LVE caps concurrent entry processes per account, which rules out WebSockets and Server-Sent Events on shared hosting — 30 held-open connections would exhaust the allocation. Short polling against a deliberately cheap endpoint is the correct approach at this scale.

- A single state_version integer per shift is bumped on any assignment, phase, check-in, lunch, or broadcast change.
- Clients poll GET /api/state?shift=N&v=X. If the version is unchanged the server returns 304 Not Modified — a few bytes, one indexed lookup.
- Poll every 10 seconds in the foreground, 60 seconds when backgrounded, paused entirely when offline or when the shift window is closed.
- At your peak of roughly 30 active clients this is about 3 requests per second of near-zero-cost responses. LiteSpeed will not notice.
- The polling layer sits behind a thin abstraction so it can be swapped for Web Push or a hosted service later without touching the screens.

### 10.3 Offline behaviour

- The service worker caches the application shell, the tarmac map, and position definitions with a cache-first strategy.
- The user's own assignment, group, and officer contact list are cached on every successful fetch, so My Shift Status renders with zero connectivity.
- Check-in, check-out, and lunch changes are written to an IndexedDB queue and replayed on reconnection, preserving the original timestamp.
- Officer assignment writes are never optimistic — they require a server round trip, since two officers assigning simultaneously must be resolved by the server. A failed write is surfaced immediately and retried on reconnection.
- Every cached screen carries a visible freshness indicator. A stale view must never be mistakable for a live one.

### 10.4 Concurrency

Two officers will assign at the same time. The server is the sole authority.

- Each assignment is a single transaction that vacates the person's prior position in that phase and writes the new one.
- The client re-reads shift state after every write rather than computing it locally.
- A row-level check prevents two people landing on the same non-multi position; the losing write returns a conflict and the client refreshes with a brief "someone else just assigned that spot" notice.

### 10.5 Security

- PINs hashed with bcrypt via password_hash. No recoverable credentials anywhere in the system.
- All database access through PDO prepared statements.
- CSRF tokens on every state-changing request.
- Output escaping on every rendered value.
- Role and team scope enforced server-side on every request. Hiding a menu tile is presentation, never authorisation.
- Phone numbers and email addresses are personal data belonging to volunteers — exposed only to officers of that person's own team and to Admins, and never included in any client-side cache accessible to a committeeman.
- Application-level nightly database export in addition to the host's daily offsite backups. Host backups protect against server failure; an application export protects against a bad import.

### 10.6 Performance targets

| Metric | Target |
| --- | --- |
| First contentful paint on 3G | Under 2.0 seconds |
| My Shift Status render, warm cache | Under 500 ms |
| Assignment write round trip | Under 400 ms on a normal connection |
| Assign board render, 95 positions | Under 1 second |
| Application shell payload | Under 150 KB gzipped |

## 11. Scope, Deliverables & Open Items

### 11.1 Build sequence

| Phase | Contents | Estimate |
| --- | --- | --- |
| 1 | Schema, position seed data, authentication, PIN and session model, role scaffolding | 1–2 sessions |
| 2 | Admin Menu: seasons, teams, CSV import with dry run, shift creation, user creation | 2 sessions |
| 3 | Committeeman experience: check in/out, status widget, My Shift Status, My Shifts, Tools | 1–2 sessions |
| 4 | Officer Menu: assign boards, phase control, coverage counter, view screens, copy from previous shift | 3–4 sessions |
| 5 | PWA shell, service worker, offline queue, polling layer, dark theme, tarmac map viewer | 2 sessions |
| 6 | Export, audit log, broadcast, position matrix editor, hardening and load testing | 1–2 sessions |

### 11.2 Explicitly out of scope for v1

- Rodeo Information content — placeholder page only.
- Web Push notifications when the application is closed. The free VAPID-based path is available in v1.1; iOS requires home-screen installation, which is an adoption question before it is a technical one.
- QR or geofenced check-in. Honour system confirmed.
- Automatic assignment suggestion. All assignment is manual or copied from a prior shift.
- Shift signup or availability collection. Teams stay together and work their own shifts.
- Native iOS or Android applications.
- Reporting and analytics beyond the CSV export and audit log.

### 11.3 Deliverables owed by Rodeo Express

| Item | Detail needed |
| --- | --- |
| "What's this?" definitions | One or two sentences per position, or per position family where positions are interchangeable (e.g. one definition covering Naomi Bridge 1–6). Roughly 30–40 distinct write-ups rather than 98. |
| Tarmac map | See Section 11.4 below. |
| Rodeo Information | Content structure and copy for the menu. |
| ~~Critical position review~~ | **Received.** 39 critical positions, Section 8.3. Criticality does not differ between phases. |
| ~~Default active groups~~ | **Received.** All ten groups, every shift type; see Section 5.4. |
| Skill mapping confirmation | Crosswalk Middle mapping, and whether forklift and golf cart correspond to any positions. |

### 11.4 Tarmac map — what to supply

The map should be a vector SVG rather than a photograph or PDF, so it stays sharp at any zoom, loads in a few kilobytes, and can highlight a user's own position. What is needed:

1. A scaled site drawing of the tarmac — lanes, tent, gates, crosswalks, bridge, and the named areas (Reed, Employee, Gold Badge/LT, OST, West Loop, Monroe, Maxey, Woodlands, Special Events, Naomi, Holly Hall). A CAD export, a site plan, or a clean hand drawing on graph paper all work as a starting point.
2. A marked location for each of the 98 positions. The practical approach: print the position list from Section 8.3, print the site drawing, and mark each position number on the drawing by hand. I convert that into positioned SVG elements.
3. Confirmation of orientation — which way is north, and which direction guests arrive from. Committeemen navigate by landmark, so the map should be oriented the way people actually stand.
4. Any labels that should appear on the map itself versus only on tap.

Once supplied, each position becomes an addressable SVG element, and My Shift Status can highlight the user's own spot with everyone else in their group shown in a secondary colour. For a new committeeman who has never worked the tarmac, seeing where he stands is worth more than reading the position name.

### 11.5 Open items requiring a decision

| # | Question |
| --- | --- |
| ~~1~~ | **Answered.** All ten groups, on every shift type — the phase matrix already does the per-phase filtering and the four route stops always run. See Section 5.4, which also re-scopes the count this question was asked in service of. |
| 2 | Do forklift and golf cart certifications correspond to any tarmac positions, or are they reference-only? |
| 3 | Does "Crosswalk Middle" mean the Naomi Center Starter and Holly Hall Center positions, or something else? |
| ~~4~~ | **Answered.** 39 positions, Section 8.3, and criticality does not differ between phases. Reed Road carries a single critical Computer and Counter for the group rather than one per gate: Reed and Employee can run from one gate when short, which is a management change on the ground rather than a second critical pair. |
| 5 | A committeeman on two teams: if both teams somehow have shifts on the same date, which shift does his widget show? Proposed default is the earlier start time, with a team switcher. |
| 6 | Should officers be able to see, and assign within, the shift immediately following their own — you noted officers sometimes stay on. Proposed default is read-only visibility of the next shift on the same team. |
| 7 | Retention: how many seasons of history should stay live before archival? |
| 8 | Is there a Rodeo Express or committee logo to use in place of the wordmark on the login screen? |

*End of specification, version 2.0*
