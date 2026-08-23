-- Rodeo Express — Phase 1 schema.
--
-- Entities follow docs/spec-v2.md section 4. Conventions used throughout:
--
--   * InnoDB and utf8mb4_unicode_ci are named explicitly on every table.
--     MariaDB's defaults differ from MySQL's and the server default is not
--     ours to rely on (docs/hosting.md).
--   * Every DATETIME is UTC. The connection pins time_zone to +00:00, so
--     CURRENT_TIMESTAMP defaults record UTC too. Display converts to
--     America/Chicago through a real timezone, never a fixed offset — the
--     season crosses the March DST transition.
--   * Operational history is append-only. Rows are superseded, not updated,
--     so "who moved Johnson off Curve 2 and when" stays answerable.
--   * Nothing is deleted to deactivate. is_active flags exist so that
--     retiring a position or a volunteer preserves the records pointing at
--     them, which is why the foreign keys below mostly RESTRICT.

-- ---------------------------------------------------------------------------
-- People
-- ---------------------------------------------------------------------------

CREATE TABLE `user` (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- The login username and the natural key for CSV import (spec 3.1).
    -- Numeric in practice but stored as text: it is an identifier, never
    -- arithmetic, and leading zeros must survive a round trip.
    --
    -- NULL is allowed for walk-ons added on the tarmac, who may not have a
    -- Member ID to hand (spec 6.9.3). MySQL permits repeated NULLs in a
    -- UNIQUE index, so several walk-ons coexist; a walk-on without one simply
    -- cannot log in until an officer fills it in.
    member_id      VARCHAR(32)  NULL,

    last_name      VARCHAR(80)  NOT NULL,
    first_name     VARCHAR(80)  NOT NULL,

    -- Two columns on purpose: phone keeps whatever the roster supplied so it
    -- displays the way the committeeman recognises it, phone_e164 is the
    -- normalised form behind tel: links (spec 6.10.3).
    phone          VARCHAR(40)  NULL,
    phone_e164     VARCHAR(20)  NULL,

    -- Contact and recovery only — never a login, and deliberately not unique:
    -- spouses share an address, so it is not a safe key (spec 6.10.3).
    email          VARCHAR(190) NULL,

    -- bcrypt via password_hash. A 4-digit PIN is low entropy whatever the
    -- algorithm; rate limiting does the real work and this protects the
    -- database if it ever leaks.
    pin_hash       VARCHAR(255) NOT NULL,
    pin_changed_at DATETIME     NULL,

    role           ENUM('committeeman','officer','admin') NOT NULL DEFAULT 'committeeman',
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    is_walkon      TINYINT(1)   NOT NULL DEFAULT 0,

    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by     INT UNSIGNED NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_member_id (member_id),
    KEY idx_user_name (last_name, first_name),
    KEY idx_user_role (role, is_active),
    CONSTRAINT fk_user_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Authentication
-- ---------------------------------------------------------------------------

-- Server-side session records, so a session can be revoked (spec 3.3).
--
-- Every login writes one of these, not just "keep me signed in" — the PHP
-- session holds a reference to the row and each request re-checks it, which is
-- what makes "changing a PIN invalidates all other sessions" take effect at
-- once instead of whenever PHP happens to collect the session file.
--
-- Split into selector and verifier so the lookup is an indexed equality test
-- on a value that is useless on its own, and the secret half is compared with
-- hash_equals in constant time. The verifier is hashed with SHA-256 rather
-- than password_hash: it is 32 bytes of random_bytes output, not a memorable
-- secret, so there is nothing for a slow hash to defend against and bcrypt on
-- every request would cost real time at shift start.
CREATE TABLE auth_token (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,

    selector      CHAR(32)     NOT NULL,  -- 16 random bytes, hex encoded
    verifier_hash CHAR(64)     NOT NULL,  -- sha256 of 32 random bytes

    -- False for a plain sign-in, true for the 90-day rolling cookie. The PHP
    -- session cannot hold that lifetime: gc_maxlifetime is 1440s on this host
    -- and collection is not ours to govern (docs/hosting.md).
    is_persistent TINYINT(1)   NOT NULL DEFAULT 0,

    issued_at     DATETIME     NOT NULL,
    last_used_at  DATETIME     NOT NULL,
    expires_at    DATETIME     NOT NULL,
    revoked_at    DATETIME     NULL,

    user_agent    VARCHAR(255) NULL,
    ip            VARBINARY(16) NULL,     -- inet_pton form, IPv4 and IPv6

    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_token_selector (selector),
    KEY idx_auth_token_user (user_id, expires_at),
    CONSTRAINT fk_auth_token_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feeds the rate limit in spec 3.2: 10 failed attempts from one IP within 15
-- minutes triggers a 60-second lockout. Successes are recorded too, so the
-- audit answers "was that lockout a typo or an attack".
CREATE TABLE login_attempt (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip          VARBINARY(16)   NOT NULL,
    member_id   VARCHAR(32)     NULL,
    succeeded   TINYINT(1)      NOT NULL DEFAULT 0,
    occurred_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_login_attempt_ip_time (ip, occurred_at),
    KEY idx_login_attempt_member_time (member_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Season and teams
-- ---------------------------------------------------------------------------

-- Wraps all operational data so 2027 does not mix with 2026. Seasons are
-- archived rather than deleted, which is why everything below RESTRICTs.
CREATE TABLE season (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(80)  NOT NULL,
    start_date DATE         NOT NULL,
    end_date   DATE         NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_season_name (name),
    KEY idx_season_active (is_active),
    CONSTRAINT fk_season_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    season_id  INT UNSIGNED NOT NULL,
    name       VARCHAR(80)  NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_team_season_name (season_id, name),
    CONSTRAINT fk_team_season FOREIGN KEY (season_id) REFERENCES season (id) ON DELETE RESTRICT,
    CONSTRAINT fk_team_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many: a committeeman may belong to more than one team, and officers
-- commonly cover several. season_id duplicates team.season_id deliberately —
-- it makes the season scope on every roster query a single indexed column
-- instead of a join, and roster reads happen on every officer screen.
CREATE TABLE team_member (
    user_id    INT UNSIGNED NOT NULL,
    team_id    INT UNSIGNED NOT NULL,
    season_id  INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,

    PRIMARY KEY (user_id, team_id),
    KEY idx_team_member_team (team_id),
    KEY idx_team_member_season_user (season_id, user_id),
    CONSTRAINT fk_team_member_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_member_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_member_season FOREIGN KEY (season_id) REFERENCES season (id) ON DELETE RESTRICT,
    CONSTRAINT fk_team_member_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Skills
-- ---------------------------------------------------------------------------

CREATE TABLE skill (
    id         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(32)      NOT NULL,
    label      VARCHAR(60)      NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_skill_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Certifications persist from shift to shift and season to season once set,
-- so this table is deliberately not season-scoped (spec 7).
CREATE TABLE user_skill (
    user_id    INT UNSIGNED     NOT NULL,
    skill_id   TINYINT UNSIGNED NOT NULL,
    granted_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    granted_by INT UNSIGNED     NULL,

    PRIMARY KEY (user_id, skill_id),
    KEY idx_user_skill_skill (skill_id),
    CONSTRAINT fk_user_skill_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_skill_skill FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_skill_granted_by FOREIGN KEY (granted_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Position matrix
-- ---------------------------------------------------------------------------

CREATE TABLE position_group (
    id         TINYINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    code       VARCHAR(32)       NOT NULL,
    label      VARCHAR(60)       NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_position_group_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per unique physical position on the tarmac (98 of them).
--
-- The Position Matrix Editor (spec 6.10.8) edits this table directly, so
-- layouts can change between seasons without a code change. Retiring a
-- position clears is_active and keeps the row, because historical assignments
-- point at it.
CREATE TABLE position (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id   TINYINT UNSIGNED  NOT NULL,
    label      VARCHAR(80)       NOT NULL,

    -- Drives the radio skill filter and its soft warning (spec 8.2).
    is_radio   TINYINT(1)        NOT NULL DEFAULT 0,

    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active  TINYINT(1)        NOT NULL DEFAULT 1,

    -- The "What's this?" copy behind every assignment (spec 11.3), and the id
    -- of this position's element in the tarmac map SVG (spec 11.4). Both are
    -- content Rodeo Express still owes; the columns are where it lands.
    definition TEXT              NULL,
    map_ref    VARCHAR(60)       NULL,

    created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_position_group_label (group_id, label),
    KEY idx_position_sort (group_id, sort_order),
    CONSTRAINT fk_position_group FOREIGN KEY (group_id) REFERENCES position_group (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-phase attributes. A position may exist in one phase or both, with
-- different flags in each — 157 rows over the 98 positions: 62 Unload, 95
-- Bump and Run.
CREATE TABLE position_phase (
    position_id   SMALLINT UNSIGNED NOT NULL,
    phase         ENUM('unload','bump_run') NOT NULL,

    -- True only for the three Unload group positions, which hold several
    -- people at once. Everything else is one-to-one in both phases, and the
    -- assignment table below enforces that at the index level.
    multi_assign  TINYINT(1)        NOT NULL DEFAULT 0,

    -- Assigning someone in Unload also places them in the same position in
    -- Bump and Run, until an officer overrides it (spec 6.9.5). Set on both
    -- phase rows of a carrying position: the Unload row marks the source, the
    -- Bump and Run row marks a slot that may arrive inherited.
    carry_forward TINYINT(1)        NOT NULL DEFAULT 0,

    -- Counts toward "Critical covered" and pins the position to the top of
    -- the assign board when vacant. Per phase, because a position can matter
    -- more during the departure rush than during arrival.
    is_critical   TINYINT(1)        NOT NULL DEFAULT 0,

    PRIMARY KEY (position_id, phase),
    KEY idx_position_phase_phase (phase, is_critical),
    CONSTRAINT fk_position_phase_position FOREIGN KEY (position_id) REFERENCES position (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Shifts
-- ---------------------------------------------------------------------------

CREATE TABLE shift (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    season_id        INT UNSIGNED NOT NULL,
    team_id          INT UNSIGNED NOT NULL,
    shift_type       ENUM('weeknight','weekend_day','weekend_night') NOT NULL,

    -- UTC. A weeknight shift is 16:45–02:00 America/Chicago, which is two
    -- different UTC offsets side of the March DST change; storing UTC and
    -- converting on display is what makes that night behave.
    starts_at        DATETIME     NOT NULL,
    ends_at          DATETIME     NOT NULL,

    -- Weeknight and Weekend Day open in Unload; Weekend Night opens straight
    -- into Bump and Run (spec 5.1). The toggle is never hard-locked — weather
    -- does what it wants and the officer on the ground needs the control.
    current_phase    ENUM('unload','bump_run') NOT NULL DEFAULT 'unload',
    phase_changed_at DATETIME     NULL,

    notes            VARCHAR(255) NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by       INT UNSIGNED NULL,

    PRIMARY KEY (id),
    KEY idx_shift_team_start (team_id, starts_at),
    KEY idx_shift_season_start (season_id, starts_at),
    CONSTRAINT fk_shift_season FOREIGN KEY (season_id) REFERENCES season (id) ON DELETE RESTRICT,
    CONSTRAINT fk_shift_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE RESTRICT,
    CONSTRAINT fk_shift_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which position groups are actually staffed on this shift (spec 5.4).
--
-- Without this the Bump and Run board's 95 positions would report "70 open"
-- on a normal 25-person night, and the number would be ignored. Open and
-- critical-coverage counts are computed across active groups only.
CREATE TABLE shift_group (
    shift_id  INT UNSIGNED     NOT NULL,
    group_id  TINYINT UNSIGNED NOT NULL,
    is_active TINYINT(1)       NOT NULL DEFAULT 1,

    PRIMARY KEY (shift_id, group_id),
    KEY idx_shift_group_group (group_id),
    CONSTRAINT fk_shift_group_shift FOREIGN KEY (shift_id) REFERENCES shift (id) ON DELETE CASCADE,
    CONSTRAINT fk_shift_group_group FOREIGN KEY (group_id) REFERENCES position_group (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Operational events — append-only
-- ---------------------------------------------------------------------------

-- Check in and check out. Never updated: current state is the latest row for
-- a (shift, user), so a mis-tap corrected a second later leaves both events
-- visible and the audit stays honest.
CREATE TABLE check_event (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_id    INT UNSIGNED    NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    type        ENUM('in','out') NOT NULL,

    -- When it happened. For an event queued offline this is the device's own
    -- clock at the time of the tap, not the sync time.
    occurred_at DATETIME        NOT NULL,

    -- When the server received it. Equal to occurred_at for a live check-in;
    -- for an offline_sync row the gap between the two is what exposes a phone
    -- with a wrong clock.
    recorded_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    recorded_by INT UNSIGNED    NULL,
    source      ENUM('self','officer','offline_sync') NOT NULL DEFAULT 'self',

    PRIMARY KEY (id),
    KEY idx_check_event_shift_user (shift_id, user_id, occurred_at),
    KEY idx_check_event_shift_time (shift_id, occurred_at),
    CONSTRAINT fk_check_event_shift FOREIGN KEY (shift_id) REFERENCES shift (id) ON DELETE CASCADE,
    CONSTRAINT fk_check_event_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE RESTRICT,
    CONSTRAINT fk_check_event_recorded_by FOREIGN KEY (recorded_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Position assignments. Append-only history with is_current flagging the live
-- row, so the board can be rebuilt for any point in the evening.
--
-- The two unique indexes at the bottom are the load-bearing part of this
-- table. Two officers will assign at the same time on the same board, and the
-- server is the sole authority (spec 10.4) — so the rules are enforced by the
-- database, not by application code that reads then writes:
--
--   uq_assignment_person  a person holds at most one position per phase.
--                         Assigning someone already placed vacates their prior
--                         position in the same transaction; there is no path
--                         to a double-booking, including through a retry or a
--                         second officer.
--
--   uq_assignment_slot    a position holds at most one person per phase,
--                         except the three multi-assign Unload positions,
--                         whose key includes user_id so several people share
--                         the slot while still landing on it only once each.
--
-- Both keys evaluate to NULL once is_current is cleared, and MySQL does not
-- enforce uniqueness over NULL, which is what lets the history accumulate
-- freely underneath the live row. The losing write gets a duplicate-key error,
-- which the client surfaces as "someone else just assigned that spot".
--
-- is_multi is copied from position_phase.multi_assign when the row is written.
-- It is denormalised on purpose: a generated column may only reference its own
-- row, so the flag has to be here for the index to see it.
CREATE TABLE assignment (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    shift_id       INT UNSIGNED      NOT NULL,
    phase          ENUM('unload','bump_run') NOT NULL,
    position_id    SMALLINT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED      NOT NULL,

    assigned_by    INT UNSIGNED      NULL,
    assigned_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vacated_at     DATETIME          NULL,
    is_current     TINYINT(1)        NOT NULL DEFAULT 1,

    -- Carried from this person's Unload assignment rather than placed by hand
    -- (spec 6.9.5). Shown as inherited on the officer's board; an override
    -- writes a manual row and the position stops tracking Unload.
    is_inherited   TINYINT(1)        NOT NULL DEFAULT 0,

    is_multi       TINYINT(1)        NOT NULL DEFAULT 0,
    source         ENUM('manual','carry_forward','copy_previous') NOT NULL DEFAULT 'manual',

    current_person VARCHAR(64)
        GENERATED ALWAYS AS (IF(is_current = 1, CONCAT_WS('|', shift_id, phase, user_id), NULL)) STORED,
    current_slot   VARCHAR(64)
        GENERATED ALWAYS AS (
            IF(is_current = 1,
               CONCAT_WS('|', shift_id, phase, position_id, IF(is_multi = 1, user_id, '*')),
               NULL)
        ) STORED,

    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_person (current_person),
    UNIQUE KEY uq_assignment_slot (current_slot),
    KEY idx_assignment_board (shift_id, phase, is_current),
    KEY idx_assignment_user (user_id, shift_id),
    KEY idx_assignment_position (position_id),
    CONSTRAINT fk_assignment_shift FOREIGN KEY (shift_id) REFERENCES shift (id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_position FOREIGN KEY (position_id) REFERENCES position (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_assigned_by FOREIGN KEY (assigned_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Three-state lunch tracking. Moving someone to at_lunch vacates their
-- position and returns them to the available pool; moving them to done does
-- not restore it, so the officer places them deliberately (spec 6.9.9).
CREATE TABLE lunch_event (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_id    INT UNSIGNED    NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    state       ENUM('not_yet','at_lunch','done') NOT NULL,
    occurred_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by INT UNSIGNED    NULL,

    PRIMARY KEY (id),
    KEY idx_lunch_event_shift_user (shift_id, user_id, occurred_at),
    CONSTRAINT fk_lunch_event_shift FOREIGN KEY (shift_id) REFERENCES shift (id) ON DELETE CASCADE,
    CONSTRAINT fk_lunch_event_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE RESTRICT,
    CONSTRAINT fk_lunch_event_recorded_by FOREIGN KEY (recorded_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A short message pinned to every committeeman's status widget for this shift:
-- "bump and run in 15 minutes", "Reed lane closed, use Employee".
CREATE TABLE broadcast (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_id   INT UNSIGNED NOT NULL,
    body       VARCHAR(280) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     NULL,
    retired_at DATETIME     NULL,

    PRIMARY KEY (id),
    KEY idx_broadcast_shift (shift_id, created_at),
    CONSTRAINT fk_broadcast_shift FOREIGN KEY (shift_id) REFERENCES shift (id) ON DELETE CASCADE,
    CONSTRAINT fk_broadcast_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Polling and audit
-- ---------------------------------------------------------------------------

-- One integer per shift, bumped on any assignment, phase, check-in, lunch or
-- broadcast change.
--
-- LVE caps concurrent entry processes per account, so 30 held-open WebSocket
-- or SSE connections would exhaust the allocation (docs/hosting.md). Clients
-- short-poll instead: GET the version, and if it has not moved the server
-- answers 304 from this single-row primary key lookup. At ~30 clients that is
-- about 3 requests a second of near-zero cost.
CREATE TABLE state_version (
    shift_id   INT UNSIGNED NOT NULL,
    version    INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (shift_id),
    CONSTRAINT fk_state_version_shift FOREIGN KEY (shift_id) REFERENCES shift (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only. Written on every assignment, phase flip, check event, PIN reset
-- and import — the record that answers "who moved Johnson off Curve 2 and
-- when" (spec 6.10.9).
--
-- The spec names these columns before and after; BEFORE is a reserved word in
-- MySQL, hence the _json suffixes.
CREATE TABLE audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id    INT UNSIGNED    NULL,
    action      VARCHAR(60)     NOT NULL,
    entity      VARCHAR(40)     NOT NULL,
    entity_id   BIGINT UNSIGNED NULL,
    shift_id    INT UNSIGNED    NULL,
    before_json JSON            NULL,
    after_json  JSON            NULL,
    occurred_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_audit_entity (entity, entity_id),
    KEY idx_audit_occurred (occurred_at),
    KEY idx_audit_shift (shift_id, occurred_at),
    KEY idx_audit_actor (actor_id, occurred_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
