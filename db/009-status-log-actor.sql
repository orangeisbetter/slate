-- 009: record who moved a project in status_log.
--
-- status_log had from, to and when, but not who. On a team, "who moved this to review?"
-- is the first question the history is asked, and it could not answer. Existing rows keep
-- a NULL actor and render as before; new rows carry the signed-in user.
ALTER TABLE status_log ADD COLUMN changed_by INTEGER REFERENCES users(id);
