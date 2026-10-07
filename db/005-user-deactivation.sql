-- 005: deactivating an account.
-- Deactivation keeps the row rather than deleting it, so everything the account authored stays
-- attributed to it: projects, comments, assignments and board memberships all point at users(id),
-- and a delete would either orphan that work or cascade it away. What it stops is access -- signing
-- in, and any session the account already has open. That second half is the reason this is a flag
-- and not just a check at the sign-in form: a cookie lasts past the moment an admin clicks, and an
-- account switched off on a shared machine has to stop working there and then.
--
-- The default is 1, so every existing row is active and nothing has to be written back for them.
ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1;
