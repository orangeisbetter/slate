# Slate

A small project tracker: a dashboard, drag-and-drop boards, projects with assignees, comments,
due dates and a status history. Plain PHP and SQLite, no build step, no dependencies to install.

## Requirements

- PHP 8.1 or newer, with `pdo_sqlite` enabled
- A web server that can run PHP (any of nginx, Apache, Caddy, or PHP's own built-in server for
  trying it out)

There is nothing to install. No Composer, no npm, no vendored libraries.

Bootstrap and htmx are loaded from a CDN (`cdn.jsdelivr.net`, pinned to exact versions), so a client
needs internet access to render the pages. For an air-gapped install, download those three files and
point the `<link>`/`<script>` tags in `src/views.php` at them.

## Install

```sh
git clone <this repo> slate && cd slate
php bin/migrate.php
```

`bin/migrate.php` creates `data/board.db`, applies every migration in `db/` that has not run yet,
and prints what it did. It is safe to run again: migrations that have already been applied are
skipped. Keep the `data/` directory writable by the user the web server runs as — it holds the
database and nothing else, and nothing in it belongs in version control.

Point the web server's document root at **`public/`**, not at the repository root:

```
server {
    root /srv/slate/public;
    index index.php;
    ...
}
```

This matters. `src/`, `db/`, `bin/`, `data/` and `tests/` all sit next to `public/` on purpose,
outside the document root, so they are not reachable over HTTP. Serving the repository root would
expose the database directory and the source.

Finally open the site in a browser. The first visit to `/setup/` creates the first account, which
becomes an admin. Once any account can sign in, `/setup/` closes itself.

### Trying it out without a web server

```sh
php -S localhost:8000 -t public
```

This is for local use only. PHP's built-in server is single-threaded, so a page that loads
fragments over htmx will queue behind itself and appear to hang.

## Configuration

Everything is optional and set as environment variables on the PHP process.

| Variable | Effect |
| --- | --- |
| `SLATE_SETUP_KEY` | Requires this key at `/setup/`. Optional on a fresh install; once an account exists, `/setup/` is only reachable with it. Set it if you might ever need to create another admin. |
| `SLATE_DEBUG=1` | Renders the full detail of an error on the page instead of just a reference code. For development only — it discloses file paths and SQL. |
| `SLATE_CSRF_STRICT=1` | Refuses state-changing requests that carry no cross-origin headers at all, instead of allowing them. See below. |

## Backups

The database is the only state that matters, and it runs in WAL mode, so **copying `board.db`
while the app is running can silently miss recent writes** that are still sitting in `board.db-wal`
beside it. Use the backup script instead, which asks SQLite for a consistent snapshot:

```sh
php bin/backup.php                     # -> data/backup-20261003-214500.db
php bin/backup.php /mnt/usb/slate-$(date +%F).db
```

It refuses to overwrite an existing file and refuses a target that does not end in `.db`. Back up
somewhere other than the machine running the app.

To restore: stop the app, copy the snapshot over `data/board.db`, delete any `board.db-wal` and
`board.db-shm` left beside it (they belong to the database being replaced), and start the app again.

## Serving it over HTTPS

Terminate TLS in the web server, so PHP sees `HTTPS=on` and can mark the session cookie `Secure`.
The session cookie is set `HttpOnly` and `SameSite=Lax`, and its path follows the mount point, so a
subdirectory install does not hand its cookie to the rest of the host.

If you terminate TLS at a reverse proxy instead, PHP will not see HTTPS and the cookie will be sent
without the `Secure` flag. Either have the proxy set `HTTPS=on` for PHP, or turn on
`session.cookie_secure` in `php.ini`. The app does not read `X-Forwarded-Proto` itself, because
trusting that header without knowing the proxy setup is how people end up accepting forged requests.

Worth setting in `php.ini` for a small site like this:

```ini
expose_php = Off
display_errors = Off          ; src/errors.php logs the detail and shows a reference code
log_errors = On
error_log = /var/log/php/slate.log
```

