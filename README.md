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
`src/lib.php` are the two helpers that avoid it, and a direct `mb_` call belongs nowhere else.

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
| `SLACK_ALLOWED_TEAM_ID` | Limits Slack sign-in and linking to members of that workspace (the `T…` id). Unset means any workspace, as before. |

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
The session cookie is set `HttpOnly` and `SameSite=Lax`, with its path at `/`, since the app is served
from the document root.

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
cd tests && .venv/bin/python -m pytest -q   # the suite, including the account tabs in Chromium
php  tests/schema.php  # migrations, constraints, the schema ledger
node tests/drag.js     # the board's drag highlight, which is client-side
```

The suite is Python, in `tests/test_*.py`, run from `tests/` with pytest. It copies the app to a
temp directory, migrates a throwaway database there, and drives it over HTTP — never touching
`data/board.db`. `tests/conftest.py` holds the fixtures: the server, the clients, the accounts, and
`tests/seed.php` the rows they work against. Each module pins one part of the app: authentication and
authorization, projects, boards and their panels, search, validation, comments, assignees, the
dashboard, the list view, and — in `test_admin.py` — the admin area and the statuses it administers.

The account tabs are checked in a real browser by `test_browser_tabs.py`, which drives headless
Chromium at the rendered page and skips itself if there is no browser or the Bootstrap stylesheet
cannot be fetched. `tests/drag.js` is the other check that has to run outside the Python suite: the
board's drag highlight is client-side, and a class toggled inside a listener either works or fails
silently, so the only honest test is to run it.

## How it is put together

```
public/     the document root: pages, endpoints, and assets, colocated
src/        bootstrap, shared library, views, error handling
db/         migrations, applied in filename order and recorded in schema_migrations
bin/        migrate.php, backup.php
tests/      the Python suite, schema checks, fixtures, drag.js
data/       board.db, created by migrate.php, never committed
```

URLs mirror the files: `public/project/index.php` is `/project/`, and its endpoints sit beside it as
`public/project/add.php`, `public/project/update.php`, `public/project/assign.php`, and so on. Every
link is written from that root — `href="/project/?id=4"`, never `href="project/?id=4"` and never a bare
fragment — because pages sit at different depths and a relative or fragment-only URL would resolve
against whichever page the reader happens to be on. There is no `<base>` tag and no URL-building
helper: serving the app from a subdirectory is a rewrite in the web server, not something the app has
to detect at runtime. Pages include `../src/bootstrap.php`, which is the single place that starts the
session, loads shared code and applies the cross-origin check.

A project is addressed by its row id, and that number is also shown next to its title wherever a
project is named, as `#4`. So the thing you read on a card is the thing you type into
`project/?id=4` — there is no second identifier to keep in step, and nothing to allocate. The number
carries no product prefix on purpose: a deployment on its own subdomain keeps its own ids, so the
subdomain is what says which install a number belongs to. `ref_badge()` in `src/views.php` is the
only place that formats it.

A name or a board that appears on a page is a link to the thing it names, with one deliberate
exception for people. `user_link()` points an admin at that person's row on the user list,
`/admin/users/#user-3`, and hands anyone else the name as plain text: a href a member cannot follow
is worse than no href at all, and `/account/` is only ever your own, so there is nowhere else for a
name to point. Boards link to `board/?id=`. Two places are deliberately left as plain text: the
picker dialogs, where clicking a row adds it rather than going anywhere, and the avatars on a board
card, because the card is itself a link and an anchor inside an anchor does not work.

An account is retired rather than deleted. `users.active` is a flag, and `/admin/users/` gives an
admin a Deactivate button per row that switches it either way; the account keeps its row, so the
projects,
comments, assignments and board memberships that point at `users(id)` all stay attributed to the
person who did that work. Deleting would either orphan them or cascade the work away, and neither is
what "this person has left" means. Two things follow from it being a flag rather than a check at the
sign-in form. A session the account already holds stops working, because a cookie outlives the moment
an admin clicks and an account switched off on a shared machine has to stop working there and then —
`require_login()` throws the session away rather than redirecting, so that re-activating the account
cannot hand the old session straight back its access. And a failed sign-in for a deactivated account
is refused exactly as a wrong password is, word for word, so the sign-in form cannot be used to find
out which addresses have accounts or which of them are switched off. Deactivation is refused for the
last admin who can still sign in, for the same reason `roles.php` refuses to demote the last admin:
nobody would be left who could invite or promote anybody, and recovery needs `SLATE_SETUP_KEY`. It is
allowed for an admin switching *themselves* off once somebody else can sign in, because that is how
the last admin hands over: promote a colleague, then switch yourself off.

