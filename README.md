# Slate

A small project tracker: a dashboard, drag-and-drop boards, projects with assignees, comments,
due dates and a status history.

Plain PHP 8.1+ and SQLite. No build step, no framework, one Composer dependency (Parsedown, for
markdown in descriptions and comments).

## Features

- **Dashboard** — open and assigned projects.
- **Boards** — boards of drag-and-drop columns; projects can be on more than one.
- **Projects** — statuses, assignees, due dates, comments, subprojects, and a status history.
- **Search** — by title, description, comments or assignee, from a box in the header on every page.
- **Statuses** — configurable at `/admin/statuses/`: rename, reorder, mark finished or delete them.
  They are rows in `project_statuses`, not hard-coded.
- **Accounts** — invites, roles and deactivation, all admin-only. Sign in with a password or Slack.
- **Themes** — each account chooses its own look on `/account/`: the default, Bootstrap's dark mode,
  or a little seasonal decoration. It is a palette, not a theme editor, and only the account that
  chose it sees it.
- **Archiving** — a project can be archived, which hides it from every default view and makes it
  read-only. The row, its comments and its history are kept.

## How it is built

Plain PHP, SQLite, and server-rendered HTML. htmx and Bootstrap are served from the app's own
`public/assets/vendor/` (pinned versions committed to the repository), so no page makes a request
to a third-party host and no client needs internet access. There is no build step, and the only
custom JavaScript is error toasts and the board's drag highlight.

URLs map to files on disk: `public/project/index.php` is `/project/`, and its endpoints sit beside
it. There is no front controller. Shared behavior is centralized — statuses are one table, all
output goes through `h()`, and every state-changing request goes through one cross-origin check.

A crash returns an HTTP 500 with a complete page and a reference code, logged to `data/error.log`.

## Requirements