The app turns warnings into exceptions on purpose, so a coding mistake shows up as a logged error
with a reference code rather than as half-rendered HTML. Anything the handler catches is written to
`error_log()`, which is where to look when a page comes back as "Something went wrong".

## Tests

```sh
bash tests/auth.sh     # sign-in, setup, invites, roles, authorization, CSRF
bash tests/flows.sh    # projects, boards, drag and drop, htmx fragments
php  tests/schema.php  # migrations, constraints, the schema ledger
```

The two shell suites copy the app to a temp directory, migrate a throwaway database there, and drive
it over HTTP — `auth.sh` on port 8125, `flows.sh` on 8127. They never touch `data/board.db`.
`tests/seed.php` creates fixtures and is not meant for a real database.

## How it is put together

```
public/     the document root: pages, endpoints, and assets, colocated
src/        bootstrap, shared library, views, error handling
db/         migrations, applied in filename order and recorded in schema_migrations
bin/        migrate.php, backup.php
tests/      shell suites, schema checks, fixtures
data/       board.db, created by migrate.php, never committed
```

URLs mirror the files: `public/project/index.php` is `/project/`, and its endpoints sit beside it as
`public/project/add.php`, `public/project/update.php`, `public/project/assign.php`, and so on. Pages
include `../src/bootstrap.php`, which is the single place that starts the session, loads shared code
and applies the cross-origin check.

A project is addressed by its row id, and that number is also shown next to its title wherever a
project is named, as `#4`. So the thing you read on a card is the thing you type into
`project/?id=4` — there is no second identifier to keep in step, and nothing to allocate. The number
carries no product prefix on purpose: a deployment on its own subdomain keeps its own ids, so the
subdomain is what says which install a number belongs to. `ref_badge()` in `src/views.php` is the
only place that formats it.

## Security posture

Worth knowing before you expose this to anyone:

- Passwords are hashed with PHP's `password_hash()` and verified with `password_verify()`.
- Sign-in is throttled: 8 failures per email address within a 15-minute window.
- Setup is closed once an account exists, and can be keyed with `SLATE_SETUP_KEY`.
- Invitation links are single-use, expire after two weeks, and are unguessable: 24 bytes from
  `random_bytes()`. `SLATE_SETUP_KEY` is compared with `hash_equals()`.
- SQL goes through prepared statements with bound parameters. Three queries interpolate instead of
  binding — one `id` cast to `int`, one list of generated `?` placeholders, and a sort key narrowed to
  two literals before it reaches the query — and none of them carry user text unescaped.
- Output is escaped through `h()`; HTML is written into templates rather than assembled by string
  concatenation.
- **CSRF**: there are no per-form tokens. Instead, `src/bootstrap.php` checks every state-changing
  request against the headers a browser will not let another site forge — `Sec-Fetch-Site` must be
  `same-origin`, and failing that, `Origin` or `Referer` must start with this site's own origin. That
  check lives in one place, so no endpoint can forget it and no form needs a hidden field. Requests
  carrying none of those headers are logged and allowed, which keeps curl, the test suites and older
  browsers working; set `SLATE_CSRF_STRICT=1` to refuse those instead.

## Known limitations

Deliberately not built yet, and worth deciding on before this grows:

- **No way to deactivate a user.** An account can be created and its role changed, but not retired.
  Remove someone's access by changing their role, or delete the row.
- **No email.** Invitations are a link an admin copies and sends by hand; there is no mail transport,
  no password reset, and no notification of any kind.
- **No pagination.** The list view and the boards render every row. Fine for tens of projects,
  not for thousands.
- **No CSRF tokens**, by the design described above. The header check covers current browsers; a
  token in the form is the belt-and-braces option if you need one.
- **Single-node assumptions.** SQLite in WAL mode on a local disk, one app instance. Do not point two
  servers at one database file, and do not put the database on NFS.
