-- 015: a sessions table, so the account page can list the browsers holding an account and sign
-- them out one at a time or all at once.
--
-- PHP still owns the session data files and the cookie; this table is only an index over them,
-- keyed by sha256(session_id()) so the raw session id never sits in the database. A session with
-- no row is refused by require_login(): that single rule is revocation (revoke one, revoke all
-- others) and credential change (a password change deletes the account's other rows) without a
-- second mechanism to keep in step.
--
-- It supersedes users.session_version (014), which signed every other session out on a password
-- change but could not address one session alone. The column goes with this migration: one
-- source of truth for which sessions are welcome. Sessions signed in before this migration have
-- no row and are refused once -- the same one-off everybody re-signed in after 014, and for the
-- same reason: there is nothing to backfill from.
--
-- Rows age out with the sessions they describe: login_user() prunes anything not seen for
-- SESSION_LIFETIME, and last_seen_at is touched (at most once a minute) by the request that
-- already looked the row up. A row can therefore only outlive its session by the same two weeks
-- PHP's own gc is configured for (see the README); a ghost row in the list is a session that
-- will be refused anyway, and signing it out removes it.
CREATE TABLE sessions(
  id TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  user_agent TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL);

CREATE INDEX sessions_user ON sessions(user_id);

-- SQLite 3.35+ (2021-03). On an older build this fails inside the migration's transaction,
-- so nothing is applied and the message names the statement; bin/check.php reports the version
-- before you get that far. The column is unused the moment this file applies.
ALTER TABLE users DROP COLUMN session_version;