- PHP 8.1 or newer, with `pdo_sqlite` enabled
- [Composer](https://getcomposer.org/), for the one library the app installs
- A web server that can run PHP (nginx, Apache, Caddy, or PHP's own built-in server for local use)

Run `php bin/check.php` on a new machine to confirm all of that at once. It exits non-zero if
something is missing and reports the state of `data/board.db`.

`mbstring` is used when present but is not required; without it, search only folds case for ASCII
letters.

Bootstrap and htmx are vendored under `public/assets/vendor/` at pinned versions and committed
alongside the code, so rendering pages needs no network at all.

## Install

```sh
git clone <this repo> slate && cd slate
composer install          # Parsedown into vendor/; vendor/ is gitignored
php bin/migrate.php       # creates data/board.db and applies migrations
```

Point the web server's document root at **`public/`**, not the repository root. `src/`, `db/`,
`bin/`, `data/` and `vendor/` sit outside it and are not reachable over HTTP.

```
server {
    root /srv/slate/public;
    index index.php;
    error_page 404 /404.php;
}
```

An unknown path never reaches PHP (there is no front controller), so the web server has to be told
where the 404 page is. Apache: `ErrorDocument 404 /404.php`.

Keep `data/` writable by the user the web server runs as. Everything the process creates there —
`data/` itself, `board.db`, `error.log` — is created `0750`/`0640`, readable only by the web
server's user and group. An older install keeps whatever mode it already has; bring it in line once
with `chmod 750 data && chmod 640 data/board.db`. Then open the site: the first visit to
`/setup/` creates the first account, which becomes an admin. Once any account can sign in, `/setup/`
closes itself.

### Trying it out without a web server

```sh
php -S localhost:8000 -t public
```

For local use only. PHP's built-in server handles one request at a time, so pages that load htmx
fragments queue up and may appear to hang.

## Configuration

All are optional, set as environment variables on the PHP process. Each has the default stated
below when it is unset. Slack sign-in stays off until `SLACK_CLIENT_ID` and `SLACK_CLIENT_SECRET`
are provided.

| Variable | Default when unset | Effect |
| --- | --- | --- |
| `SLATE_SETUP_KEY` | Setup closes once an account exists | Requires this key at `/setup/`, so it stays reachable after setup. Set it if you might ever need to create another admin. |
| `SLATE_DEBUG` | Errors show only a reference code | `1` shows full error detail on the page. Development only — it discloses paths and SQL. |
| `SLATE_CSRF_STRICT` | Requests with no cross-origin headers are allowed | `1` refuses state-changing requests that carry no cross-origin headers at all. |
| `SLACK_CLIENT_ID` | Slack sign-in and linking are disabled | Slack app client id. Required for Slack sign-in and linking. |
| `SLACK_CLIENT_SECRET` | Slack sign-in and linking are disabled | Slack app client secret, used to exchange the OAuth code. Required for Slack sign-in and linking. |
| `SLACK_REDIRECT_URI` | `<scheme>://<host>/login/slack-callback.php` | The redirect URI registered with the Slack app. |
| `SLACK_ALLOWED_TEAM_ID` | Any workspace is accepted | Limits Slack sign-in and linking to members of that workspace (`T…` id). |
| `SLACK_ALLOW_SIGNUP` | Slack only signs in already-linked accounts | `1` allows Slack sign-in to create new accounts. |

## Sessions

A signed-in session lasts two weeks (`SESSION_LIFETIME`). PHP's default `session.gc_maxlifetime` is
only 24 minutes, so raise it to match or sessions will silently expire early. Set it globally in
`php.ini`, for whichever SAPI serves the app:

```sh
# match <version> and <sapi> to `php -i | grep 'Loaded Configuration'`
sudo tee /etc/php/8.2/fpm/conf.d/99-session-lifetime.ini >/dev/null <<'INI'
session.gc_maxlifetime = 1209600
INI
sudo systemctl reload php8.2-fpm
```

Terminate TLS in the web server so PHP sees `HTTPS=on` and marks the cookie `Secure`. The app does
not trust `X-Forwarded-Proto` itself; if you terminate TLS at a proxy, set `HTTPS=on` for PHP or
enable `session.cookie_secure`.

## Backups

The database is the only stateful data. It runs in WAL mode, so copying `board.db` while
the app is running can miss recent writes. Use the backup script, which takes a consistent snapshot:

```sh
php bin/backup.php                     # -> data/backup-20261003-214500.db
php bin/backup.php /mnt/usb/slate.db   # or a path of your own
```

Back up somewhere other than the machine running the app. To restore: stop the app, copy the snapshot
over `data/board.db`, delete any `board.db-wal` and `board.db-shm` beside it, and start the app.

## Tests

```sh
php  bin/check.php                          # is this PHP able to run the app
cd tests && .venv/bin/python -m pytest -q   # the Python suite (200+ tests)
php  tests/schema.php                       # migrations and the schema ledger
node tests/drag.js                          # the board's client-side drag highlight
```

The suite is Python (`tests/test_*.py`), run from `tests/`. It copies the app to a temp directory,
migrates a throwaway database there, and drives it over HTTP — never touching `data/board.db`.
`test_browser_tabs.py` drives headless Chromium and skips itself if there is none.

## Security posture

Notes for deploying this publicly:

- Passwords are hashed with `password_hash()` and verified with `password_verify()`; a refusal
  costs one hash verification whether or not the address has an account.
- Sign-in is throttled: 8 failures per email address within a 15-minute window.
- Setup is closed once an account exists, and can be keyed with `SLATE_SETUP_KEY`.
- Invitation links are single-use, expire after two weeks, and use 24 bytes from `random_bytes()`.
- SQL goes through prepared statements with bound parameters; output through `h()`.
- Markdown is rendered by Parsedown in safe mode. Only two anchors can come out of a body — a bare
  http(s) URL and a `#NN` project reference — and both repeat their target as the visible text.
  Bracket links are not parsed at all, so a body can never show one label and go to another.
- Every response carries `X-Frame-Options: DENY` and `X-Content-Type-Options: nosniff`, plus HSTS
  when the request arrived over TLS.
- Changing a password stamps every other session for sign-out: they are refused on their next
  request, not retroactively disconnected.
- **No CSRF tokens.** Instead one guard checks every state-changing request against request headers
  that another site cannot forge (`Sec-Fetch-Site`, then `Origin`/`Referer`). Requests with none of
  those headers are allowed, so curl and the test suite work; set `SLATE_CSRF_STRICT=1` to refuse
  them. `SameSite=Lax` on the session cookie carries the main weight.

## Known limitations

Not built yet:

- **No email.** Invitations are a link an admin copies and sends by hand. No password reset, no
  notifications.
- **No pagination.** The list view and boards render every row.
- **Search is substring only.** `bnch` finds nothing.
- **Single-node assumptions.** SQLite in WAL mode on a local disk, one app instance. Do not point two
  servers at one database file, and do not put it on NFS.
- No licence and no privacy policy page.

## Layout

```
public/     the document root: pages, endpoints and assets, colocated
src/        bootstrap, shared library, views, error handling
db/         migrations, applied in filename order
bin/        migrate.php, backup.php, check.php
tests/      the Python suite, schema checks, fixtures, drag.js
data/       board.db, created by migrate.php, never committed
vendor/     composer install output (Parsedown), never committed
```

Every link is absolute from the root.
