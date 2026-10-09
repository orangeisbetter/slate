-- 007: drop the member role, and let an invite carry several roles.
-- "Member" gated nothing: every signed-in user can do everything except the admin-only
-- actions, so the role was a label with no effect behind it, and every account carried it.
-- A "member" badge next to an "admin" one also implied the two were alternatives. What an
-- account can do is decided by the admin role alone now.
--
-- Invites used to store a single role_id. They now store the roles granted as a
-- comma-separated slug list, so an invite can say "admin" plus a future role at once,
-- or no role at all.

ALTER TABLE invites RENAME TO invites_old;

CREATE TABLE invites(
  token TEXT PRIMARY KEY,
  email TEXT NOT NULL,
  name TEXT,
  roles TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  accepted_at TEXT,
  accepted_by INTEGER REFERENCES users(id));

INSERT INTO invites(token,email,name,roles,created_by,created_at,expires_at,accepted_at,accepted_by)
  SELECT i.token, i.email, i.name,
         COALESCE((SELECT r.slug FROM roles r WHERE r.id = i.role_id), ''),
         i.created_by, i.created_at, i.expires_at, i.accepted_at, i.accepted_by
  FROM invites_old i;

DROP TABLE invites_old;

-- No one keeps the membership once it stops meaning anything.
DELETE FROM user_roles WHERE role_id = (SELECT id FROM roles WHERE slug='member');
DELETE FROM roles WHERE slug='member';
