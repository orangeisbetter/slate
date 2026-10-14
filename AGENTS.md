# Working on Slate

Read `README.md` first — it is the design document, and it explains *why* nearly everything is the
way it is. This file is the rest: the things an agent needs that the README assumes or does not
cover, above all the odd way the database is used for two jobs at once.

## The two faces of `data/board.db`

One file, two roles, and confusing the two is the easiest way to make a mess:

1. **The app's database.** `data/board.db` is what the running app serves: users, projects, boards,
   comments, statuses. Migrations in `db/` shape it; `bin/migrate.php` applies them.
2. **The team's task board.** The same file contains a project called "Slate App Improvements"
   (board id **2**, joined via `project_boards`) whose rows track work *on this app*. Project #27's
   spec — the admin page — was a row in the database the code then changed. The other boards in the
   same install are unrelated domains (3 = Electrical, 4 = Software, 5 = Telem); never touch any
   board but 2.

So when "the board" is mentioned, it means a query, not a separate system:

```sh
sqlite3 data/board.db "
  SELECT p.id, p.type, p.status, p.title
  FROM projects p JOIN project_boards pb ON pb.project_id = p.id
  WHERE pb.board_id = 2 AND p.status NOT IN ('done','cancelled')
  ORDER BY p.id;"
```

Open cards live in `todo`/`blocked`/`in_progress`; finished ones in `done` or `cancelled`. Type is
`task`, `feature`, `bug`, `epic` or `research`. The description column is the spec — write it in the
first person, plain, the way the existing cards read.

## Reading and changing the board

The board is administered with **direct `sqlite3`**, not through the app, because the app server
usually is not running during a session and because an agent should not need a session cookie to
file a card. The recipes, all against `data/board.db`:

- **Add a card**: one `INSERT INTO projects(...)` with `status='todo'`, `creator_id=1`, timestamps
  from `strftime('%Y-%m-%dT%H:%M:%S+00:00','now')`, then link it with
  `INSERT INTO project_boards(project_id,board_id,added_by,added_at) VALUES(last_insert_rowid(),2,1,...)`.
