-- 002: authentication, roles and invites.
-- Sign-in replaces the old "acting as" cookie. Accounts exist only once someone
-- creates them through setup (the first admin) or accepts an invite, so there is no
-- self-service signup. Users that predate this migration keep their rows but have a
-- NULL password_hash and therefore cannot sign in; they stay as names on old records.

ALTER TABLE users ADD COLUMN email TEXT;
ALTER TABLE users ADD COLUMN password_hash TEXT;

-- SQLite allows any number of NULLs under a UNIQUE index, so the legacy users above
-- are unaffected while real addresses stay unique.
CREATE UNIQUE INDEX IF NOT EXISTS users_email ON users(email);

-- A user may hold several roles. Seeded with the two the app knows about; add more
-- rows here (or by hand) and they show up in the admin UI automatically.
CREATE TABLE roles(
  id INTEGER PRIMARY KEY,
  slug TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL);

CREATE TABLE user_roles(
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  PRIMARY KEY(user_id, role_id));

INSERT OR IGNORE INTO roles(slug,label) VALUES ('admin','Administrator'),('member','Member');

-- One row per issued invite. token is single use: accept_invite.php stamps accepted_at,
-- and expires_at bounds how long the link stays usable.
CREATE TABLE invites(
  token TEXT PRIMARY KEY,
  email TEXT NOT NULL,
  name TEXT,
  role_id INTEGER REFERENCES roles(id),
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  accepted_at TEXT,
  accepted_by INTEGER REFERENCES users(id));

-- Failed sign-ins, so the login form is not an open guessing oracle.
CREATE TABLE login_attempts(
  email TEXT NOT NULL,
  attempted_at TEXT NOT NULL);

CREATE INDEX IF NOT EXISTS login_attempts_email ON login_attempts(email);