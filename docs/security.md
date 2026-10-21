# Security

Slate's security posture is documented as binding in the codebase.

## Input/Output Safety

- **SQL**: All database queries go through `q()` with prepared statements and bound parameters. Never interpolate user input into SQL strings.
- **Output escaping**: Every user-facing string passes through `h()` before being output in HTML. The exception is `status_label()`, which returns HTML-escaped text already — do not wrap it in `h()` again (that would double-escape). Where a raw label is needed and the caller escapes everything itself, read the status row directly.
- **Markdown**: Bodies rendered with Parsedown in safe mode. Only two anchors can emerge: a bare `http(s)` URL and a `#NN` project reference; both repeat their target as the visible text. Bracket links are not parsed at all, so a body can never show one label and go to another.

## CSRF & Origin

- **No CSRF tokens**. Instead, `require_same_origin()` (called from `bootstrap.php`) checks request headers that another site cannot forge: first `Sec-Fetch-Site`, then `Origin`/`Referer`. 
- **Permissive by default for tooling**: Requests with none of those headers are allowed (so `curl` and the test suite work). Set `SLATE_CSRF_STRICT=1` to refuse them.
- **Session cookie**: `SameSite=Lax` carries the main weight for browser requests.

## Headers

- `X-Frame-Options: DENY` and `X-Content-Type-Options: nosniff` on every response.
- `Strict-Transport-Security` (HSTS) added when the request arrived over TLS.
- Set by `slate_security_headers()` in `src/errors.php`.

## Auth & Sessions

- Sessions are managed by `slate_start_session()` in `src/lib.php`. Password changes stamp other sessions (they are refused on their next request, not disconnected retroactively).
- Sign-in is throttled: 8 failures per email per 15 minutes. Avoid hammering one address with bad passwords in tests.
- Setup: `/setup/` creates the first account (becomes admin). Once any account can sign in, `/setup/` closes itself. Can be keyed with `SLATE_SETUP_KEY`.
- Invitations: single-use, expire after two weeks, use 24 bytes from `random_bytes()`. No email delivery — links are copied by hand.
- User list is admin-only at `/admin/users/`; `user_link()` links admins there and gives everyone else plain text.
- Unlinking Slack on the account page is refused without a password.

## Data & Operational

- `data/` is created with restrictive permissions (`0770`/`0660`) and must be writable by the web server user/group. Never commit `data/`, `tests/.venv/`, `tests/__pycache__/`.
- Errors log to `data/error.log`. Uncaught errors produce a complete error page with a reference code.
- Archived projects are read-only; mutating paths must use `editable_project()`. There is no un-archive or delete.
- Statuses form the source of truth (rows in `project_statuses`); editing happens at `/admin/statuses/`. A status can only be deleted if no projects are on it (the refusal names the count); history survives as the slug.
- Single-node assumptions: SQLite in WAL mode on local disk, one app instance. Do not point two servers at the same DB file or put it on NFS.
