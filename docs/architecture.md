# Architecture

Slate is a plain PHP 8.1+ web application with colocated routing (no front controller), SQLite for storage, and a single Composer dependency (Parsedown). The code prioritizes explicit, procedural patterns and centralizes cross-cutting concerns.

## Key Design Principles

- **Colocated routing**: URLs map directly to files on disk. For example, `public/project/index.php` serves `/project/`, and endpoint handlers live alongside it in the same directory.
- **No build step**: All frontend libraries (Bootstrap, htmx) are vendored in `public/assets/vendor/` and committed. Rendering requires no network access.
- **Procedural PHP**: No heavy OOP frameworks; there is one class per table in `src/models.php` (Project, User, Board, Comment, Invite, StatusLogEntry), but most logic is procedural.
- **Centralized helpers**: Shared behavior lives in `src/lib.php`, view rendering in `src/views.php`, bootstrap in `src/bootstrap.php`, and error handling in `src/errors.php`.

## Request Flow

1. Every page includes `../src/bootstrap.php` near the top. `bootstrap.php`:
   - Starts the session (`slate_start_session()`).
   - Runs the CSRF/origin header check via `require_same_origin()` (header-based CSRF model).
   - Sets up global state: loads all users into `$USERS`, sets `$me` if logged in.
2. Pages that require authentication include `../src/app.php` and call `require_login()` or `require_admin()`. `app.php` itself includes `bootstrap.php`.
3. State-changing endpoints validate `REQUEST_METHOD` (return 405 if wrong) and look up entities, returning 404 for unknown IDs. Mutations that touch projects use `editable_project()` (blocks archived projects).
4. Output is rendered via helpers in `src/views.php` or returned as htmx fragments. All user-facing strings pass through `h()` before output (except where a helper already returns escaped HTML like `status_label()`).
5. Database access goes through `q()` with prepared statements and bound parameters.

## Error Handling

- `src/errors.php` defines `slate_error_page()` (the standalone error page with inline CSS) and `slate_error_handler()`/`slate_exception_handler()` (turn warnings into exceptions, log errors to `data/error.log`).
- `bad($msg, $code=400)` returns either an htmx fragment (plain text sentence for toasts) or a full error page depending on whether the request came via htmx.
- The 500 handler renders the same error page. `public/404.php` uses `slate_error_page()` so unknown paths also get the consistent error UI.
- Every response gets security headers via `slate_security_headers()` (frame protection, nosniff, HSTS on TLS).

## Status System

Statuses are stored as rows in `project_statuses` (not hardcoded). The statuses API in `src/lib.php` provides:
- `statuses()` / `status_by_id()` / `status_by_slug()`
- `panel_order()` / `default_status_id()` / `default_done_status_id()`
- `status_label()` (returns HTML-escaped label text; do not double-escape with `h()`)
- `status_is_finished()`, `status_is_cancelled()`, `status_is_todo_like()`, `status_is_active_work()`

The UI reads statuses dynamically; never hardcode slug lists or `done` checks elsewhere.

## Project Mutations & Archival

- Projects are retired by archiving (`projects.archived = 1`, `projects.archived_at` set). Archiving is one-way; there is no un-archive or delete.
- Archived projects are read-only. Any mutating path must load via `editable_project()` (which fails if archived) rather than the bare `project()` lookup.