`/account/` is your own account, reached from the Account menu in the navbar. It is split into two
tabs: **Profile** for your name and the roles the account holds, and **Sign-in** for how you get in —
the address you sign in with, the password form, and the Slack connection. Both panes are in the
document and `public/assets/style.css` shows one of them, using `:target` for the pane a link points
at and `:has()` to decide what "no pane named" means. A tab is therefore an ordinary fragment link,
switching costs no request, and every tab works with JavaScript switched off. They are not
Bootstrap's tab component because the stylesheet half of that hides the other panes on its own, so
without its script half the second tab would be unreachable. The roles are shown and not editable:
they are what an admin grants at `/admin/users/`, and a form on your own page that could hand you a
role would be a way around every check that role is subject to.

Both form endpoints redirect back with a fragment — `#tab-profile`, `#tab-signin` — which the browser
applies after it follows the redirect. That is why a password change lands on Sign-in without the
server being told which pane to draw, and it is why the tab state is not a second thing to keep
consistent with the URL. Switching panes this way does not move focus or announce itself, and the
markup claims no `role="tablist"` or `aria-selected`, because those promise keyboard arrow-key movement
that only JavaScript could provide here.

You can change the two things that are yours to change: your name, and your password. The password form
asks for the current one, because without it anyone who reached an already-signed-in browser could take
the account over permanently. The address is shown read-only, and that is the honest version rather than
a missing feature: it is the identity the account signs in with, and changing it needs a confirmation
sent to the new address before it takes effect, which means an outbox this app does not have. The Slack
connection can be taken back off the account the same way it was put on, with one guard: an account
with no password of its own would be left with no way to sign in at all, so unlinking is refused until
a password is set. None of the endpoints takes a user id, so they cannot be pointed at somebody else's
account by a hand-rolled POST.

`/admin/` is the admin area, linked from the navbar, which offers it only to an admin; anyone else
is refused at every path under it with a 403, rather than sent to a page pretending there is nothing
there. It holds two things, and the hub page is nothing but a link to each. `/admin/users/` is the
user list — invites, roles, deactivation — which used to sit at `/users/` and was reachable by any
signed-in user; it is admin-only now, which is also why `user_link()` only ever draws a link an admin
can follow. `/admin/statuses/` administers the statuses themselves, and is where the rest of this
section starts.

Every status a project can hold is a row in `project_statuses`, created, renamed, reordered, marked
finished or deleted at `/admin/statuses/`. They used to be two constants in `src/lib.php` — `LABELS`
for what they are called and `PANEL_ORDER` for the order — which meant a status this app did not
ship with could not exist at all; an install that wanted "Waiting on QA" had to edit the source and
redeploy. Each row carries the slug, the label, the Bootstrap tone it is drawn in, whether it counts
as finished, and its place in the order. The slug is what `projects.status` and `status_log` store,
so it is fixed once the status is made: renaming changes what a status is called everywhere it is
offered, and never what its existing projects say they are on. `status_label()` falls back to the
slug when the row is missing, which is the shape history is in after a delete — the log keeps its
entries, since a record of what things were called at the time is not a record of what they are
called now.

A status can only be deleted while nothing is on it, and the refusal says how many projects are and
leaves the status where it was. That is the whole policy — no `active` flag, no retired marker, no
way to park a status that is still in use — because a project sitting on a deleted status is one no
page knows how to draw, and a soft delete would leave exactly that state reachable by the next admin
to click. The status's `board_panels` rows go with it, since a panel for a status that no longer
exists is a column that can never be filled.

Which statuses count as finished is the admin's call as well. `is_finished` is what the dashboard
subtracts from its Open tile, what the list view leaves out of its default filter, and what the
overdue styling gives up on — a finished project is not late, whatever its due date says. `done` and
`cancelled` ship marked finished; that is the seeded default rather than a constant, and an install
that finishes at `shipped` marks that one and leaves the rest.

A board's columns are those statuses as panels, and the board says how it wants that one treated:
**Always** draws the column even when it is empty, so there is always somewhere to drop a card;
**Only when it has projects** draws it only while something is in it, which is the useful setting for
a status a board uses occasionally — and which also means the column is not on the page at all while
it is empty, so it cannot be dropped into; **Never** leaves it off the board. A board with nobody's
opinion on record gets To do, In progress and Done, and an admin sets the rest from the Panels dialog
on `/boards/` — including a status just created, which arrives as **Never** until a board asks for it,
so adding a status changes no board by itself. That the list is every status rather than a fixed set
of columns is the point: a project moved to Blocked used to disappear from its board entirely,
because nothing matched its status. Hiding a panel hides nothing else: the projects stay on the board
with their status untouched and are still in the list view, so **Never** is a display choice and not a
way to lose work. Because the default is the absence of a row, only the panels that differ from it are
stored in `board_panels`; storing one row per status per board would mean nearly all of them
repeating the default, and every one of them a place for a row to disagree with it.

