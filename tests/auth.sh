#!/usr/bin/env bash
# End-to-end auth tests: drives a throwaway copy of the app over HTTP with a cookie jar.
#   tests/auth.sh          uses a temp copy, leaves data/board.db alone
#   tests/auth.sh --keep   same, but prints the temp dir so you can poke at it
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d /tmp/slate-auth.XXXXXX)"
PORT="${SLATE_TEST_PORT:-8125}"
BASE="http://localhost:$PORT"
JAR="$TMP/cookies.txt"
BODY="$TMP/body"
HDR="$TMP/headers"

fails=0
pass() { printf "  ok    %s\n" "$1"; }
fail() { printf "  FAIL  %s\n     expected: %s\n     actual:   %s\n" "$1" "$2" "$3"; fails=$((fails + 1)); }
is()   { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "$2" "$3"; fi; }
has()  { if grep -qF -- "$2" "$BODY"; then pass "$1"; else fail "$1" "body containing \"$2\"" "$(head -c 160 "$BODY" | tr '\n' ' ')"; fi; }
hasnt(){ if grep -qF -- "$2" "$BODY"; then fail "$1" "body without \"$2\"" "$(head -c 160 "$BODY" | tr '\n' ' ')"; else pass "$1"; fi; }
loc() { grep -i '^location:' "$HDR" | tail -1 | tr -d '\r' | sed 's/^[Ll]ocation: *//'; }

# Did the last get/post render a whole document? A PHP fatal leaves well-formed markup behind and
# every other assertion still passes on whatever was emitted before it gave up, so the closing tags
# are the only real evidence. The sign-in and setup pages are checked here because they are the ones
# nobody ever sees while signed in, and so the ones least likely to be looked at.
finished() { is "$1 finishes" "yes" "$(tail -c 400 "$BODY" | grep -q '</html>' && echo yes || echo no)"; }

