-- The master administrator account.
--
-- Seeded here rather than created by hand so that it exists in every
-- environment, permanently, from the first migration run: a fresh database is
-- never in a state where nobody can sign in.
--
-- The PIN is deliberately NOT set here. This repository is public, and a
-- 4-digit PIN has 10,000 possibilities: at the bcrypt cost this application
-- uses, all of them can be tried offline in about half an hour on one core.
-- Committing the hash would therefore publish the credential, and git would
-- keep it after the PIN was changed. The login rate limit does not help --
-- it stops online guessing, and an offline crack never touches the login form.
--
-- So the account ships locked. Set its PIN once, over SSH or cPanel Terminal:
--
--   cd ~/resm-app && php bin/set-admin-pin.php
--
-- resm:atomic

INSERT INTO `user` (member_id, last_name, first_name, pin_hash, role, is_active, is_walkon)
VALUES (
    '987654321',
    'Dill',
    'Adam',
    -- Not a hash, and not one any PIN can produce: password_verify() returns
    -- false for every input until bin/set-admin-pin.php replaces it.
    '!no-pin-set',
    'admin',
    1,
    0
);

-- The audit log is meant to answer "where did this come from". An account that
-- appeared with no actor is exactly the kind of thing it should record.
INSERT INTO audit_log (actor_id, action, entity, entity_id, after_json, occurred_at)
VALUES (
    NULL,
    'seed_master_admin',
    'user',
    LAST_INSERT_ID(),
    '{"member_id":"987654321","role":"admin","pin":"not set - see bin/set-admin-pin.php"}',
    UTC_TIMESTAMP()
);
