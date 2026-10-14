-- 012: when a project was archived.
--
-- Archiving is one-way: there is no un-archive, so the date it happened is the only record of
-- when a project left the screens. It is also what a later review would measure age against --
-- the "purge what has been archived for years" idea, decided then rather than now. Projects
-- archived before this column exist stay NULL, which is honest: the flag is what matters, the
-- date is what a person wants to read, and a NULL says we never recorded it.
ALTER TABLE projects ADD COLUMN archived_at TEXT;