get()  { curl -sS -o "$BODY" -D "$HDR" -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE$1"; }
post() { curl -sS -o "$BODY" -D "$HDR" -w '%{http_code}' -c "$JAR" -b "$JAR" \
              -X POST --data "$2" "$BASE$1"; }

# Query the scratch database. The SQL arrives as $1 and must contain no double quotes;
# shell string literals inside it are single-quoted, e.g. sq "select ... where email='a@b.c'".
sq() { php -r '
  $d = new PDO("sqlite:" . $argv[1]);
  $d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $v = $d->query($argv[2])->fetchColumn();
  echo $v === null ? "" : $v;
' "$TMP/data/board.db" "$1"; }

PIDS=()
cleanup() {
  for pid in "${PIDS[@]:-}"; do kill "$pid" 2>/dev/null; done
  wait 2>/dev/null
  if [ "${1:-}" = "--keep" ]; then echo "kept: $TMP"; else rm -rf "$TMP"; fi
}
trap 'cleanup "${KEEP:-}"' EXIT

serve() {   # serve <port> <docroot> [env assignment...]
  local port="$1" docroot="$2"; shift 2
  env "$@" php -S "localhost:$port" -t "$docroot" >"$TMP/server-$port.log" 2>&1 &
  PIDS+=($!)
  for _ in $(seq 1 60); do
    curl -sS -o /dev/null "http://localhost:$port/login/" 2>/dev/null && return 0
    sleep 0.1
  done
  echo "server on $port never came up"; exit 1
}

echo "== migrate =="
mkdir -p "$TMP/data"
cp -r "$ROOT/src" "$ROOT/public" "$ROOT/db" "$ROOT/bin" "$TMP/"
is "fresh migrate applies every file" "6" "$(cd "$TMP" && php bin/migrate.php | grep -cE '^ *\+ ')"
is "re-running applies nothing" "0" "$(cd "$TMP" && php bin/migrate.php | grep -cE '^ *\+ ')"
php "$ROOT/tests/schema.php" "$TMP/data/board.db" >"$TMP/schema.out" 2>&1
is "schema check passes against a fresh database" "0" "$?"

serve "$PORT" "$TMP/public"

echo "== anonymous access is refused =="
is "board redirects on first run" "302" "$(get /index.php)"
is "  ...to setup" "/setup/" "$(loc)"
is "list redirects" "302" "$(get /list/)"
is "new project redirects" "302" "$(get /new/)"
is "users page redirects" "302" "$(get /users/)"
is "search redirects" "302" "$(get /search.php)"
is "an admin endpoint redirects rather than running" "302" "$(get /users/invite.php)"
is "setup page is offered" "200" "$(get /setup/)"
has "  ...asking for the first account" "Create the first account"
hasnt "  ...with no identity badge yet" "Sign out"
has "  ...centred in the window too" "page-center"
hasnt "  ...and no navigation bar either" "navbar"
hasnt "  ...and no footer either" "a small project tracker"

echo "== first-run setup =="
is "a short password is refused" "302" \
  "$(post /setup/accept_setup.php 'name=A&email=a@e.com&password=short&password2=short')"
get '/setup/?error=Passwords%20need%20at%20least%2010%20characters' >/dev/null
has "  ...with a length complaint" "at least 10 characters"
is "a mismatched confirmation is refused" "302" \
  "$(post /setup/accept_setup.php 'name=A&email=a@e.com&password=longenough1&password2=otherlong1')"
get '/setup/?error=Passwords%20do%20not%20match' >/dev/null
has "  ...with a mismatch complaint" "do not match"
is "a malformed address is refused" "302" \
  "$(post /setup/accept_setup.php 'name=A&email=nope&password=longenough1&password2=longenough1')"
is "setup creates the admin" "302" \
  "$(post /setup/accept_setup.php 'name=Ada Admin&email=ada@example.com&password=correcthorse1&password2=correcthorse1')"
is "  ...landing on the board" "/index.php" "$(loc)"
is "the account has both roles" "admin,member" \
  "$(sq "select group_concat(r.slug) from roles r join user_roles ur on ur.role_id=r.id where ur.user_id=1")"
is "the password is hashed, not stored" "1" \
  "$(sq "select case when password_hash like '\$2%' then 1 else 0 end from users where id=1")"

echo "== the board now requires a session =="
is "board loads when signed in" "200" "$(get /index.php)"
has "  ...showing who you are" "Ada Admin (admin)"
hasnt "  ...and no impersonation switcher" "Acting as"
is "setup page turns away a signed-in visitor" "302" "$(get /setup/)"
is "  ...to the board" "/index.php" "$(loc)"

echo "== sign out =="
is "logout redirects" "302" "$(post /logout.php '')"
rm -f "$JAR"
is "board redirects again" "302" "$(get /index.php)"
is "  ...to sign in, remembering where" "/login/?next=%2Findex.php" "$(loc)"
is "the sign-in page renders while signed out" "200" "$(get /login/)"
finished "the sign-in page"
has "  ...with the form" "Sign in"
hasnt "  ...and no identity badge" "Sign out"
has "  ...explaining how to get an account" "Ask an administrator"
has "  ...centred in the window, with no navbar to centre it under" "page-center"
hasnt "  ...and no navigation bar at all" "navbar"
hasnt "  ...nor an account menu" ">Account</a>"

echo "== rejected sign-ins =="
is "a wrong password is refused" "302" \
  "$(post /login/login.php 'email=ada@example.com&password=wrongwrongwrong')"
is "  ...back to the form" "/login/" "$(loc)"
get /login/ >/dev/null
has "  ...with a generic message" "do not match an account"
is "an unknown address is refused" "302" \
  "$(post /login/login.php 'email=nobody@example.com&password=whatever12')"
get /login/ >/dev/null
has "  ...word for word" "do not match an account"
is "failures are recorded" "2" "$(sq 'select count(*) from login_attempts')"

echo "== seeded users cannot sign in =="
is "only the new account has credentials" "1" \
  "$(sq 'select count(*) from users where password_hash is not null')"
is "a fresh install starts with one account" "1" "$(sq "select count(*) from users")"

echo "== throttle =="
for _ in $(seq 1 8); do post /login/login.php 'email=ada@example.com&password=wrongwrongwrong' >/dev/null; done
is "the right password is refused while throttled" "302" \
  "$(post /login/login.php 'email=ada@example.com&password=correcthorse1')"
get /login/ >/dev/null
has "  ...saying so" "Too many attempts"
php -r '$d=new PDO("sqlite:'$TMP'/data/board.db"); $d->exec("delete from login_attempts");'

echo "== sign in =="
is "the right password is accepted" "302" \
  "$(post /login/login.php 'email=ada@example.com&password=correcthorse1')"
is "  ...to the board" "/index.php" "$(loc)"
get /index.php >/dev/null
has "the board greets the admin" "Ada Admin (admin)"
is "failures were cleared" "0" "$(sq 'select count(*) from login_attempts')"

echo "== the boards menu =="
# Only one board exists at this point, which is the case the menu used to hide itself for.
is "there is just the one board" "1" "$(sq 'select count(*) from boards')"
has "the navbar offers a dropdown" 'data-bs-toggle="dropdown"'
has "  ...labelled Boards" "dropdown-toggle"
has "  ...listing every board" "General"
has "  ...and a way to see them all" "All boards"
hasnt "  ...the old jump select is gone" 'id="jump"'
# Without navbar-expand-lg, Bootstrap leaves .navbar-nav .dropdown-menu at position:static
# and the open menu stretches the bar instead of floating over it.
has "  ...in a navbar that lets the menu float" 'navbar-expand-lg'
get /boards/ >/dev/null
hasnt "  ...and nothing is marked active" 'dropdown-item active'
get /board/?id=1 >/dev/null
hasnt "  ...on a board page either" 'dropdown-item active'
has "  ...while every board still links to its page" 'href="/board/?id=1"'

echo "== the navbar collapses and the account menu =="
get /index.php >/dev/null
has "narrow screens get a toggler" "navbar-toggler"
has "  ...pointing at the collapsible region" 'data-bs-target="#navbar"'
has "  ...which it controls" 'aria-controls="navbar"'
has "  ...and that region exists" 'collapse navbar-collapse'
has "the account is a dropdown" ">Account</a>"
has "  ...holding who you are" "Ada Admin (admin)"
has "  ...and signing out from inside it" '<button class="dropdown-item" type="submit">Sign out</button>'

echo "== the only admin cannot be stranded =="
is "demoting the only admin is refused" "400" "$(post /users/roles.php 'user_id=1&roles[]=2')"
has "  ...with an explanation" "only admin"
is "and the role survived" "admin,member" \
  "$(sq "select group_concat(r.slug) from roles r join user_roles ur on ur.role_id=r.id where ur.user_id=1")"

echo "== invites =="
is "an existing address cannot be invited" "400" \
  "$(post /users/invite.php 'email=ada@example.com&role_id=2')"
has "  ...saying why" "already has an account"
is "a malformed address is rejected" "400" "$(post /users/invite.php 'email=not-an-email&role_id=2')"
is "an unknown role is rejected" "400" "$(post /users/invite.php 'email=g@example.com&role_id=999')"
is "an invite is created" "302" "$(post /users/invite.php 'email=grace@example.com&name=Grace&role_id=2')"
invite_url="$(loc)"
token="${invite_url##*invite=}"
is "  ...as a member" "/users/?invite=$token" "$invite_url"
get "/users/?invite=$token" >/dev/null
has "the users page shows the link" "invite/?token=$token"
is "it is listed as pending" "grace@example.com" \
  "$(sq 'select email from invites where accepted_at is null')"

echo "== accepting an invite =="
rm -f "$JAR"
is "the invite page renders while signed out" "200" "$(get "/invite/?token=$token")"
has "  ...naming the role" "Join as Member"
# A signed-out visitor gets no toggler, so the collapsible region is forced open instead of
# hiding the links behind a hamburger that is not there.
hasnt "  ...nor a toggler" "navbar-toggler"
has "  ...while the links stay visible anyway" "collapse navbar-collapse show"
is "an unknown token is refused" "200" "$(get '/invite/?token=deadbeefdeadbeef')"
has "  ...as invalid" "Invitation not valid"
is "a short password is refused" "302" \
  "$(post /invite/accept_invite.php "token=$token&name=Grace&password=short&password2=short")"
short_url="$(loc)"
get "/${short_url#../}" >/dev/null
has "  ...with the length complaint" "at least 10 characters"
is "an invite is accepted" "302" \
  "$(post /invite/accept_invite.php "token=$token&name=Grace Hopper&password=hopperpassword1&password2=hopperpassword1")"
is "  ...signing the new user in" "/index.php" "$(loc)"
grace_id="$(sq "select id from users where email='grace@example.com'")"
is "the new user is a member" "member" \
  "$(sq "select group_concat(r.slug) from roles r join user_roles ur on ur.role_id=r.id where ur.user_id=$grace_id")"
is "the invite is marked used" "1" \
  "$(sq "select count(*) from invites where token='$token' and accepted_at is not null")"
is "the link cannot be reused" "200" "$(get "/invite/?token=$token")"
has "  ...it says so" "Invitation not valid"

echo "== what a member may and may not do =="
get /users/ >/dev/null
has "a member sees the user list" "Ada Admin"
hasnt "  ...but not the invite form" "Invite someone"
hasnt "  ...nor the pending invites" "Pending invitations"
is "a member cannot mint an invite" "403" "$(post /users/invite.php 'email=z@example.com&role_id=2')"
is "a member cannot change roles" "403" "$(post /users/roles.php 'user_id=1&roles[]=1')"

echo "== boards: admins manage them, members file work =="
post /new/add.php 'title=Boardable project&description=&status=todo&type=task' >/dev/null
boardable="$(sq "select id from projects where title='Boardable project'")"
get /boards/ >/dev/null
has "a member sees the boards" "General"
hasnt "  ...but not a New board button" "New board"
hasnt "  ...nor the panel settings" "panels[todo]"
hasnt "  ...nor a way into any dialog" "data-bs-toggle=\"modal\""
hasnt "  ...and neither dialog is even on the page" "panels-modal"
is "  ...and the panels dialog is not there to open" "403" "$(get '/boards/panels.php?id=1')"
is "  ...nor the edit one" "403" "$(get '/boards/edit.php?id=1')"
is "  ...nor the create one" "403" "$(get '/boards/edit.php')"
# The same forms again as the fragments the buttons fetch, which are separate scripts and so separate
# ways in. Guarding only the pages would leave a member able to read a whole board's panel settings.
is "  ...nor the panels fragment" "403" "$(get '/boards/panels-dialog.php?id=1')"
is "  ...nor the edit fragment" "403" "$(get '/boards/edit-dialog.php?id=1')"
is "  ...nor the create fragment" "403" "$(get '/boards/edit-dialog.php')"
is "a member cannot create a board" "403" "$(post /boards/save_board.php 'name=Sneaky&description=')"
is "  ...and none was created" "0" "$(sq "select count(*) from boards where name='Sneaky'")"
is "a member cannot delete a board" "403" "$(post /boards/delete_board.php 'id=1')"
is "  ...and it is still there" "1" "$(sq "select count(*) from boards where name='General'")"
is "  ...with its panels untouched" "0" "$(sq "select count(*) from board_panels")"
is "a member cannot set a board's panels" "403" \
  "$(post /boards/save_panels.php 'id=1&panels[todo]=hidden')"
is "but a member may file work on a board" "302" \
  "$(post /project/project_boards.php "id=$boardable&boards[]=1")"
is "  ...which is recorded" "1" \
  "$(sq "select count(*) from project_boards where project_id=$boardable and board_id=1")"

echo "== deleting is admin-only =="
# Give the member something real to try to delete, so this is not just a 404.
post /new/add.php 'title=Doomed project&description=&status=todo&type=task' >/dev/null
project_id="$(sq "select id from projects where title='Doomed project'")"
is "the member saved a project" "1" "$(sq "select count(*) from projects where id=$project_id")"
is "a member cannot delete it" "403" "$(post /project/delete.php "id=$project_id")"
is "  ...and it is still there" "1" \
  "$(sq "select count(*) from projects where id=$project_id")"
get "/project/?id=$project_id" >/dev/null
hasnt "  ...with no delete button offered" "Delete project"
post /logout.php '' >/dev/null
rm -f "$JAR"
post /login/login.php 'email=ada@example.com&password=correcthorse1' >/dev/null
get "/project/?id=$project_id" >/dev/null
has "an admin is offered the delete button" "Delete project"
is "an admin can delete it" "302" "$(post /project/delete.php "id=$project_id")"
is "  ...and it is gone" "0" "$(sq "select count(*) from projects where id=$project_id")"

echo "== an admin's board lifecycle =="
post /new/add.php 'title=Survivor&description=&status=todo&type=task&boards[]=1' >/dev/null
survivor="$(sq "select id from projects where title='Survivor'")"
is "a project can be filed on a board at creation" "1" \
  "$(sq "select count(*) from project_boards where project_id=$survivor")"
is "an admin can create a board" "302" "$(post /boards/save_board.php 'name=Field Work&description=Out of office')"
new_board="$(sq "select id from boards where name='Field Work'")"
is "  ...with its description" "Out of office" "$(sq "select description from boards where id=$new_board")"
is "and rename it" "302" "$(post /boards/save_board.php "id=$new_board&name=Field&description=")"
is "  ...under the new name" "Field" "$(sq "select name from boards where id=$new_board")"
is "an admin can set its panels" "302" \
  "$(post /boards/save_panels.php "id=$new_board&panels[todo]=required&panels[in_progress]=optional&panels[blocked]=required")"
# To do is required by default and blocked is not a column by default, so the row that gets written
# for the first is none at all and the one for the third is the interesting case: asking for a
# status that had no column is now a thing the form can express.
is "  ...keeping only the panels that differ" "2" \
  "$(sq "select count(*) from board_panels where board_id=$new_board")"
is "  ...and reading back as asked" "optional" \
  "$(sq "select mode from board_panels where board_id=$new_board and status='in_progress'")"
# Blocked was not a column at all before this, and asking for it writes a row where the default
# would have stored nothing.
is "  ...including a status with no column by default" "required" \
  "$(sq "select mode from board_panels where board_id=$new_board and status='blocked'")"
is "an empty board name is refused" "302" \
  "$(post /boards/save_board.php "id=$new_board&name=&description=")"
board_url="$(loc)"
get "/${board_url#../}" >/dev/null
has "  ...with the complaint" "board needs a name"
is "  ...and leaves the name alone" "Field" "$(sq "select name from boards where id=$new_board")"
post /project/project_boards.php "id=$survivor&boards[]=$new_board" >/dev/null
is "an admin can delete that board" "302" "$(post /boards/delete_board.php "id=$new_board")"
is "  ...and it is gone" "0" "$(sq "select count(*) from boards where id=$new_board")"
is "  ...the membership with it" "0" "$(sq "select count(*) from project_boards where board_id=$new_board")"
is "  ...but the project survives, on no board" "1" \
  "$(sq "select count(*) from projects where id=$survivor")"
get "/project/?id=$survivor" >/dev/null
has "  ...and says so on its page" "no board"
is "and it is still listed" "200" "$(get '/list/?board=0')"
rm -f "$JAR"
post /login/login.php 'email=grace@example.com&password=hopperpassword1' >/dev/null
rm -f "$JAR"
is "and the board is closed again" "302" "$(get /index.php)"

echo "== promotion =="
post /login/login.php 'email=ada@example.com&password=correcthorse1' >/dev/null
is "an admin promotes the member" "302" "$(post /users/roles.php "user_id=$grace_id&roles[]=1&roles[]=2")"
is "  ...giving both roles" "admin,member" \
  "$(sq "select group_concat(r.slug) from roles r join user_roles ur on ur.role_id=r.id where ur.user_id=$grace_id")"
is "a bogus role is ignored" "302" "$(post /users/roles.php "user_id=$grace_id&roles[]=999")"
is "  ...leaving no roles" "" \
  "$(sq "select group_concat(r.slug) from roles r join user_roles ur on ur.role_id=r.id where ur.user_id=$grace_id")"
post /users/roles.php "user_id=$grace_id&roles[]=2" >/dev/null
is "both roles can be restored" "302" "$(post /users/roles.php "user_id=$grace_id&roles[]=1&roles[]=2")"

echo "== the promoted member sees the admin tools =="
rm -f "$JAR"
post /login/login.php 'email=grace@example.com&password=hopperpassword1' >/dev/null
is "sign-in as the promoted user works" "/index.php" "$(loc)"
get /users/ >/dev/null
has "  ...the invite form" "Invite someone"
has "  ...and their new badge" "Grace Hopper (admin)"

echo "== revoking =="
post /users/invite.php 'email=alan@example.com&role_id=2' >/dev/null
token2="$(loc)"; token2="${token2##*invite=}"
is "revoking redirects" "302" "$(post /users/revoke_invite.php "token=$token2")"
is "the revoked link is dead" "200" "$(get "/invite/?token=$token2")"
has "  ...and says so" "Invitation not valid"
is "it is no longer listed as pending" "0" \
  "$(sq "select count(*) from invites where token='$token2' and accepted_at is null")"

echo "== the account page =="
# get_as/post_as take a jar of their own, for the sections below that need two identities signed in
# at the same time. A copy of the current jar will not do: signing in regenerates the session id, so
# the copy and the original would be fighting over one session and the loser would be signed out.
get_as()  { curl -sS -o "$BODY" -D "$HDR" -w '%{http_code}' -c "$1" -b "$1" "$BASE$2"; }
post_as() { curl -sS -o "$BODY" -D "$HDR" -w '%{http_code}' -c "$1" -b "$1" \
                -X POST --data "$3" "$BASE$2"; }
# The account endpoints answer with a redirect carrying the message, so the page it lands on is what
# gets read -- asserting on the query string would only be asserting this file back at itself.
follow() { get "$(loc)" >/dev/null; }
grace_jar="$TMP/grace-cookies.txt"

get /account/ >/dev/null
finished "the account page finishes"
has "it says whose account it is" "Your account"
has "  ...with the name" "Grace Hopper"
has "  ...a form for the name" 'name="name"'
has "  ...and the address it signs in with, read-only" 'readonly'

echo "== the account tabs =="
# The panes are switched with :target and :has() in public/assets/style.css, so every pane is in the
# one response and a tab is a fragment link. These checks can only see the markup and the stylesheet,
# not what a browser computes from them -- tests/browser-tabs.sh drives headless Chromium for that --
# so what is asserted here is that the hooks the CSS needs are present and the ones it must not rely
# on are absent.
get /account/ >/dev/null
for t in profile password access; do
  has "the $t pane is in the document" "id=\"tab-$t\""
    has "  ...behind a bare fragment link" "href=\"#tab-$t\""
done
has "  ...marked up as navigation" 'aria-label="Account sections"'
hasnt "  ...with no tab state left to the server, which cannot see a fragment" '?tab='
hasnt "  ...and no claim to be a tablist it cannot drive from the keyboard" 'role="tablist"'
hasnt "  ...nor Bootstrap's script tab component, which only the navbar's dropdowns use" 'data-bs-toggle="tab"'
# What the stylesheet then makes of that markup is checked for real, by a browser, in
# tests/browser-tabs.sh. Asserting the CSS text here would only prove it is spelled as expected.

has "the password pane holds the password form" 'name="current"'
has "the access pane says what the account may do" "What this account may do"
has "  ...the roles it holds" ">Roles<"
has "  ...and the status it is in" ">Status<"
# A pane id that collides with a field id makes :target ambiguous and the HTML invalid, which is how
# the password pane first shipped: id="password" on the pane and on the new-password input.
is "no id appears twice in the account page" "" "$(php -r '
  preg_match_all("/id=\"([^\"]+)\"/", file_get_contents($argv[1]), $m);
  echo implode(",", array_diff_assoc($m[1], array_unique($m[1])));
' "$BODY")"

# Each form redirects back to the tab it sits on, so a save lands where the reader was. Both of these
# are the refused paths, which change nothing, so the checks after them still see the old values.
post /account/change_password.php 'current=nopenopenope&password=hopperpassword2&password2=hopperpassword2' >/dev/null
is "a refused password change stays on the password tab" \
  "/account/?error=That%20is%20not%20your%20current%20password#tab-password" "$(loc)"
post /account/update_name.php 'name=' >/dev/null
is "a refused name change stays on the profile tab" \
  "/account/?error=A%20name%20is%20needed#tab-profile" "$(loc)"
follow
has "  ...landing on the tab with a reason" "A name is needed"
is "  ...without changing the row" "Grace Hopper" "$(sq "select name from users where id=$grace_id")"

post /account/update_name.php 'name=Grace H. Hopper' >/dev/null
is "a name save returns to the profile tab" "/account/?saved=Name%20saved#tab-profile" "$(loc)"
follow
has "  ...saying so" "Name saved"
has "  ...showing the new name" "Grace H. Hopper"
hasnt "  ...and not the old one" ">Grace Hopper<"
has "  ...including in the navbar" "Grace H. Hopper (admin)"

post /account/change_password.php 'current=nopenopenope&password=hopperpassword2&password2=hopperpassword2' >/dev/null
follow
has "a wrong current password is refused" "not your current password"
post /account/change_password.php 'current=hopperpassword1&password=hopperpassword2&password2=hopperpassword3' >/dev/null
follow
has "two new passwords that differ are refused" "do not match"
post /account/change_password.php 'current=hopperpassword1&password=short&password2=short' >/dev/null
follow
has "a too-short password is refused" "at least 10 characters"
is "  ...leaving the old password in place" "1" \
  "$(sq "select count(*) from users where id=$grace_id and password_hash is not null")"

post /account/change_password.php 'current=hopperpassword1&password=hopperpassword2&password2=hopperpassword2' >/dev/null
is "a password change returns to the password tab" \
  "/account/?saved=Password%20changed#tab-password" "$(loc)"
follow
has "  ...saying so" "Password changed"
has "  ...and the session it was made from still works" "Grace H. Hopper"

echo "== who is offered the other accounts =="
# Only checked for two different people, because the navbar offers /users/ to anybody signed in and
# the button on the Access tab is the thing being tested. Grace gets a jar of her own, since $JAR is
# hers and signing in again on a copy would strand it. Ada makes the role change, so the check does
# not depend on what Grace happened to be promoted to earlier in this file.
post_as "$grace_jar" /login/login.php 'email=grace@example.com&password=hopperpassword2' >/dev/null
rm -f "$JAR"
post /login/login.php 'email=ada@example.com&password=correcthorse1' >/dev/null
post /users/roles.php "user_id=$grace_id&roles[]=2" >/dev/null
get_as "$grace_jar" '/account/#tab-access' >/dev/null
hasnt "a member is not offered the other accounts" ">Manage users</a>"
post /users/roles.php "user_id=$grace_id&roles[]=1&roles[]=2" >/dev/null
get_as "$grace_jar" '/account/#tab-access' >/dev/null
has "  ...but an admin is" ">Manage users</a>"
post /users/roles.php "user_id=$grace_id&roles[]=2" >/dev/null
is "the section leaves one admin, as the next one needs" "1" \
  "$(sq "select count(*) from user_roles ur join roles r on r.id=ur.role_id where r.slug='admin'")"

echo "== switching an account off and on =="
rm -f "$JAR"
post /login/login.php 'email=ada@example.com&password=correcthorse1' >/dev/null
is "the endpoint is POST only" "405" "$(get /users/set_active.php)"
is "an unknown user is refused" "404" "$(post /users/set_active.php 'user_id=99999&active=0')"
is "a missing state is refused" "400" "$(post /users/set_active.php "user_id=$grace_id")"
has "  ...rather than guessed at" "Say whether the account should be active"

# Grace signs in on a jar of her own, and is dropped to a plain member so the account being switched
# off has no admin powers of its own to muddy what is being checked.
post_as "$grace_jar" /login/login.php 'email=grace@example.com&password=hopperpassword2' >/dev/null
is "a deactivated account is not what is signed in here" "/index.php" "$(loc)"
post /users/roles.php "user_id=$grace_id&roles[]=2" >/dev/null

is "a member cannot switch an account off" "403" \
  "$(post_as "$grace_jar" /users/set_active.php 'user_id=1&active=0')"
is "  ...and the row is untouched" "1" "$(sq "select active from users where id=1")"

is "the only admin cannot switch themselves off" "400" "$(post /users/set_active.php 'user_id=1&active=0')"
has "  ...saying why" "only admin"
is "  ...and they are still active" "1" "$(sq "select active from users where id=1")"

is "switching an account off redirects" "302" "$(post /users/set_active.php "user_id=$grace_id&active=0")"
is "  ...back to the list" "/users/" "$(loc)"
is "  ...and the row says so" "0" "$(sq "select active from users where id=$grace_id")"
is "  ...leaving the account and its name alone" "Grace H. Hopper" \
  "$(sq "select name from users where id=$grace_id")"
get /users/ >/dev/null
has "  ...and the list calls it Deactivated" "Deactivated"
has "  ...offering to switch it back on" "Reactivate"
# users_list() sorts on active first, so a switched-off account is at the top of the list rather than
# buried among the active ones -- it is the row an admin is most likely to be looking for.
grace_at=$(grep -n "id=\"user-$grace_id\"" "$BODY" | cut -d: -f1)
ada_at=$(grep -n 'id="user-1"' "$BODY" | cut -d: -f1)
is "  ...and it is listed above the active ones" "yes" \
  "$([ "$grace_at" -lt "$ada_at" ] && echo yes || echo no)"

# The point of the whole thing: a session that account was already holding stops counting, instead of
# staying good until it happens to expire on its own.
is "the session it already had stops working" "302" "$(get_as "$grace_jar" /)"
is "  ...sent back to sign in" "/login/?next=%2F" "$(loc)"
is "  ...and stays stopped" "302" "$(get_as "$grace_jar" /)"

post_as "$grace_jar" /login/login.php 'email=grace@example.com&password=hopperpassword2' >/dev/null
is "a switched-off account cannot sign in, back to the form" "/login/" "$(loc)"
get_as "$grace_jar" /login/ >/dev/null
has "  ...saying only that the details did not match" "do not match an account"
hasnt "  ...without revealing that it exists but is off" "eactivat"

post /users/set_active.php "user_id=$grace_id&active=1" >/dev/null
is "switching it back on redirects to the list" "/users/" "$(loc)"
is "  ...and the row says so" "1" "$(sq "select active from users where id=$grace_id")"
post_as "$grace_jar" /login/login.php 'email=grace@example.com&password=hopperpassword2' >/dev/null
is "  ...and it signs in again" "/index.php" "$(loc)"

echo "== the last admin handing over =="
# An admin who is leaving promotes a colleague first and then switches their own account off, which
# is only possible because self-deactivation is allowed once somebody else can still sign in.
post /users/roles.php "user_id=$grace_id&roles[]=1&roles[]=2" >/dev/null
is "an admin switches themselves off" "302" "$(post /users/set_active.php 'user_id=1&active=0')"
is "  ...which takes effect on the very next request" "302" "$(get /)"
is "  ...sending them back to sign in" "/login/?next=%2F" "$(loc)"
is "  ...and it stays that way" "302" "$(get /)"
post_as "$grace_jar" /login/login.php 'email=grace@example.com&password=hopperpassword2' >/dev/null
is "the colleague they promoted signs in" "/index.php" "$(loc)"
is "  ...and can switch the old account back on" "302" "$(post_as "$grace_jar" /users/set_active.php 'user_id=1&active=1')"
post_as "$grace_jar" /login/login.php 'email=ada@example.com&password=correcthorse1' >/dev/null
is "  ...and the old admin signs in again" "/index.php" "$(loc)"

# Hand the main jar back as an admin, which is who the cross-site section below expects to find.
rm -f "$JAR"
post /login/login.php 'email=ada@example.com&password=correcthorse1' >/dev/null
is "the section hands back an admin session" "/index.php" "$(loc)"

echo "== cross-site requests =="
# require_same_origin() reads the headers a browser cannot be forged into sending, so these drive
# it directly rather than trying to stage a real attack. The admin session is signed in here.
post_h() { local path="$1" data="$2"; shift 2
           curl -sS -o "$BODY" -D "$HDR" -w '%{http_code}' -c "$JAR" -b "$JAR" \
             -X POST --data "$data" "$@" "$BASE$path"; }
invited_before="$(sq 'select count(*) from invites')"
is "a cross-site request is refused" "403" \
  "$(post_h /users/invite.php 'email=csrf1@example.com&role_id=2' -H 'Sec-Fetch-Site: cross-site')"
has "  ...and says so" "Cross-site request refused"
is "a sibling subdomain is refused as well" "403" \
  "$(post_h /users/invite.php 'email=csrf2@example.com&role_id=2' -H 'Sec-Fetch-Site: same-site')"
is "a forged Origin is refused" "403" \
  "$(post_h /users/invite.php 'email=csrf3@example.com&role_id=2' -H 'Origin: https://evil.example')"
is "  ...and so is our own host as the front of another" "403" \
  "$(post_h /users/invite.php 'email=csrf4@example.com&role_id=2' -H "Origin: $BASE.evil.example")"
is "  ...and not one of them invited anybody" "$invited_before" "$(sq 'select count(*) from invites')"
is "our own Origin is allowed" "302" \
  "$(post_h /users/invite.php 'email=csrf5@example.com&role_id=2' -H "Origin: $BASE")"
is "  ...and the invitation exists" "csrf5@example.com" \
  "$(sq "select email from invites where email='csrf5@example.com'")"
is "Sec-Fetch-Site same-origin is allowed" "302" \
  "$(post_h /users/invite.php 'email=csrf6@example.com&role_id=2' -H 'Sec-Fetch-Site: same-origin')"
is "a matching Referer stands in for Origin" "302" \
  "$(post_h /users/invite.php 'email=csrf7@example.com&role_id=2' -H "Referer: $BASE/users/")"
is "a request with no such headers at all is allowed" "302" \
  "$(post /users/invite.php 'email=csrf8@example.com&role_id=2')"
is "a reading request is never refused" "200" \
  "$(curl -sS -o "$BODY" -D "$HDR" -w '%{http_code}' -c "$JAR" -b "$JAR" \
     -H 'Sec-Fetch-Site: cross-site' "$BASE/list/")"

echo "== SLATE_SETUP_KEY =="
is "setup stays closed with no key set" "302" "$(get /setup/)"
serve "$((PORT + 1))" "$TMP/public" SLATE_SETUP_KEY=secret-key
rm -f "$JAR"
is "setup opens when a key is set" "200" \
  "$(curl -sS -o "$BODY" -D "$HDR" -w '%{http_code}' "http://localhost:$((PORT + 1))/setup/")"
has "  ...and asks for the key" "Setup key"
key_post() { curl -sS -o "$BODY" -D "$HDR" -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST --data "$1" \
                        "http://localhost:$((PORT + 1))$2"; }
is "a wrong key is refused" "302" \
  "$(key_post 'setup_key=nope&name=S&email=s@example.com&password=longenough1&password2=longenough1' /setup/accept_setup.php)"
is "the right key creates an admin" "302" \
  "$(key_post 'setup_key=secret-key&name=Second Admin&email=second@example.com&password=longenough1&password2=longenough1' /setup/accept_setup.php)"
is "  ...with the admin role" "admin,member" \
  "$(sq "select group_concat(r.slug) from roles r join user_roles ur on ur.role_id=r.id
          where ur.user_id=(select id from users where email='second@example.com')")"

echo "== an unmigrated database =="
mkdir -p "$TMP/fresh/data"
cp -r "$ROOT/src" "$ROOT/public" "$TMP/fresh/"
serve "$((PORT + 2))" "$TMP/fresh/public"
is "the app explains itself instead of erroring" "500" \
  "$(curl -sS -o "$BODY" -w '%{http_code}' "http://localhost:$((PORT + 2))/index.php")"
has "  ...naming the fix" "php bin/migrate.php"

echo
if [ "$fails" -eq 0 ]; then echo "all auth checks passed"; else echo "$fails check(s) failed"; fi
exit $((fails > 0))
