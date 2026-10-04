-- 001: base schema.
-- Applied once by bin/migrate.php. Every statement is IF NOT EXISTS, so running this
-- against a database that predates it is a no-op rather than an error.

CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, name TEXT NOT NULL);

CREATE TABLE IF NOT EXISTS projects(
  id INTEGER PRIMARY KEY, title TEXT NOT NULL, description TEXT DEFAULT '',
  status TEXT NOT NULL DEFAULT 'todo', creator_id INTEGER REFERENCES users(id),
  type TEXT NOT NULL DEFAULT 'task',
  parent_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
  start_date TEXT, due_date TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);

CREATE TABLE IF NOT EXISTS project_assignees(
  project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id), PRIMARY KEY(project_id, user_id));

CREATE TABLE IF NOT EXISTS comments(
  id INTEGER PRIMARY KEY, project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
  author_id INTEGER REFERENCES users(id), body TEXT NOT NULL, created_at TEXT NOT NULL);

CREATE TABLE IF NOT EXISTS status_log(
  id INTEGER PRIMARY KEY, project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
  from_status TEXT, to_status TEXT NOT NULL, changed_at TEXT NOT NULL);