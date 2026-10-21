# Endpoints & Routing

Slate uses colocated routing: URLs map directly to files under `public/`. There is no front controller.

## Conventions

- **Colocation**: `public/<path>/index.php` serves `/<path>/`. Endpoint handlers live in the same directory (e.g. `add.php`, `update.php`, `move.php`, `delete.php`).
- **Absolute URLs**: Templates write URLs from root (`/project/?id=4`, never relative).
- **Fragments vs full pages**: htmx dialogs come in twins — a `-dialog.php` fragment and an unsuffixed page sharing the same field helper. Full-page mutations redirect; fragment mutations swap a whole form or region (never `none`).
- **Method validation**: State-changing endpoints check `REQUEST_METHOD` and return 405 for the wrong method; return 404 for unknown IDs.
- **Auth/permissions**: Pages include `../src/bootstrap.php` and call `require_login()`/`require_admin()` as appropriate. Mutating project operations use `editable_project()` to block archived projects.
- **CSRF/origin guard**: `require_same_origin()` (in `src/lib.php`, called from `bootstrap.php`) enforces header-based CSRF. It checks `Sec-Fetch-Site`, then `Origin`/`Referer`. Requests with none of those headers are allowed by default (for curl/tests); set `SLATE_CSRF_STRICT=1` to refuse them. `SameSite=Lax` on the session cookie is the main protection.
- **Output**: All user-facing output goes through `h()` except where helpers return escaped HTML (e.g. `status_label()` returns escaped text; do not double-escape).

## Core Routes (high level)

- `/` — index/home
- `/setup/` — first-run account creation; closes once any account exists (or keyed by `SLATE_SETUP_KEY`)
- `/signin/`, `/signout/`, `/account/` — auth and profile
- `/project/` — project view and related endpoints (`update.php`, `archive.php`, etc.)
- `/boards/` — boards views; board drag/move uses `board/move.php` re-rendering the board (reference pattern)
- `/new/` — create new items (`add.php`)
- `/comments/` — comment endpoints
- `/invite/` — invitation links (single-use, expire after 2 weeks; 24 bytes from `random_bytes()`)
- `/admin/` — admin hub, users, statuses (admin-only; members get 403)

## Error Responses

- Unknown paths: web server serves `public/404.php` which uses `slate_error_page()` (consistent standalone error page).
- Application errors: `bad($msg, $code)` returns htmx fragment for htmx requests or full error page otherwise. 500s render the same error page.