- **Close a card**: `UPDATE projects SET status='done', updated_at=... WHERE id=N`, plus an
  `INSERT INTO status_log(project_id,from_status,to_status,changed_at,changed_by)` row so the history
  the app renders is complete. `changed_by` is the acting user id (use `1`, the board's own account);
  card #33 added that column, and the app records it on every status change it makes itself.
- **Reject an idea**: `status='cancelled'` and append the reason to the description (precedent:
  card #37). Do not delete cards; the board is the record of what was decided and why.

If the app server *is* running and you can log in, the app's own endpoints (`/new/add.php`,
`/project/update_status.php`) are better — they write the same rows with the history. Either way,
never touch any board but 2.

## Changing the app's schema

- Never edit an applied migration. `tests/schema.php` checks every recorded checksum against the
  file, and a mismatch fails the gate. Add `db/00N-thing.sql` instead.
- `bin/migrate.php` splits on `;` and only strips **full-line** `--` comments: no semicolons
  inside strings, no trailing comments after a statement.
- After migrating the real database, run `php bin/check.php` and `php tests/schema.php`; both
  must stay green.

## Running and testing

```sh
php -S localhost:8000 -t public          # serve the app
cd tests && .venv/bin/python -m pytest -q # the suite (200+ tests)
php tests/schema.php                     # schema gate
```

- The Python suite is the suite. It copies the app to a temp directory, migrates a fresh database
  and drives it over HTTP; it never touches `data/board.db`. Fixtures are in `tests/conftest.py`.
- The old bash suites are gone. The last live one, `browser-tabs.sh`, is now
  `test_browser_tabs.py`, which drives headless Chromium and skips if there is none. `drag.js`
  stays: the board's drag highlight is client-side and only a browser can test it.
- One server per pytest session, so **mutations leak between tests**: anything you change through
  the app and do not put back (a user's name, a password, the statuses table) decides later tests'
  outcomes. The patterns to copy: `test_admin.py`'s snapshot fixture for the statuses table, and
  `test_account.py`'s change-and-restore for credentials. `App.sql()` does not commit writes —
  direct inserts need their own connection with an explicit commit (see `insert_user()`).
- Sign-in is throttled (8 failures per email per 15 minutes); do not hammer one address with bad
  passwords across tests.

## Code conventions the app enforces

Plain PHP 8.1+, procedural, SQLite, one Composer dependency (Parsedown, for markdown), no build
step. `vendor/` is gitignored; `composer.json` and `composer.lock` are committed, and
`src/views.php` requires `../vendor/autoload.php`, so a checkout needs `composer install` once.
The README's security posture section is binding: every output goes through `h()`, SQL through `q()`
with bound parameters, no CSRF tokens (the header check, `require_same_origin()` in `src/lib.php`,
called from `src/bootstrap.php`, covers it).

- **One source of truth for statuses.** They are rows in `project_statuses` (migration 008), read
  through the API in `src/lib.php` (`statuses()`, `panel_order()`, `status_label()`,
  `status_is_finished()`…). Never hardcode a slug list, a `done` check or an order — that is what
  this codebase just finished removing.
- **Pages include `../src/bootstrap.php`** and call `require_login()`/`require_admin()`; every
  state-changing endpoint checks `REQUEST_METHOD` and answers 405 for the wrong one, 404 for an
  unknown id. Redirect back with `?saved=`/`?error=` plus a fragment naming the tab.
- **Every error uses one page.** `bad($msg, $code)` in `src/lib.php` answers an htmx fragment with a
  plain-text sentence (that is what the toast reads) and everything else with `slate_error_page()`
  in `src/errors.php`; the 500 handler renders that same page. It is a standalone document with
  inline CSS, not drawn by `views.php`, because it must work when the database or `src/` is the
  failure. `public/404.php`, which the web server serves for an unknown path, uses it too.
- **URLs are absolute from the root** (`/project/?id=4`, never relative), written in the template.
- **htmx dialogs come in twins** (`-dialog.php` fragment, unsuffixed page) sharing one field
  helper in `views.php`; both carry `hx-get` and `href`. Full-page mutations redirect;
  fragment mutations swap a whole form or region, never `none` — leaving stale UI on the page is
  card #42/#43, and the board drag (`board/move.php` re-rendering the board) is the reference
  pattern.
- **Account tabs** are CSS-only (`:target`/`:has()` in `public/assets/style.css`); no Bootstrap
  tab component, no JavaScript.
- The app turns warnings into exceptions, and an undefined array key is a warning: always use the
  fallback helpers (`status_label()`, `$USERS[$id] ?? …`) rather than reading a key directly.
- `&`-reference functions cannot declare a `: array` return type while holding `null`
  (`status_cache()` was bitten by this; use `?array`).

## Where things live

```
src/errors.php    the error page and the uncaught-error handler; loads first, depends on nothing
src/bootstrap.php session, CSRF header check, globals ($me, $USERS); every page requires this
src/app.php       login/admin guards and $me, loaded for pages that need a signed-in user
src/lib.php       helpers: DB, auth, statuses API, validation, project queries
src/views.php     all rendering: page chrome, forms, pickers, boards
src/models.php    one class per table (Project, User, Board, Comment, Invite, StatusLogEntry)
public/          document root; each directory is a page with its endpoints beside it
public/admin/    admin area: hub, statuses, users (admin-only; members get 403)
db/              migrations in filename order, recorded in schema_migrations
bin/             migrate.php, backup.php, check.php
tests/           pytest suite, schema.php, drag.js, seed.php
data/            board.db — gitignored, never committed, the two-faced file above
```

## Standing decisions

Do not relitigate these; they were decided and built:

- Statuses are rows, edited at `/admin/statuses/`; a status can only be deleted while no project
  is on it (the refusal names the count), and history survives as the slug.
- The user list is admin-only at `/admin/users/`; `user_link()` links admins there and gives
  everyone else plain text.
- The account page has two tabs, Profile and Sign-in; unlinking Slack is refused without a
  password.
- A project is retired by archiving it, and archiving is one-way: there is no un-archive and no
  delete. `projects.archived` hides it from every default view; `projects.archived_at` records when.
  An archived project is read-only: load it through `editable_project()` in `src/lib.php` on every
  mutating path, never bare `project()`. Nothing purges archived projects yet.
- The test suite is Python (`tests/test_*.py`); `drag.js` owns the one client-side check.
- Never commit unless the user asks. `data/`, `tests/.venv/`, `tests/__pycache__/` are gitignored.
