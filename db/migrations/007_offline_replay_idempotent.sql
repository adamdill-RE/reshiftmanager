-- A replayed event must land once, however many times it is sent.
--
-- The offline queue (spec 10.3) replays on reconnection, and reconnection is
-- not a single tidy moment: a phone walking in and out of signal at the tent
-- can start a replay, lose the answer, and start it again. The client deletes
-- a queued item only once the server has confirmed it, so an unanswered
-- request is one it is required to retry.
--
-- The application's existing answer to "two writes race" is a unique index and
-- a 1062, not a read followed by a write (the assignment table works this way
-- for exactly this reason). Same here. A replay carries the device's original
-- occurred_at unchanged, so the second attempt collides on the nose with the
-- first and the endpoint reports the collision as the success it is.
--
-- These are natural keys rather than a client-supplied id, because the thing
-- being deduplicated is the EVENT, not the message. Two taps in the same
-- second on the same shift are one intent whichever route they arrive by, and
-- the append-only rule that keeps a corrected mis-tap (6.4) still holds: a
-- correction a second later has a different occurred_at and is a different row.

ALTER TABLE check_event
    ADD UNIQUE KEY uq_check_event_once (shift_id, user_id, type, occurred_at);

ALTER TABLE lunch_event
    ADD UNIQUE KEY uq_lunch_event_once (shift_id, user_id, state, occurred_at);
