# Development Guide

## Prerequisites

- PHP 8.1 or newer with `pdo_sqlite` enabled
- Composer (https://getcomposer.org/) for the single dependency (Parsedown)
- Web server or PHP built-in server for local use
- Python 3 for the test suite (tests use a virtualenv at `tests/.venv/`)

Verify environment: `php bin/check.php` exits non-zero if anything is missing and reports the state of `data/board.db`.

Note: `mbstring` is used when present but not required; without it, search only folds case for ASCII letters.

## Installation

```sh
git clone <this repo> slate && cd slate
composer install          # installs Parsedown into vendor/ (vendor/ is gitignored)
php bin/migrate.php       # creates data/board.db and applies migrations
```

## Running the App

Point the web server's document root at **`public/`**, not the repository root. `src/`, `db/`, `bin/`, `data/`, and `vendor/` are outside `public/` and not reachable over HTTP.

Example (PHP built-in server):
```sh
php -S localhost:8000 -t public
```

Example nginx config snippet (404 must be configured since no front controller):
```nginx
server {
    root /srv/slate/public;
    index index.php;
    error_page 404 /404.php;
}
```

Apache: `ErrorDocument 404 /404.php`

Ensure `data/` is writable by the web server user/group. Files are created with `0770`/`0660`. If users differ, the shared group needs write access.

First visit to `/setup/` creates the first account (becomes admin). Once any account exists, `/setup/` closes itself.

## Testing

The Python test suite is authoritative:
```sh
cd tests && .venv/bin/python -m pytest -q  # 200+ tests
```

- Tests copy the app to a temp directory, migrate a fresh DB, and drive over HTTP. They never touch `data/board.db`.
- One server per pytest session — **mutations leak between tests**. Follow existing patterns for isolation: see `test_admin.py`'s snapshot fixture for statuses, `test_account.py`'s change-and-restore for credentials.
- Direct DB writes in tests: `App.sql()` does not commit; direct inserts need their own connection with explicit commit (see `insert_user()`).
- Sign-in throttling: 8 failures per email per 15 minutes. Don't hammer the same address across tests.
- Schema gate: `php tests/schema.php` validates migration checksums.
- Browser tests: `test_browser_tabs.py` drives headless Chromium and skips if none available. `drag.js` is the client-side board drag check.

## Conventions & Gotchas

- Follow existing code style. Plain PHP 8.1+, procedural, SQLite. No build step.
- When editing, understand surrounding context (especially imports). Mimic conventions; check if a library already exists before adding dependencies.
- **NEVER** commit secrets or keys. All output escaped via `h()`, SQL via `q()` with bound params.
- Warnings become exceptions; never read array keys directly without fallbacks (use `status_label()`, `$USERS[$id] ?? …`).
- `&`-reference functions cannot declare a `: array` return type while holding `null` (use `?array`).
- Statuses are the source of truth — never hardcode slug lists, `done` checks, or panel orders. Use the statuses API in `src/lib.php`.
- `status_label()` returns HTML-escaped text; do not wrap in `h()`.
- Archived projects are read-only; use `editable_project()` on mutating paths, never bare `project()`.
- Every state-changing endpoint checks `REQUEST_METHOD` (405) and unknown IDs (404). Redirect back with `?saved=`/`?error=` plus fragment naming the tab.
- URLs are absolute from root. htmx dialogs come as twins (`-dialog.php` fragment + unsuffixed page). Full-page mutations redirect; fragment mutations swap whole form/region.
- Account tabs are CSS-only (`:target`/`:has()` in `public/assets/style.css`); no Bootstrap tab JS.

## Migrations

- Add new migrations as `db/00N-*.sql` (never edit applied ones).
- `bin/migrate.php` splits on `;` and strips only full-line `--` comments. Avoid semicolons inside strings and trailing comments after statements.
- After adding a migration, run `php bin/migrate.php`, then `php tests/schema.php` and `php bin/check.php`.

## Git & Commits

- **Never commit unless explicitly asked.** `data/`, `tests/.venv/`, `tests/__pycache__/` are gitignored.
- Do not edit applied migrations; a checksum mismatch will fail the schema gate.
