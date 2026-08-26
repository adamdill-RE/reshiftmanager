-- Lunch changes are queued offline too, and could not say so.
--
-- Spec 10.3 puts check-in, check-out AND lunch changes in the IndexedDB queue
-- replayed on reconnection. check_event was built for that in migration 001 —
-- occurred_at for the device's own clock at the tap, recorded_at for when the
-- server received it, and source to mark the replay. lunch_event was not: it
-- has occurred_at and nothing to compare it against.
--
-- The gap between the two timestamps is the only thing that exposes a phone
-- with a wrong clock, and a lunch change replayed four hours late is exactly
-- where that matters — a man shown At Lunch because his handset thinks it is
-- still 15:00 is a position the board reports as covered and is not.
--
-- Both columns take the same shape as check_event's so the two tables can be
-- read the same way. The default on recorded_at makes every existing row say
-- what is true of it: it was recorded when it occurred.

ALTER TABLE lunch_event
    ADD COLUMN recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER occurred_at,
    ADD COLUMN source ENUM('self','officer','offline_sync') NOT NULL DEFAULT 'self' AFTER recorded_by;

-- Existing rows were all live, and their recorded_at defaulted to the moment
-- this migration ran rather than to when they happened.
UPDATE lunch_event SET recorded_at = occurred_at WHERE recorded_at <> occurred_at;