The *order* of the columns is the order on `/admin/statuses/`, held as `sort_order` and moved with
the up and down arrows there — one list everywhere, the board, the Panels dialog, and the project's
status dropdown, so a status reads the same wherever it is offered. Migration 008 seeds the nine the
app used to hard-code in the order they were in, which is what keeps `review_ready` before `review`
even though it was the last of them added: appended to `LABELS`, it landed below Cancelled and the
dropdown put "Ready for review" under "Cancelled", splitting the two review stages apart. No board
can rearrange its columns, on purpose: the order encodes how work flows, and letting each board
change it would make "Done" mean something different on two boards. What an admin chooses is which
panels exist, how each behaves, and where a status sits in the flow — not what each board calls
normal.

`/boards/` is a table — Board, Projects, and for an admin Actions — because a board has three facts
about it and a row of them is a row of columns. The panels a board has are deliberately not summarised
anywhere on it: the Panels dialog is where that question is answered, and a column of badges beside
every row was a second, worse answer to the same question. That dialog lists every status beside its own
answer — a name, how many projects are in it, and a select. The select rather than three buttons because
a segmented control does not fit the dialog's width, and the count beside the name already says what
choosing Never would cost, a cost that also rides on the Never option itself so it is there at the
moment it is picked. That button carries two addresses, an `href` to `boards/panels.php` and an `hx-get`
to `boards/panels-dialog.php` — see below. Panels are saved by their own endpoint rather than alongside a
board's name, so renaming a board cannot quietly reset them. The setting is per board on purpose: review
means something different on a board that is a review queue than on one that is not.

`/boards/` displays and does not administer. It used to carry a rename form, a delete button and a
new-board form per board, which made a page of boards a page of forms; all of that is now one dialog,
reached from an Edit button per board and from New board in the heading. The page itself is a table of
links: name, description, project count. Both dialogs are one shell each for the whole page rather than
one per board. `edit.php` serves the create and edit forms from one script, distinguished only by
whether an id is in the query, and posts to the `save_board.php` and `delete_board.php` that the inline
forms used — there is no second implementation of saving a board to keep in step with the first. Delete
sits on the same row as Save by pointing at a form of its own through the button's `form` attribute,
since a form nested inside another is not valid HTML and a second form after it would put a destructive
button on a row of its own. Dropping the badges
also dropped the one query the page was making to count projects per status, which nothing on it needs.

Every dialog in this app is Bootstrap's modal example and nothing else: a `.modal`, a `.modal-dialog`, a
`.modal-content`, and inside that a `.modal-header` with the title and close button, a `.modal-body`, and
a `.modal-footer` holding the buttons. No `.modal-dialog-centered`, no `py-2` on a header, no `fs-5` on a
title, and no row of buttons hand-placed in a `d-flex` div where the footer is supposed to be. There is
no modal CSS in this project at all; if a dialog does not look right, the class to reach for is
Bootstrap's.

A `.modal-footer` is a *sibling* of the `.modal-body`, not a part of it, which is why the dialogs on
`/boards/` arrive whole rather than as a body alone: htmx swaps the outer HTML of a div inside the
`.modal-dialog` for the whole `.modal-content`, header and footer included. Only the content inside the
`.modal-dialog` is replaced — the dialog element itself stays, because Bootstrap holds a reference to it
for the backdrop and the focus trap, and replacing it would leave both pointing at a node no longer in the
document. The shell therefore holds no header of its own, which is the point: the server that draws the
dialog also writes its title, because it knows which board was asked for, where the button that opened it
only knew what to call the last thing it opened. That removed the `show.bs.modal` handler in `app.js` and
the `data-dialog-title` and `data-board-name` attributes it read.

The form is the body's and the footer sits beside it, so a footer button cannot be inside the form and
says which form to submit through its `form` attribute instead — the same attribute Delete uses for its
form of its own. The three picker dialogs (assignee, board, and the draft pickers on the project form)
have no action buttons to move: their content *is* a list of choices, and a list of choices belongs in
the body, so they are just the example's markup with nothing added to it.

Every control on `/boards/`, `/board/` and both dialogs is full size. The `-sm` variants were shipped to
match the rest of the app and were asked for twice before that was noticed.

A board page carries no way to rename its own board. It used to carry an admin's Edit link, which was a
second way into the same dialog as `/boards/` and reached by leaving the board you were looking at; it is
gone, and boards are administered at `/boards/`, where the one dialog lives. The page is about the work
on a board. The one thing it still links to `/boards/` for is a board whose panels are all hidden, where
it points at that board's row so the admin can set some.

That leaves the board form reached from one place rather than two, and it does not change the bargain
this README already states: setting a board or a panel does not depend on a dialog being openable. That
bargain is kept by having the form be a page as well as a fragment, and it is what the four scripts on
`/boards/` are:

