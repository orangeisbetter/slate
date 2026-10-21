# Database

Slate uses SQLite (`data/board.db`) for all persistence. Migrations live in `db/` and are applied by `bin/migrate.php`. `tests/schema.php` validates recorded checksums against migration files.

## Two Faces of `data/board.db`

This single file serves two distinct purposes — confusing them is the easiest way to make mistakes:

1. **The app's database.** Stores users, projects, boards, comments, statuses, invites, etc. The app's tables define the actual application behavior.
2. **The team's task board.** The same database contains a project called "Slate App Improvements" (board id **2**, linked via `project_boards`) whose rows track work *on this app itself*. Other boards in the install are unrelated domains (3 = Electrical, 4 = Software, 5 = Telem) and must never be touched.

When "the board" is mentioned in an agent context, it refers to board 2. Open cards live in `todo`/`blocked`/`in_progress`; finished ones in `done` or `cancelled`. Card types are `task`, `feature`, `bug`, `epic`, or `research`. The `description` column is the spec (write in first person, plain style).

### Common Board Queries

```sh
sqlite3 data/board.db "
  SELECT p.id, p.type, p.status, p.title
  FROM projects p JOIN project_boards pb ON pb.project_id = p.id
  WHERE pb.board_id = 2 AND p.status NOT IN ('done','cancelled')
  ORDER BY p.id;"
```

### Board Operations (via sqlite3)

- **Add a card**: `INSERT INTO projects(...)` with `status='todo'`, `creator_id=1`, timestamps from `strftime('%Y-%m-%dT%H:%M:%S+00:00','now')`, then link via `INSERT INTO project_boards(project_id,board_id,added_by,added_at) VALUES(last_insert_rowid(),2,1,...)`.
- **Close a card**: `UPDATE projects SET status='done', updated_at=... WHERE id=N`, and `INSERT INTO status_log(project_id,from_status,to_status,changed_at,changed_by)` with `changed_by=1` (the board's account). Include history for every status change.
- **Reject an idea**: Set `status='cancelled'` and append the reason to the description (precedent: card #37). Do not delete cards.

If the app server is running and you can log in, the app endpoints (`/new/add.php`, `/project/update_status.php`) are preferred as they write the same rows with proper history. Either way, never touch any board but 2.

## Schema & Migrations

- Migrations in `db/` are named in order (`00X-*.sql`). `bin/migrate.php` splits on `;` and only strips **full-line** `--` comments (no semicolons inside strings, no trailing comments after statements).
- Never edit an applied migration — `tests/schema.php` checks every recorded checksum against the file and fails if mismatched. Add a new migration file instead.
- After migrating, run `php bin/check.php` and `php tests/schema.php`; both must be green.

## Key Tables (for reference)

- `users`: accounts; the first created via `/setup/` becomes admin. Admins can manage users/statuses/invites.
- `projects`: the core entity; has `status_id` (FK to `project_statuses`), `archived`/`archived_at` (archival is one-way), `creator_id`, timestamps, etc.
- `project_statuses`: source of truth for statuses (slug, label, panel order, `is_done`, `is_cancelled`, etc.). Edited via `/admin/statuses/`.
- `boards` / `project_boards`: boards and membership; board 2 is the app's own task board.
- `comments`, `invites`, `status_log`: activity/history.
