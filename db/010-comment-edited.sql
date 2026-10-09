-- 010: comments can be edited, so the record keeps when that last happened.
--
-- Comments were write-once: a typo could not be fixed. edited_at stays NULL until
-- the first edit, then carries when that edit happened. created_at keeps the
-- original time, so the history stays honest about having changed.
ALTER TABLE comments ADD COLUMN edited_at TEXT;
