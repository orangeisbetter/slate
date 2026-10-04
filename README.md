# Slate

A small project tracker: a dashboard, drag-and-drop boards, projects with assignees, comments,
due dates and a status history. Plain PHP and SQLite, no build step, no dependencies to install.

## Requirements

- PHP 8.1 or newer, with `pdo_sqlite` enabled
- A web server that can run PHP (any of nginx, Apache, Caddy, or PHP's own built-in server for
  trying it out)

There is nothing to install. No Composer, no npm, no vendored libraries. Run `php bin/check.php` on
any new machine, or after changing PHP version, to confirm all of that at once; it exits non-zero if
something the app needs is missing, and it also reports the state of `data/board.db` and whether its
migrations have been applied.

`mbstring` is used when it is there and not required. Without it, search folds ASCII case only, so
`GRÜN` will not match `grün`, and the length cap on a board name counts bytes, so accented names hit
the limit sooner. That is the whole cost, and it used to be a blank page instead: the pickers called
`mb_strtolower()` directly, so on a PHP without the extension every page carrying one stopped
rendering halfway down, with the reason visible only in the error log. `lower()` and `chars()` in
`src/lib.php` are the two helpers that avoid it, and `tests/flows.sh` refuses any direct `mb_` call
outside them.

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
with a reference code rather than as half-rendered HTML.

Every failure lands in two places. The SAPI log is where a developer expects it — under `php -S` that
is the terminal's stderr, and the `error_log` setting above moves it under Apache. And
`data/error.log`, which the app writes itself, keeps one previous generation and stays under about a
megabyte. The second exists because the SAPI log is somebody else's file on somebody else's schedule:
it is rotated by a policy you do not control, it is cluttered with everything else the server logged,
and on the built-in server it disappears with the terminal. `data/` is gitignored, so nothing in it is
ever committed.

Each entry carries the reference code shown to the user, so a report of "it said
`a1b2c3d4`" leads straight to the exception, the file and line, and the request that caused it. It
records request paths and messages, never addresses or passwords.

### When something goes wrong

The whole response is buffered. The status line goes out with the first flush of the body, so without
a buffer a 200 is already on the wire before a page has finished rendering and cannot be taken back
for a 500. That made the size of the page decide whether a crash was visible at all: identical code
returned a clean 500 for a failure near the top of a page and a silently truncated 200 for the same
failure further down. The cost is one page of memory per request.

So a failure is a real HTTP 500 with a complete page, never the half-built one it interrupted, and it
carries the reference in an `X-Slate-Error` header as well as on the page.

htmx does not swap a response that is not 2xx — it fires `htmx:responseError` and leaves the page
alone, which is the right default, since a failed fragment must not replace good markup with half an
error page. The cost is that a failed save is otherwise completely silent. `public/assets/app.js`
listens for `htmx:responseError`, `htmx:sendError` and `htmx:timeout` and shows a toast quoting the
reference, so the failure is visible and traceable to the log. A 401 from an expired session gets the
same treatment.