| | fragment, for htmx | page, for the `href` |
|---|---|---|
| board form | `boards/edit-dialog.php` | `boards/edit.php` |
| panel settings | `boards/panels-dialog.php` | `boards/panels.php` |

Every button on `/boards/` carries both addresses at once — an `href` naming the page and an `hx-get`
naming the fragment. htmx follows the `hx-get` and swaps; a browser without it follows the `href` and
gets a page that works on its own. Keeping the fallback meant the endpoints could not simply be
fragment-only, which would have left a bare `.modal-content` with no `<html>` around it, served with a
200, at the end of a link that looks like it works.

These are separate scripts rather than one branching on the `HX-Request` header, because each of them is
then one thing: the fragment is Bootstrap's example with no conditionals threaded through it, and the page
is a page. The branch had to be decided while each was halfway through drawing, which is what put
`class="<?= $fragment ? 'modal-body' : '' ?>"` and a footer that was sometimes a `.modal-footer` and
sometimes a hand-placed row into the middle of both.

The suffix is `-dialog` rather than `-no-js` on purpose. A file named after the client that reaches it
says who it is for; one named after what it returns says what it is, and stays true when a third
representation turns up. So `-dialog.php` answers with a fragment and nothing else, and the unsuffixed
name stays the page — which is also the stable, bookmarkable URL, and what a bare `curl` should sensibly
get.

Both pairs draw their fields from `board_form_fields()` and `board_panel_rows()` in `views.php`, because
two copies of a form is two places for a field name, a `maxlength` or the cost of Never to drift apart,
and the split would otherwise have introduced exactly the duplication the rest of this page avoids.
The fragment and the page must agree, and all four must refuse a member — the
fragments are separate ways in, so guarding only the pages would have let one read a board's settings.

A board's description is a textarea, where it is a single line of text in an input. That puts the value
where an attribute value never was — inside the element, as its text — so it is escaped as text by `h()`,
which uses `ENT_QUOTES` and therefore escapes the quotes too. Escaping an attribute would not have been
enough here: a `<` that reached the markup would close the tag. The tags are on one line for the same
reason, since a newline straight after `<textarea>` becomes the first character of the value. A project's
description is a textarea for the same reason and has been all along.

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
shareable list. A project is found by its title, description, comments or assignee names.
Two details are deliberate: the input sits outside
the region htmx replaces, because replacing the box a person is typing into takes the caret with it and
would leave the field accepting exactly one letter; and `%` and `_` are escaped, so a query of `%`
looks for a percent sign rather than returning every project in the install. SQLite folds ASCII case
but not the rest, so a title with an accented letter matches only on its exact case.

A failure partway through a page used to be almost invisible. `src/errors.php` could not send an error
page once output had started, so it logged and returned, and the browser was left holding half a
document with no message in it — a truncated page that reads as a layout bug rather than a crash. That
is the right trade for paths and SQL, and it is what the rendering checks in the suite were written
against: they walk the signed-in pages and fail unless each one ends in `</html>` and leaks no PHP
diagnostic, and `test_auth.py` does the same for the sign-in page. A project page once stopped dead
at the top of its right column with the whole suite green, because every assertion in it greps for
content the page had already managed to emit; those checks exist because of it.

Buffering, described above, is what removed the other half of the problem. A crash is now a 500 with a
complete page rather than a 200 with half of one, and `data/error.log` records what happened.
`SLATE_DEBUG=1` puts the detail on the page for development, and `php bin/check.php` says whether the
PHP itself is set up to run the app.

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

- **No email.** Invitations are a link an admin copies and sends by hand; there is no mail transport,
  no password reset, and no notification of any kind.
- **Changing a password does not sign other devices out.** It changes the hash and gives the browser
  you changed it in a new session id, but sessions are held in PHP's own session files with no
  server-side index, so there is no way to find and drop the others. Revoking them needs a sessions
  table keyed by account.
- **No pagination.** The list view and the boards render every row. Fine for tens of projects,
  not for thousands. The header search caps its menu at eight and sends the rest to `search.php`,
  which still lists every match.
- **Search is substring only.** Titles, descriptions, comments and assignee names are all
  matched, but not fuzzily: `bnch` finds nothing.
- **No CSRF tokens**, by the design described above. The header check covers current browsers; a
  token in the form is the belt-and-braces option if you need one.
- **No LICENCE, and no privacy policy page.** The footer names the repository and its issues page,
  the only contact points, since the app has no mail transport and no contact address, and says
  nothing else. There is no licence file to name; a licence is one file to add. A privacy policy is
  deliberately not published at all: it would be a claim about one deployment rather than about this
  code, and whoever runs the app decides what is logged and where, so any text shipped here could be
  made false by an install that differs from this one.
- **Single-node assumptions.** SQLite in WAL mode on a local disk, one app instance. Do not point two
  servers at one database file, and do not put the database on NFS.
