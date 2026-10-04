#!/usr/bin/env bash
# Regression tests for the project flows: create, update, assign, comment, move, delete.
# Signs in properly, unlike the older anonymous version of this script.
#   tests/flows.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d /tmp/slate-flows.XXXXXX)"
PORT="${SLATE_TEST_PORT:-8127}"
# A second server on a PHP without mbstring, for the check that needs one. Off by one so it cannot
# collide with the first.
NOPORT="${SLATE_TEST_PORT_NOEXT:-$((PORT+1))}"
# A third server, on a copy of the app with a crash injected, for the checks that need one. Also off
# by one so it cannot collide with the other two.
FPORT="${SLATE_TEST_PORT_FAULT:-$((PORT+2))}"
base="http://localhost:$PORT"
jar="$TMP/jar"
fails=0

check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: expected [$2] got [$3]"; fails=$((fails+1)); fi; }
has()   { if grep -qF "$3" "$2"; then echo "ok   $1"; else echo "FAIL $1: '$3' missing from $2"; fails=$((fails+1)); fi; }
hasnt() { if grep -qF "$3" "$2"; then echo "FAIL $1: '$3' unexpectedly in $2"; fails=$((fails+1)); else echo "ok   $1"; fi; }
t()     { echo "--- $1"; }
sq()    { php -r '
  $d = new PDO("sqlite:" . $argv[1]);
  $d->setAttribute(PDO::FETCH_DEFAULT, PDO::FETCH_ASSOC);
  $v = $d->query($argv[2])->fetchColumn();
  echo $v === null ? "" : $v;
' "$TMP/data/board.db" "$1"; }
# Which board column does the first thing matching $2 sit in? The enclosing section is the one
# whose data-status comes last before it.
column_of() { php -r '
  $h = file_get_contents($argv[1]);
  $at = strpos($h, $argv[2]);
  if ($at === false) { echo "absent"; exit; }
  preg_match_all("/data-status=\"([a-z_]+)\"/", substr($h, 0, $at), $m);
  echo $m[1] ? end($m[1]) : "none";
' "$1" "$2"; }

# The deepest an <a> sits inside another <a>. Above 1 is invalid HTML and makes the inner link
# unclickable, which is the trap when a card is itself a link. Scans tags rather than using DOM,
# which the PHP build here does not have.
max_anchor_depth() { php -r '
  preg_match_all("/<(\/?)a\b[^>]*>/i", file_get_contents($argv[1]), $m);
  $d = 0; $max = 0;
  foreach ($m[0] as $t) {
      if ($t[1] === "/") { $d--; } else { $d++; if ($d > $max) { $max = $d; } }
  }
  echo $max;
' "$1"; }

# The current-member rows on a page — the assignee and board lists — one per line with whitespace
# collapsed, so a row can be asserted on as a single string. The picker dialogs use different markup
# and are deliberately not included.
picker_rows() { php -r '
  preg_match_all("/<div[^>]*data-picker-row.*?<\/div>/s", file_get_contents($argv[1]), $m);
  echo implode("\n", array_map(fn($b) => trim(preg_replace("/\s+/", " ", $b)), $m[0])), "\n";
' "$1"; }

# Which of the search box and its menu comes first in the markup. Only the input carries hx-swap, so
# finding that attribute before the menu means the box sits outside the region htmx replaces, and
# keeps its caret while results change. The other way round, the box would accept one letter.
markup_order() { php -r '
  $h = file_get_contents($argv[1]);
  $i = strpos($h, "hx-swap=\"outerHTML\"");
  $m = strpos($h, "<div id=\"search-menu\"");
  echo ($i !== false && $m !== false && $i < $m) ? "input-first" : "menu-first";
' "$1"; }

# The project ids a search result links to, comma separated, so a whole match set is compared at
# once rather than asserting that some expected id is somewhere in there.
menu_ids() { php -r '
  preg_match_all("/href=\"project\/\?id=(\d+)\"/", file_get_contents($argv[1]), $m);
  echo implode(",", $m[1]);
' "$1"; }

PIDS=()
cleanup() { for pid in "${PIDS[@]:-}"; do kill "$pid" 2>/dev/null; done; wait 2>/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT

mkdir -p "$TMP/data"
cp -r "$ROOT/src" "$ROOT/public" "$ROOT/db" "$ROOT/bin" "$TMP/"
(cd "$TMP" && php bin/migrate.php >/dev/null)
php "$ROOT/tests/seed.php" "$TMP/data/board.db" 'ada@example.com' 'correcthorse1' >/dev/null

# Run here rather than in auth.sh because the seeded database has projects, which is what the
# board cascade checks need in order to be exercised instead of skipped.
t "the seeded database matches the schema"
php "$ROOT/tests/schema.php" "$TMP/data/board.db" >"$TMP/schema.out" 2>&1
check "schema check passes" "0" "$?"
check "  ...including the board cascade" "0" "$(grep -c 'skipped the cascade' "$TMP/schema.out" || true)"

# Board ids for the tests below. General is where migration 003 puts everything, so the seed
# files its projects across General and a second board.
gen=$(sq "select id from boards where name='General'")
lab=$(sq "select id from boards where name='Lab Hardware'")
check "both boards exist" "2" "$(sq 'select count(*) from boards')"

# Every request below carries the session cookie, so the existing call sites stay readable.
cat > "$TMP/curlrc" <<EOF
cookie = "$jar"
cookie-jar = "$jar"
silent
show-error
EOF
curl() { command curl --config "$TMP/curlrc" "$@"; }

php -S "localhost:$PORT" -t "$TMP/public" >"$TMP/server.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 60); do curl -sS -o /dev/null "$base/login/" 2>/dev/null && break; sleep 0.1; done

t "sign in first"
loc=$(curl -sS -o /dev/null -w '%{redirect_url}' -X POST "$base/login/login.php" \
  -d 'email=ada@example.com' -d 'password=correcthorse1')
check "login redirects to the board" "$base/index.php" "$loc"

t "new project from a board preselects it"
curl -sS "$base/new/?board=$lab" -o "$TMP/page"
has "the board is already a row" "$TMP/page" 'data-draft-row data-value="'"$lab"'"'
curl -sS "$base/new/" -o "$TMP/page"
hasnt "a plain new project starts with no rows" "$TMP/page" 'data-draft-row data-value='

t "create project with assignees"
loc=$(curl -sS -o /dev/null -w '%{redirect_url}' -X POST "$base/new/add.php" \
  -d 'title=Flow Test' --data-urlencode 'description=Body & <b>text</b>' -d 'status=todo' -d 'type=bug' \
  -d 'start_date=2026-10-01' -d 'due_date=2026-12-01' -d 'as[]=1' -d 'as[]=3')
new_id=${loc##*id=}
check "redirects to the new project" "$base/project/?id=$new_id" "$loc"
check "it was recorded as this user" "1" "$(sq "select creator_id from projects where id=$new_id")"

t "new project page renders the saved values"
curl -sS "$base/project/?id=$new_id" -o "$TMP/page"
has "title"            "$TMP/page" "Flow Test"
# The number is the id and it leads the title, so the span's content must be exactly #<id>: any
# word put in front of it would land inside the span and fail this match.
check "its number leads the title, and it is the id" "1" \
  "$(tr '\n' ' ' < "$TMP/page" | grep -o '<h1 class="h3 mb-0">[^<]*<span class="ref">#'"$new_id"'</span>' | wc -l)"
has "type badge"       "$TMP/page" "bg-danger-subtle text-danger-emphasis"
has "start date"       "$TMP/page" 'value="2026-10-01"'
has "due date"         "$TMP/page" 'value="2026-12-01"'
has "escaped description" "$TMP/page" 'Body &amp; &lt;b&gt;text&lt;/b&gt;'
has "assignee 1 is a row"  "$TMP/page" 'data-picker-row data-value="1"'
hasnt "assignee 2 is not"   "$TMP/page" 'data-picker-row data-value="2"'
has "no comments yet"  "$TMP/page" "No comments yet."
has "status history"   "$TMP/page" "Created in To do"
hasnt "no subprojects yet" "$TMP/page" "Subprojects yet"
hasnt "no impersonation switcher" "$TMP/page" "Acting as"
has "signed in identity" "$TMP/page" "Ada Lovelace (admin)"

t "update the project"
loc=$(curl -sS -o /dev/null -w '%{redirect_url}' -X POST "$base/project/update.php" \
  -d "id=$new_id" -d 'title=Flow Test Renamed' -d 'status=review' -d 'type=research')
check "redirects back" "$base/project/?id=$new_id" "$loc"
curl -sS "$base/project/?id=$new_id" -o "$TMP/page"
has "new title"        "$TMP/page" "Flow Test Renamed"
has "status logged"    "$TMP/page" "To do to In review"
hasnt "old title gone" "$TMP/page" "Flow Test<"
has "type changed"     "$TMP/page" "text-info-emphasis"

t "change assignees"
loc=$(curl -sS -o /dev/null -w '%{redirect_url}' -X POST "$base/project/assign.php" -d "id=$new_id" -d 'as[]=2' -d 'as[]=4')
check "redirects back" "$base/project/?id=$new_id" "$loc"
curl -sS "$base/project/?id=$new_id" -o "$TMP/page"
has "assignee 2 is a row"  "$TMP/page" 'data-picker-row data-value="2"'
has "assignee 4 is a row"  "$TMP/page" 'data-picker-row data-value="4"'
hasnt "assignee 1 is gone" "$TMP/page" 'data-picker-row data-value="1"'

t "the people picker"
# Assignees 2 and 4 are chosen at this point, so they are rows, and the dialog offers the rest.
has "a row per chosen person"  "$TMP/page" 'data-picker-row data-value="2"'
has "  ...each carrying the field the endpoint reads" "$TMP/page" 'name="as[]" value="2"'
has "  ...and an × to remove it" "$TMP/page" 'data-picker-row data-value="2"'
has "  ...named, so the × says what it removes" "$TMP/page" 'aria-label="Remove Grace Hopper"'
has "  ...that posts the removal through htmx" "$TMP/page" "hx-vals='{\"remove\":2}'"
hasnt "  ...and nobody else is a row" "$TMP/page" 'data-picker-row data-value="1"'
has "  ...reached through an Add button" "$TMP/page" 'data-bs-toggle="modal"'
has "  ...pointing at a dialog"          "$TMP/page" 'data-bs-target="#picker-people-modal"'
has "  ...that offers each remaining person to click" "$TMP/page" 'data-picker-option data-value="1"'
has "  ...each entry posting itself through htmx" "$TMP/page" "hx-vals='{\"add\":1}'"
has "  ...naming the form it will swap into" "$TMP/page" 'hx-target="#people-form"'
has "  ...but not one already chosen"    "$TMP/page" 'bg-body d-none"'
has "  ...with a search box over the list" "$TMP/page" 'id="picker-people-search"'
has "  ...that filters by asking, not by script" "$TMP/page" 'hx-get="project/assign.php"'
has "  ...and re-asks whenever the dialog opens" "$TMP/page" 'shown.bs.modal from:#picker-people-modal'
hasnt "  ...with no checkbox list left behind" "$TMP/page" 'type="checkbox"'
hasnt "  ...and no no-scripting scaffolding" "$TMP/page" '<noscript>'

t "the search box filters on the server"
curl -sS -b "$jar" "$base/project/assign.php?id=$new_id&mode=options&q=hopper" -o "$TMP/list"
has "a match by name"       "$TMP/list" 'data-picker-option data-value="2"'
hasnt "  ...and nobody else" "$TMP/list" 'data-picker-option data-value="1"'
curl -sS -b "$jar" "$base/project/assign.php?id=$new_id&mode=options&q=ada@example.com" -o "$TMP/list"
has "  ...or by address, as the box promises" "$TMP/list" 'data-picker-option data-value="1"'
curl -sS -b "$jar" "$base/project/assign.php?id=$new_id&mode=options&q=nobody" -o "$TMP/list"
has "and says so when nothing matches" "$TMP/list" 'No matches.'
hasnt "  ...with no options at all" "$TMP/list" 'data-picker-option'

t "a pick saves over htmx and only the form's contents come back"
curl -sS -X POST -H 'HX-Request: true' "$base/project/assign.php" \
  -d "id=$new_id" -d 'add=1' -o "$TMP/frag"
has "the rows come back"  "$TMP/frag" 'data-picker-row data-value="1"'
hasnt "  ...with the dropped one gone" "$TMP/frag" 'data-picker-row data-value="2"'
# The swapped-in rows carry buttons, so those buttons have to name the same script the page asked
# for. Under <base> a bare assign.php resolves to /assign.php and 404s, and grepping the page alone
# would not catch it, because the page and the fragment are rendered by different files.
has "  ...and they post back to the same script" "$TMP/frag" 'hx-post="project/assign.php"'
hasnt "  ...not to a bare assign.php" "$TMP/frag" 'hx-post="assign.php"'
# The form and the dialog are page furniture and must not come back with the swap, or the page
# ends up with two copies of each and duplicate ids. Only the dialog's list is refreshed, and it
# comes back out of band so the modal element itself is left alone.
hasnt "  ...with no form in them" "$TMP/frag" '<form'
hasnt "  ...and no second dialog"  "$TMP/frag" 'class="modal fade"'
has "  ...but the list is refreshed out of band" "$TMP/frag" 'hx-swap-oob="true"'
has "  ...naming the list it replaces" "$TMP/frag" 'id="picker-people-options"'
has "  ...with the picked person no longer offered" "$TMP/frag" 'data-picker-option data-value="1"'
check "  ...and it was saved" "1" \
  "$(sq "select count(*) from project_assignees where project_id=$new_id and user_id=1")"
check "  ...and the rest replaced rather than added to" "1" \
  "$(sq "select count(*) from project_assignees where project_id=$new_id")"
# Put them back, so the counts further down are unaffected.
curl -sS -o /dev/null -X POST "$base/project/assign.php" -d "id=$new_id" -d 'as[]=2' -d 'as[]=4'

t "add a comment over htmx"
curl -sS -X POST -H 'HX-Request: true' "$base/project/comment.php" -d "id=$new_id" \
  --data-urlencode 'body=Line one
Line two & <b>x</b>' -o "$TMP/frag"
has "comment rendered" "$TMP/frag" "Line two &amp; &lt;b&gt;x&lt;/b&gt;"
has "author shown"     "$TMP/frag" "Ada Lovelace</a>"
  has "  ...and linked to their user" "$TMP/frag" 'class="link-quiet" href="users/#user-1"'
has "newline kept"     "$TMP/frag" "<br />"
hasnt "old empty state" "$TMP/frag" "No comments yet"
check "authored by this user" "1" "$(sq "select author_id from comments where project_id=$new_id")"

t "dashboard shows totals and assigned work"
curl -sS "$base/" -o "$TMP/dash"
has "totals"        "$TMP/dash" "All projects"
has "assigned work" "$TMP/dash" "Assigned to you"
hasnt "no per-board cards" "$TMP/dash" "Open board"
check "counts every project" "4" "$(sq 'select count(*) from projects')"

t "put the project on a board"
check "a project can start on no board" "0" "$(sq "select count(*) from project_boards where project_id=$new_id")"
hasnt "and says so"                  "$TMP/dash" "Bench Power Supply"
curl -sS "$base/list/?board=0" -o "$TMP/list0"
has "unassigned filter finds it" "$TMP/list0" "Flow Test Renamed"
curl -sS -X POST "$base/project/project_boards.php" -d "id=$new_id" -d "boards[]=$lab"
check "membership saved" "1" "$(sq "select count(*) from project_boards where project_id=$new_id and board_id=$lab")"
curl -sS "$base/board/?id=$lab" -o "$TMP/board"
has "card on that board" "$TMP/board" "project/?id=$new_id"
has "the card carries its number" "$TMP/board" '<span class="ref">#'"$new_id"'</span>'
has "board column"       "$TMP/board" 'data-status="review"'
has "board tagged for htmx" "$TMP/board" "data-board=\"$lab\""
curl -sS "$base/board/?id=$gen" -o "$TMP/gen"
hasnt "and not on the other board" "$TMP/gen" "project/?id=$new_id"
curl -sS "$base/list/?board=$lab" -o "$TMP/list"
has "list filtered by board" "$TMP/list" "Flow Test Renamed"
has "the list names it by number too" "$TMP/list" '<span class="ref">#'"$new_id"'</span>'
has "list shows the board"   "$TMP/list" "Lab Hardware"

t "the board picker"
curl -sS "$base/project/?id=$new_id" -o "$TMP/page"
has "a row for the board it is on" "$TMP/page" 'data-picker-row data-value="'"$lab"'"'
has "  ...carrying the field the endpoint reads" "$TMP/page" 'name="boards[]" value="'"$lab"'"'
has "  ...and an × that posts the removal" "$TMP/page" "hx-vals='{\"remove\":$lab}'"
hasnt "  ...and no row for the board it is not on" "$TMP/page" 'data-picker-row data-value="'"$gen"'"'
has "  ...reached through an Add button" "$TMP/page" 'data-bs-target="#picker-boards-modal"'
has "  ...with the project count for context" "$TMP/page" 'projects</span>'
has "  ...whose entries post themselves into that form" "$TMP/page" 'hx-target="#boards-form"'
has "  ...and the search box asks the server" "$TMP/page" 'hx-get="project/project_boards.php"'
has "  ...re-asking whenever the dialog opens" "$TMP/page" 'shown.bs.modal from:#picker-boards-modal'
hasnt "  ...and nothing in the list is searched in the browser" "$TMP/page" 'data-search='
# Class and data attributes land on different lines, so flatten before matching across them.
tr '\n' ' ' < "$TMP/page" | tr -s '[:space:]' ' ' > "$TMP/flat"
has "  ...the board it is on is not offered again" "$TMP/flat" \
  'bg-body d-none" data-picker-option data-value="'"$lab"'"'
has "  ...but the one it is not on still is" "$TMP/flat" \
  'bg-body" data-picker-option data-value="'"$gen"'"'

t "the board search looks on the name alone"
curl -sS -b "$jar" "$base/project/project_boards.php?id=$new_id&mode=options&q=general" -o "$TMP/list"
has "a match by name"       "$TMP/list" 'data-picker-option data-value="'"$gen"'"'
hasnt "  ...and not the board it is not" "$TMP/list" 'data-picker-option data-value="'"$lab"'"'
curl -sS -b "$jar" "$base/project/project_boards.php?id=$new_id&mode=options&q=hardware" -o "$TMP/list"
has "  ...though it does match the name it is part of" "$TMP/list" 'data-picker-option'
curl -sS -b "$jar" "$base/project/project_boards.php?id=$new_id&mode=options&q=benches" -o "$TMP/list"
hasnt "  ...never on the description, which is only there to be read" \
  "$TMP/list" 'data-picker-option'
curl -sS -b "$jar" "$base/project/project_boards.php?id=$new_id&mode=options&q=zzz" -o "$TMP/list"
has "and says so when nothing matches" "$TMP/list" 'No matches.'

t "adding a board over htmx"
curl -sS -X POST -H 'HX-Request: true' "$base/project/project_boards.php" \
  -d "id=$new_id" -d "boards[]=$lab" -d "add=$gen" -o "$TMP/frag"
has "the rows come back"  "$TMP/frag" 'data-picker-row data-value="'"$gen"'"'
# The swapped-in rows carry buttons, so those buttons have to name the same script the page asked
# for: under <base> a bare project_boards.php resolves to /project_boards.php and 404s.
has "  ...and they post back to the same script" "$TMP/frag" 'hx-post="project/project_boards.php"'
hasnt "  ...not to a bare project_boards.php" "$TMP/frag" 'hx-post="project_boards.php"'
hasnt "  ...with no form in them" "$TMP/frag" '<form'
hasnt "  ...and no second copy of the dialog" "$TMP/frag" 'class="modal fade"'
has "  ...but the list is refreshed out of band" "$TMP/frag" 'hx-swap-oob="true"'
has "  ...naming the list it replaces" "$TMP/frag" 'id="picker-boards-options"'
check "  ...and both were saved" "2" \
  "$(sq "select count(*) from project_boards where project_id=$new_id")"

t "removing a board leaves the rest alone"
curl -sS -X POST -H 'HX-Request: true' "$base/project/project_boards.php" \
  -d "id=$new_id" -d "boards[]=$lab" -d "boards[]=$gen" -d "remove=$gen" -o "$TMP/frag"
hasnt "  ...with its row gone" "$TMP/frag" 'data-picker-row data-value="'"$gen"'"'
has "  ...and the one sharing the board with it left alone" "$TMP/frag" 'data-picker-row data-value="'"$lab"'"'
curl -sS -b "$jar" "$base/project/project_boards.php?id=$new_id&mode=options" -o "$TMP/list"
has "the dropped board is offered again" "$TMP/list" 'data-picker-option data-value="'"$lab"'"'
check "  ...and only the other is kept" "1" \
  "$(sq "select count(*) from project_boards where project_id=$new_id")"

t "a project can end up on no board at all"
curl -sS -X POST -H 'HX-Request: true' "$base/project/project_boards.php" -d "id=$new_id" -o "$TMP/frag"
has "the panel says so"    "$TMP/frag" "On no board"
hasnt "  ...with no rows left" "$TMP/frag" 'data-picker-row data-value='
check "  ...and nothing is stored" "0" \
  "$(sq "select count(*) from project_boards where project_id=$new_id")"
# Back onto Lab Hardware, which the rest of this script assumes.
curl -sS -o /dev/null -X POST "$base/project/project_boards.php" -d "id=$new_id" -d "boards[]=$lab"
check "restored for the tests below" "1" \
  "$(sq "select count(*) from project_boards where project_id=$new_id and board_id=$lab")"

t "drag and drop to done"
curl -sS -X POST "$base/board/move.php" -d "id=$new_id" -d 'status=done' -d "board=$lab" -o "$TMP/board"
has "moved to done" "$TMP/board" 'data-status="done"'
has "card present"  "$TMP/board" "project/?id=$new_id"
# The edge colour belongs to the project type, so a card reads the same in every column.
has "coloured by its type, not by done"  "$TMP/board" 'class="card t-research'
hasnt "  ...with no colour left on the old column" "$TMP/board" 'class="card s-'
curl -sS -X POST "$base/board/move.php" -d "id=$new_id" -d 'status=review' -d "board=$gen" -o "$TMP/gen_board"
has   "scoped to that board"      "$TMP/gen_board" "Motor Interface"
hasnt "and leaks nothing else"    "$TMP/gen_board" "Bench Power Supply"
# A drop only moves a card between columns, so it is still on the board it was filed under.
curl -sS "$base/board/?id=$lab" -o "$TMP/board"
check "  ...and now sits in review" "review" "$(column_of "$TMP/board" 'class="card t-research')"
has   "  ...still coloured by its type" "$TMP/board" 'class="card t-research'

t "PATCH status"
curl -sS -X PATCH "$base/project/update_status.php" -d "id=$new_id" -d 'status=in_progress' -o "$TMP/json"
check "echoes the payload" '{"id":"'"$new_id"'","status":"in_progress"}' "$(cat "$TMP/json")"
check "and persisted it" "in_progress" "$(sq "select status from projects where id=$new_id")"

t "references point at things that exist"
# Runs while this project still exists, before the section below deletes it. Fetched fresh rather
# than leaning on whatever the sections above last left in these files.
curl -sS -b "$jar" "$base/users/"              -o "$TMP/users"
curl -sS -b "$jar" "$base/project/?id=$new_id" -o "$TMP/page"
curl -sS -b "$jar" "$base/list/?board=$lab"      -o "$TMP/list"
curl -sS -b "$jar" "$base/board/?id=$lab"      -o "$TMP/board"
curl -sS -b "$jar" "$base/index.php"           -o "$TMP/dash"
has "the user list has a row to land on"  "$TMP/users" 'id="user-1"'
has "an assignee links to their user"     "$TMP/page"  'class="link-quiet" href="users/#user-2"'
has "the creator links to their user"     "$TMP/page"  'class="link-quiet" href="users/#user-1"'
has "a board on the project links to it"  "$TMP/page"  'class="link-quiet" href="board/?id='"$lab"'"'
has "the list's board badge links to it"  "$TMP/list"  '<a class="badge text-bg-light border text-body me-1" href="board/?id='"$lab"'"'
has "the list's assignees link to users"  "$TMP/list"  'class="link-quiet" href="users/#user-2"'
has "the dashboard's board badge links too" "$TMP/dash" 'href="board/?id='"$lab"'"'
# Every user reference on every page must have a row to land on, so a renamed or deleted account
# cannot leave a dangling anchor behind.
check "every user link lands on a real row" "0" \
  "$(grep -oh 'users/#user-[0-9]*' "$TMP/page" "$TMP/list" "$TMP/board" "$TMP/dash" | grep -o '[0-9]*$' | sort -u \
     | while read -r uid; do grep -q "id=\"user-$uid\"" "$TMP/users" || echo "dangling $uid"; done | wc -l)"
# A card is itself a link, so anything added inside one has to stay out of the markup.
check "no anchor nested inside another" "1" "$(max_anchor_depth "$TMP/board")"
check "  ...nor on the list"          "1" "$(max_anchor_depth "$TMP/list")"
check "  ...nor on a project"         "1" "$(max_anchor_depth "$TMP/page")"
check "  ...nor on the dashboard"     "1" "$(max_anchor_depth "$TMP/dash")"
check "  ...nor on the user list"     "1" "$(max_anchor_depth "$TMP/users")"

t "the member lists on a project show names only"
curl -sS -b "$jar" "$base/project/?id=$new_id" -o "$TMP/page"
picker_rows "$TMP/page" > "$TMP/rows"
check "rows were found"                  "3" "$(wc -l < "$TMP/rows")"
check "no member row shows an address"   "0" "$(grep -c '@' "$TMP/rows")"
check "no member row shows anything else" "0" "$(grep -c 'text-body-secondary' "$TMP/rows")"
has   "an assignee row still names the person" "$TMP/rows" 'Grace Hopper'
hasnt "  ...and not the creator, who is not assigned" "$TMP/rows" 'Ada Lovelace'
has   "a board row still names the board"     "$TMP/rows" 'Lab Hardware'
has   "  ...and the person is still linked"    "$TMP/rows" 'href="users/#user-'
has   "  ...and the board is still linked"     "$TMP/rows" 'href="board/?id='"$lab"'"'
# The picker dialogs are a different thing: clicking a row there adds it rather than navigating, and
# the address is what tells two similarly named people apart. They keep the detail on purpose.
has   "the picker dialog still offers addresses" "$TMP/page" 'ada@example.com'
# The htmx endpoints redraw these same rows in place, so they have to agree with the page or the
# detail would come back the moment anyone edited the list.
curl -sS -H 'HX-Request: true' -b "$jar" -X POST "$base/project/assign.php" -d "id=$new_id" -d 'as[]=2' -o "$TMP/frag"
picker_rows "$TMP/frag" > "$TMP/rows2"
check "nor does the redraw after an edit"  "0" "$(grep -c '@' "$TMP/rows2")"
check "  ...nor any other detail"          "0" "$(grep -c 'text-body-secondary' "$TMP/rows2")"
has   "  ...and it keeps the link"         "$TMP/rows2" 'href="users/#user-'
curl -sS -H 'HX-Request: true' -b "$jar" -X POST "$base/project/project_boards.php" -d "id=$new_id" -d "boards[]=$lab" -o "$TMP/frag"
picker_rows "$TMP/frag" > "$TMP/rows3"
check "nor on a board redraw"              "0" "$(grep -c 'text-body-secondary' "$TMP/rows3")"
has   "  ...and the board link survives"   "$TMP/rows3" 'href="board/?id='"$lab"'"'

t "the header searches projects"
# The box belongs to the navbar, not to one page: present everywhere, and left of New project as asked.
curl -sS "$base/index.php" -o "$TMP/page"
check "the box comes before the New project button" "input-first" "$(markup_order "$TMP/page")"
has "it offers a search role"            "$TMP/page" 'role="search"'
has "it asks the server as you type"     "$TMP/page" 'hx-get="search.php"'
has "  ...swapping the menu"             "$TMP/page" 'hx-target="#search-menu"'
has "  ...by replacing it, not filling"  "$TMP/page" 'hx-swap="outerHTML"'
curl -sS "$base/list/" -o "$TMP/list"
has "it is on the list page too"         "$TMP/list" 'search-wrap'

# What the navbar would swap in, which is what htmx gets back.
search_menu() { curl -sS -H 'HX-Request: true' --get --data-urlencode "q=$1" "$base/search.php"; }
bench_id=$(sq "select id from projects where title='Bench Power Supply'")

search_menu 'bench power' > "$TMP/menu"
check "a whole title finds its project"  "$bench_id" "$(menu_ids "$TMP/menu")"
check "  ...and the swap is only the menu" "0" "$(grep -c '<!DOCTYPE' "$TMP/menu")"
search_menu 'bench' > "$TMP/menu"
check "a fragment of it does too"        "$bench_id" "$(menu_ids "$TMP/menu")"
search_menu 'BENCH' > "$TMP/menu"
check "  ...whatever the case"            "$bench_id" "$(menu_ids "$TMP/menu")"
search_menu '#'"$bench_id" > "$TMP/menu"
check "the number beside a title works"  "$bench_id" "$(menu_ids "$TMP/menu")"
search_menu "$bench_id" > "$TMP/menu"
check "  ...with or without its hash"     "$bench_id" "$(menu_ids "$TMP/menu")"

# Left unescaped these are wildcards, and a query of % would come back with the whole install.
for wild in '%' '_'; do
  search_menu "$wild" > "$TMP/menu"
  has "a $(printf '%q' "$wild") searches for itself" "$TMP/menu" "Nothing matches"
done
search_menu 'nothing here matches this' > "$TMP/menu"
has "a miss says so"                     "$TMP/menu" 'Nothing matches'
search_menu '' > "$TMP/menu"
has "an empty box shows no menu"         "$TMP/menu" 'search-menu d-none'
check "  ...and lists nothing"           ""         "$(menu_ids "$TMP/menu")"

# Without htmx the same form submits here, so the box still works rather than dying silently.
curl -sS --get --data-urlencode 'q=bench power' "$base/search.php" -o "$TMP/page"
has "without JS the box still finds it"  "$TMP/page" 'href="project/?id='"$bench_id"'"'
has "  ...as a page of matches"           "$TMP/page" 'Matches for'
curl -sS --get --data-urlencode 'q=nothing here matches this' "$base/search.php" -o "$TMP/page"
has "  ...and admits a miss"              "$TMP/page" 'Nothing matches'
curl -sS "$base/search.php" -o "$TMP/page"
has "an empty query asks for a title"    "$TMP/page" 'Search projects'

# Ten more, so the cap is the only thing separating a full page from the menu's handful. Without
# these the two always agree, and a page that quietly dropped its LIMIT would still pass.
php -r '
  $d = new PDO("sqlite:" . $argv[1]);
  $s = $d->prepare("INSERT INTO projects (title, type, status, created_at, updated_at)
                    VALUES (?, \"task\", \"todo\", datetime(\"now\"), datetime(\"now\"))");
  for ($i = 1; $i <= 10; $i++) { $s->execute(["Bulk Search $i"]); }
' "$TMP/data/board.db"
search_menu 'bulk search' > "$TMP/menu"
check "the menu stops at its cap" "8" "$(menu_ids "$TMP/menu" | tr ',' '\n' | grep -c .)"
has "  ...and offers the rest"   "$TMP/menu" 'See all matches'
curl -sS --get --data-urlencode 'q=bulk search' "$base/search.php" -o "$TMP/page"
check "the page lists every match" "10" "$(grep -c 'href="project/?id=' "$TMP/page")"
has "  ...and says how many"      "$TMP/page" '10 matches'

# Only reachable once signed in: SLATE_REQUIRE_LOGIN answers an anonymous request with a redirect
# before search.php gets to look at the method, so the refusal is asserted here rather than there.
check "search refuses a post" "405" "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$base/search.php" -d 'q=x')"

t "add a subproject, then delete the parent"
loc=$(curl -sS -o /dev/null -w '%{redirect_url}' -X POST "$base/project/add.php" \
  -d 'title=Sub One' -d 'parent_id='"$new_id" -d 'status=todo' -d "boards[]=$lab")
check "subproject returns to parent" "$base/project/?id=$new_id" "$loc"
sub_id=$(sq "select id from projects where title='Sub One'")
curl -sS "$base/project/?id=$new_id" -o "$TMP/page"
has "subproject listed" "$TMP/page" "Sub One"
curl -sS "$base/board/?id=$lab" -o "$TMP/board"
has "subproject counted on board" "$TMP/board" "0/1 subprojects done"

t "a subproject is contained in a card naming its parent"
has "wrapped and labelled" "$TMP/board" "Subproject of"
has "  ...by the parent's title" "$TMP/board" "Flow Test Renamed"
check "  ...and by the parent's number" "1" \
  "$(tr '\n' ' ' < "$TMP/board" | grep -o 'Subproject of.\{0,120\}' | grep -c '<span class="ref">#'"$new_id"'</span>')"
check "  ...linking to the parent" "1" \
  "$(tr '\n' ' ' < "$TMP/board" | grep -o 'Subproject of.\{0,120\}' | grep -c "project/?id=$new_id")"
# The wrapper, border and all, is the drag handle; the card inside it must not be a second one.
# Matched loosely on the class list so styling changes do not read as failures.
check "  ...the whole card is draggable as one" "1" \
  "$(tr '\n' ' ' < "$TMP/board" | grep -o 'class="card subproject[^"]*" draggable="true" data-id="'"$sub_id"'"' | wc -l)"
check "  ...and the inner card is not a second handle" "1" \
  "$(tr '\n' ' ' < "$TMP/board" | grep -o 'href="project/?id='"$sub_id"'" draggable="false"' | wc -l)"
check "  ...so the handle appears once" "1" "$(grep -c "data-id=\"$sub_id\"" "$TMP/board")"
check "  ...and top-level cards keep their shadow" "1" \
  "$(tr '\n' ' ' < "$TMP/board" | grep -o 'class="card t-[a-z_]*[^"]*shadow-sm"[[:space:]]*href="project/?id='"$new_id"'"' | wc -l)"
# The parent was moved to in_progress above, the subproject is created in todo, so the wrapper
# must not drag the subproject into its parent's column. A card's edge colour is its type now,
# so read which section the markup is in rather than looking for a class.
check "  ...in its own status column" "todo" "$(column_of "$TMP/board" 'card subproject')"
check "  ...while the parent is in another" "in_progress" "$(column_of "$TMP/board" 'card t-research')"
check "  ...and it is still draggable" "1" \
  "$(sq "select count(*) from projects where id=$sub_id and status='todo'")"

t "a subproject names its parent even across boards"
# Firmware Bring-up belongs to Motor Interface but is filed on no board at all.
check "  ...while off every board" "0" \
  "$(sq "select count(*) from project_boards where project_id=(select id from projects where title='Firmware Bring-up')")"
curl -sS "$base/board/?id=$gen" -o "$TMP/gen"
hasnt "  ...so it is absent from both boards" "$TMP/gen" "Firmware Bring-up"
curl -sS -o /dev/null -X POST "$base/project/project_boards.php" \
  -d "id=$(sq "select id from projects where title='Firmware Bring-up'")" -d "boards[]=$gen"
curl -sS "$base/board/?id=$gen" -o "$TMP/gen"
has "  ...and appears on the board it is filed under" "$TMP/gen" "Subproject of"
has "  ...still naming a parent on a different board" "$TMP/gen" "Motor Interface"
hasnt "  ...without dragging in the parent's other work" "$TMP/gen" "Bench Power Supply"

curl -sS -o /dev/null -X POST "$base/project/delete.php" -d "id=$new_id"
check "subproject cascaded away" "404" "$(curl -sS -o /dev/null -w '%{http_code}' "$base/project/?id=$new_id")"
curl -sS "$base/project/?id=$new_id" | grep -q "Project not found" && echo "ok   not found page" || { echo "FAIL not found page"; fails=$((fails+1)); }
check "membership cascaded too" "0" "$(sq "select count(*) from project_boards where project_id=$new_id")"

t "every page renders to the end"
# A PHP fatal stops the script partway through a document and leaves perfectly well-formed markup
# behind: every assertion above still finds whatever the page managed to emit before it gave up.
# Observed for real, when a project page stopped dead at the top of its right column and shipped
# with a suite full of green ticks, because nothing here asked whether the page had an ending.
#
# The closing tags are the only evidence, so ask for them. display_errors is off in most
# deployments, which is why the log leak is checked too: with it on, the message lands mid-page and
# the document can close perfectly while still being a bug.
finished() { tail -c 400 "$1" | grep -q '</html>' && echo yes || echo no; }
no_leak()   { grep -qiE 'fatal error|uncaught|<b>(warning|notice|deprecated)</b>' "$1" && echo leaked || echo clean; }

# Signed-in pages only. login/ and setup/ redirect away from here and are rendered for a signed-out
# visitor instead, which is auth.sh's business; walking them here would assert on an empty body.
live_id=$(sq "select id from projects order by id limit 1")
for page in "index.php" "list/" "boards/" "users/" "new/" "board/?id=$gen" \
            "project/?id=$live_id" "search.php?q=bench" "project/?id=999999"; do
  curl -sS "$base/$page" -o "$TMP/rendered"
  check "$page finishes"  "yes"   "$(finished "$TMP/rendered")"
  check "  ...leaks nothing" "clean" "$(no_leak "$TMP/rendered")"
done

# The right column is the part that died, and it is built from data rather than markup, so it is the
# part most able to fail: options, memberships and history all have to come back before anything is
# emitted there. live_id is read now, because an id deleted by an earlier section would render the
# 404 page, which finishes perfectly and has no pickers in it at all.
curl -sS "$base/project/?id=$live_id" -o "$TMP/rendered"
for want in picker-people-modal picker-boards-modal 'Status history'; do
  has "the project page still has $want" "$TMP/rendered" "$want"
done

t "the app survives a PHP without mbstring"
# The pickers used to call mb_strtolower() and mb_strlen() directly, and with the extension absent
# those are undefined functions, so any page carrying a picker stopped dead halfway down its markup
# with the message sitting only in the error log. Nothing about the machine running this suite is
# under its control, so the only honest test is a PHP that really lacks them.
check "the helpers still fold case" "abc" \
  "$(php -d disable_functions=mb_strtolower,mb_strlen -r 'require "'"$ROOT"'/src/lib.php"; echo lower("ABC");')"
check "  ...and count characters"  "3" \
  "$(php -d disable_functions=mb_strtolower,mb_strlen -r 'require "'"$ROOT"'/src/lib.php"; echo chars("abc");')"
# The guard above is only worth anything while nothing bypasses it. This is what reintroduced the
# fatal: a new direct call, in some file that happens to be on the path of a page nobody re-checked.
check "nothing calls mbstring directly" "0" \
  "$(grep -rn 'mb_strtolower\|mb_strlen' "$ROOT/src" "$ROOT/public" 2>/dev/null | grep -vc "^$ROOT/src/lib.php:")"

# A second server on that PHP. It shares the session save path, so the cookie from the first login is
# good here and there is no reason to sign in twice.
php -d disable_functions=mb_strtolower,mb_strlen -S "localhost:$NOPORT" -t "$TMP/public" >"$TMP/noext.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 60); do curl -sS -o /dev/null "http://localhost:$NOPORT/login/" 2>/dev/null && break; sleep 0.1; done
for page in "project/?id=$live_id" "new/" "boards/" "index.php"; do
  curl -sS "http://localhost:$NOPORT/$page" -o "$TMP/rendered"
  check "$page finishes without mbstring" "yes" "$(finished "$TMP/rendered")"
  check "  ...leaks nothing"              "clean" "$(no_leak "$TMP/rendered")"
done

t "a crash is reported rather than shipped"
# The reason this section exists. A fatal partway down a page used to leave a 200 and half a document
# on the wire, because the status line goes out with the first flush of the body and a 200 cannot be
# taken back afterwards. Which made the size of the page decide whether a crash was visible at all:
# the same fault returned a clean 500 near the top and a silently truncated page further down.
#
# A crash is therefore checked for three things a person can act on: a real status code, a complete
# page that says what happened instead of the half-built one, and a reference that ties what they saw
# to a line in the log. Anything less and a failure is something you find out about from a customer.
#
# On a copy of the app, because the fault has to be a fatal one and the rest of the suite needs a
# working server. Injecting it as late as the file allows is the point: by then the page has emitted
# several KB, which is exactly the case that used to pass as a success.
fault="$TMP/fault"
mkdir -p "$fault"
cp -r "$ROOT/src" "$ROOT/public" "$ROOT/db" "$fault/"
cp -r "$TMP/data" "$fault/data"          # same database, so the same ids and the same login cookie
# Two crashes in two places, because they fail differently. The page one goes in as late as the file
# allows, so by the time it throws the buffer is holding several KB of markup — the case that used to
# go out as a 200 with half a document on it. The fragment one goes in an htmx endpoint, which is
# never rendered as a page at all.
php -r '
  $f = $argv[1];
  $s = file_get_contents($f);
  $i = strrpos($s, "page_end();");
  if ($i === false) { fwrite(STDERR, "no page_end() in $f\n"); exit(1); }
  // Emit something first, so the buffer is genuinely holding output when the throw happens.
  file_put_contents($f, substr($s, 0, $i)
    . "echo \"<p>injected marker</p>\"; throw new Error(\"injected fault\");\n"
    . substr($s, $i));
' "$fault/public/project/index.php"
php -r '
  $f = $argv[1];
  $s = file_get_contents($f);
  $needle = "require __DIR__ . \x27/../../src/bootstrap.php\x27;";
  $i = strpos($s, $needle);
  if ($i === false) { fwrite(STDERR, "no bootstrap require in $f\n"); exit(1); }
  $i += strlen($needle);
  file_put_contents($f, substr($s, 0, $i) . "\nthrow new Error(\"injected fragment fault\");" . substr($s, $i));
' "$fault/public/project/assign.php"
php -S "localhost:$FPORT" -t "$fault/public" >"$TMP/fault.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 60); do curl -sS -o /dev/null "http://localhost:$FPORT/login/" 2>/dev/null && break; sleep 0.1; done

fbase="http://localhost:$FPORT"
check "a crash late in a page is a 500" "500" \
  "$(curl -sS -D "$TMP/fh" -o "$TMP/fault.html" -w '%{http_code}' "$fbase/project/?id=$live_id")"
has "  ...with a page that says what happened" "$TMP/fault.html" "Something went wrong"
check "  ...that finishes"                     "yes"   "$(finished "$TMP/fault.html")"
check "  ...leaking nothing"                   "clean" "$(no_leak "$TMP/fault.html")"
hasnt "  ...and none of the half-built page"   "$TMP/fault.html" "injected marker"

# The reference is the join between the page and the log. It has to be the same value in both, or one
# of them is describing a different failure, which is worse than neither having it.
ref=$(grep -i '^x-slate-error:' "$TMP/fh" | tr -d '\r' | awk '{print $2}')
check "  ...carrying a reference" "yes" \
  "$(printf '%s' "$ref" | grep -qE '^[0-9a-f]{8}$' && echo yes || echo no)"
has "  ...shown on the page too" "$TMP/fault.html" "$ref"
has "  ...and written to data/error.log" "$fault/data/error.log" "$ref"
has "  ...naming the failure"              "$fault/data/error.log" "injected fault"
has "  ...and to the SAPI log too"         "$TMP/fault.log"         "$ref"

# The fragment case, which is the one a person notices: htmx will not swap a non-2xx, so this response
# is never rendered and the header is the only thing that can carry the reference to the browser.
check "a crash in an htmx request is a 500 too" "500" \
  "$(curl -sS -D "$TMP/fh2" -o /dev/null -w '%{http_code}' -X POST "$fbase/project/assign.php" \
      -H 'HX-Request: true' -d "id=$live_id" -d 'add=2')"
has "  ...with a reference for the toast" "$TMP/fh2" "X-Slate-Error"

t "reject bad input"
check "blank title"     "Title is required"    "$(curl -sS -X POST "$base/new/add.php" -d 'title=')"
check "bad date"        "Dates must be YYYY-MM-DD" "$(curl -sS -X POST "$base/new/add.php" -d 'title=x' -d 'due_date=1-2-3')"
check "bad status"      "Invalid status"       "$(curl -sS -X POST "$base/new/add.php" -d 'title=x' -d 'status=nope')"
check "bad type"        "Invalid type"         "$(curl -sS -X POST "$base/new/add.php" -d 'title=x' -d 'type=nope')"
check "missing parent"  "Parent project not found" "$(curl -sS -X POST "$base/new/add.php" -d 'title=x' -d 'parent_id=9999')"
check "get on add"      "POST only"            "$(curl -sS "$base/new/add.php")"
check "get on update"   "POST only"            "$(curl -sS "$base/project/update.php")"
check "get on assign"   "Project not found"    "$(curl -sS -b "$jar" "$base/project/assign.php")"
check "unknown project" "Project not found"    "$(curl -sS -X POST "$base/project/assign.php" -d 'id=9999')"
check "update needs an id" "Project not found" "$(curl -sS -X POST "$base/project/update.php" -d 'title=x')"
check "update blank title" "Title is required" "$(curl -sS -X POST "$base/project/update.php" -d 'id=9999')"
check "htmx status bad" "PATCH only"           "$(curl -sS -X POST "$base/project/update_status.php" -d 'id=1&status=done')"

t "the removed identity switcher is gone"
check "no such endpoint" "404" "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$base/users/user.php" -d 'uid=4')"

echo
if [ "$fails" -eq 0 ]; then echo "all flow checks passed"; else echo "$fails check(s) failed"; fi
exit $((fails > 0))