A failure is announced by a toast in the top right, and the markup and the behaviour are Bootstrap's,
from its [toast documentation](https://getbootstrap.com/docs/5.3/components/toasts/): a
`.toast-container position-fixed top-0 end-0 p-3` holding `.toast` elements, dismissed by the
documented `data-bs-dismiss="toast"` button and driven by `bootstrap.Toast`. The red is the documented
color-scheme treatment — `text-bg-danger` on the header, `btn-close-white` on its close button — so this
project carries no toast CSS of its own.

Each toast is split into the docs' two parts. A `.toast-header` bar reads "An error occurred", and that
header is where the colour goes; a `.toast-body` carries the specific message and reference on
Bootstrap's default background. The utility class belongs on the header rather than on the toast for a
reason worth keeping: `text-bg-danger` is `!important`, so on the toast it would flood the body in red
too, while `.toast-header` is the one part meant to be tinted. A single generic header also keeps a
stack of red bars legible at a glance, since what actually went wrong is said in the body, where there
is room to be specific.

Two things are set against the default, both because an error is not transient information.
`autohide: false`, because Bootstrap's default is five seconds and an error that clears itself is gone
before anybody has looked up from what they were doing; the docs note that this makes the close button
the only way out, which is why there is one. And they stack, since a second failure is new information
and keeping only the newest loses whichever of the two nobody has read yet. The container is built
empty on load rather than at the first failure, because the docs are explicit that a live region
created at the same moment as its content is generally not announced at all.

## Tests

```sh
php  bin/check.php     # is this PHP able to run the app at all
bash tests/auth.sh     # sign-in, setup, invites, roles, authorization, CSRF
bash tests/flows.sh    # projects, boards, drag and drop, htmx fragments
php  tests/schema.php  # migrations, constraints, the schema ledger
```

The two shell suites copy the app to a temp directory, migrate a throwaway database there, and drive
it over HTTP — `auth.sh` on port 8125, `flows.sh` on 8127. They never touch `data/board.db`.
`tests/seed.php` creates fixtures and is not meant for a real database. `flows.sh` also starts a
second server on the next port with `mb_strtolower` and `mb_strlen` disabled, because the extension
being absent is a failure the suite cannot otherwise see from a machine that has it.

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

A name or a board that appears on a page is a link to the thing it names. People link to their own
row on the user list, `users/#user-3`, which any signed-in user can reach — so there is no separate
profile page to build first, and when one arrives only the href in `user_link()` changes. Boards link
to `board/?id=`. Two places are deliberately left as plain text: the picker dialogs, where clicking a
row adds it rather than going anywhere, and the avatars on a board card, because the card is itself a
link and an anchor inside an anchor does not work. `tests/flows.sh` fails if any page grows a nested
anchor, and checks that every user reference still has a row to land on.

The assignee and board lists on a project page show a name and nothing else. The picker dialogs next
to them still show the address and the project count, because clicking a row there adds it rather
than navigating, and the extra line is what tells two similarly named things apart. The htmx endpoints
redraw the lists in place, so they follow the same rule; otherwise the detail would reappear the
moment anyone edited the list.

The header carries a project search to the left of the New project button, on every page. It asks the
server as you type and swaps a menu of matches underneath, offering eight and then deferring to the
rest, and it takes a project number as readily as a title: `4` and `#4` both find the same project,
which is what makes the `#4` worth reading. The form is a real GET to `search.php`, so with JavaScript
unavailable the same box submits and returns a page of every match, and `/search.php?q=fix` is a
shareable list. Titles are the only thing matched. Two details are deliberate: the input sits outside
the region htmx replaces, because replacing the box a person is typing into takes the caret with it and
would leave the field accepting exactly one letter; and `%` and `_` are escaped, so a query of `%`
looks for a percent sign rather than returning every project in the install. SQLite folds ASCII case
but not the rest, so a title with an accented letter matches only on its exact case.

A failure partway through a page used to be almost invisible. `src/errors.php` could not send an error
page once output had started, so it logged and returned, and the browser was left holding half a
document with no message in it — a truncated page that reads as a layout bug rather than a crash. That
is the right trade for paths and SQL, and it is what the rendering checks in the suite were written
against: they walk the signed-in pages and fail unless each one ends in `</html>` and leaks no PHP
diagnostic, and `tests/auth.sh` does the same for the sign-in page. A project page once stopped dead
at the top of its right column with the whole suite green, because every assertion in it greps for
content the page had already managed to emit; those checks exist because of it.

Buffering, described above, is what removed the other half of the problem. A crash is now a 500 with a
complete page rather than a 200 with half of one, `tests/flows.sh` asserts that directly against a copy
of the app with a fault injected into it — late in a page, and in an htmx endpoint — and
`data/error.log` records what happened. `SLATE_DEBUG=1` puts the detail on the page for development,
and `php bin/check.php` says whether the PHP itself is set up to run the app.

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
  not for thousands. The header search caps its menu at eight and sends the rest to `search.php`,
  which still lists every match.
- **Search matches titles only.** Not descriptions, comments or assignee names, and not a fuzzy
  match: `bnch` finds nothing. Widening it means deciding what a project is "found by", which is
  a smaller change to `search_projects()` than it looks.
- **No CSRF tokens**, by the design described above. The header check covers current browsers; a
  token in the form is the belt-and-braces option if you need one.
- **No LICENCE, and no privacy policy page.** The footer says only what is true of this app: nothing is
  collected or sent anywhere by the app itself, and the assets come from a CDN. There is no licence
  file to name, no contact address to link and no policy text to publish, so none of the three is
  invented to fill the space. All three are small additions: a licence is one file, and a contact
  address would fit the environment variables above.
- **Single-node assumptions.** SQLite in WAL mode on a local disk, one app instance. Do not point two
  servers at one database file, and do not put the database on NFS.
