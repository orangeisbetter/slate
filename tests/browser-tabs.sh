#!/usr/bin/env bash
# Checks that the account page's tabs actually switch panes, in a real browser.
#   tests/browser-tabs.sh
#
# This is separate from flows.sh and auth.sh on purpose. Those read server-rendered markup with curl,
# so they can confirm the panes are in the document and that a link points at each one -- and that is
# all. Whether :target and :has() then show the right pane is decided by the browser's cascade, not by
# the HTML, and the whole feature lives or dies on that. Grepping the stylesheet cannot answer it, and
# asserting on the CSS text would only prove the rules are spelled the way this file spells them.
#
# So this drives headless Chromium against the real rendered page and asks it what is actually
# displayed. Skips itself, loudly, if no Chromium or the Bootstrap stylesheet is available, so it is
# never the reason a machine without a browser cannot run the rest of the suite.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d /tmp/slate-tabs.XXXXXX)"
PORT="${SLATE_TEST_PORT:-8127}"
BASE="http://localhost:$PORT"

fails=0
pass() { printf "  ok    %s\n" "$1"; }
fail() { printf "  FAIL  %s\n     expected: %s\n     actual:   %s\n" "$1" "$2" "$3"; fails=$((fails + 1)); }
is()   { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "$2" "$3"; fi; }
skip() { printf "  skip  %s\n" "$1"; exit 0; }

cleanup() { [ -n "${PID:-}" ] && kill "$PID" 2>/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT

CHROME=""
for c in chromium chromium-browser google-chrome google-chrome-stable; do
  command -v "$c" >/dev/null && { CHROME="$c"; break; }
done
[ -n "$CHROME" ] || skip "no Chromium found; the tab behaviour is unverified on this machine"

BOOTSTRAP="$TMP/bootstrap.min.css"
curl -sf --max-time 20 \
  "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" -o "$BOOTSTRAP" \
  || skip "could not fetch the Bootstrap stylesheet; the panes would not be hidden without it"

mkdir -p "$TMP/data"
cp -r "$ROOT/src" "$ROOT/public" "$ROOT/db" "$ROOT/bin" "$TMP/"
(cd "$TMP" && php bin/migrate.php >/dev/null) || skip "could not migrate the scratch database"
php "$ROOT/tests/seed.php" "$TMP/data/board.db" 'ada@example.com' 'correcthorse1' >/dev/null \
  || skip "could not seed the scratch database"

php -S "localhost:$PORT" -t "$TMP/public" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 40); do curl -sf "$BASE/login/" -o /dev/null && break; sleep .25; done

curl -sS -c "$TMP/jar" -d 'email=ada@example.com&password=correcthorse1' \
  "$BASE/login/login.php" -o /dev/null
curl -sS -b "$TMP/jar" "$BASE/account/" -o "$TMP/account.html"
grep -q 'id="tab-profile"' "$TMP/account.html" || skip "the account page did not render; is the server up?"

# The page as the browser gets it, with the two stylesheets pointed at local copies so it can be loaded
# from disk without the network, and with transitions switched off. Bootstrap transitions
# background-color on .nav-link over .15s, and a computed style read while that is still running
# reports the value it started from -- which looks exactly like the rule not applying. Turning
# transitions off does not change which pane is displayed or which tab is current; it only stops the
# measurement from catching them mid-fade.
python3 - "$TMP" <<'PY'
import re, sys
tmp = sys.argv[1]
html = open(f"{tmp}/account.html").read()
html = html.replace(
    'href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"',
    'href="/bootstrap.min.css"')
html = html.replace(
    'src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"', '')
html = html.replace('</head>',
                    '<style>*,*::before,*::after{transition:none !important;animation:none !important}</style>\n</head>')
probe = """
<script>
window.addEventListener('load', function () {
  var out = [];
  ['tab-profile','tab-password','tab-access'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el && getComputedStyle(el).display !== 'none') { out.push('pane:' + id); }
  });
  document.querySelectorAll('.account-tabs .nav-link').forEach(function (a) {
    if (getComputedStyle(a).backgroundColor !== 'rgba(0, 0, 0, 0)') {
      out.push('tab:' + a.hash.replace('#tab-', ''));
    }
  });
    // Resolve each tab link the way a click would, so a link that escapes /account/ is caught.
    // Reading a.href uses the browser's own resolution rather than a guess at string concatenation.
  document.querySelectorAll('.account-tabs .nav-link').forEach(function (a) {
    var u = new URL(a.href);
    out.push('link:' + u.pathname + u.hash);
  });
  var pre = document.createElement('pre');
  pre.id = 'RESULT';
  pre.textContent = out.join(' ') || 'nothing';
  document.body.appendChild(pre);
});
</script>
"""
open(f"{tmp}/public/account/probe.html", 'w').write(html.replace('</body>', probe + '</body>'))
PY
cp "$BOOTSTRAP" "$TMP/public/bootstrap.min.css"

# The probe stands in for the account page, so it is served from that directory: a bare fragment
# resolves against the current URL, and this is the resolution a reader of /account/ actually gets.
PROBE_PATH="/account/probe.html"

  # What the browser should show for each fragment: the one pane, the one tab marked current, and the
  # three links resolved within the account directory.
  LINKS="link:$PROBE_PATH#tab-profile link:$PROBE_PATH#tab-password link:$PROBE_PATH#tab-access"
want() { echo "pane:tab-$1 tab:$1 $LINKS"; }

probe() {
  "$CHROME" --headless --no-sandbox --disable-gpu --virtual-time-budget=4000 --dump-dom \
    "$BASE$PROBE_PATH$1" 2>/dev/null \
    | sed -n '/id="RESULT"/,/<\/pre>/p' | sed 's/<[^>]*>//g' | tr -s ' \n' ' ' | sed 's/^ *//;s/ *$//'
}

echo "== the account tabs, in a browser =="
is "no fragment shows the first pane and marks the first tab" "$(want profile)" "$(probe '')"
is "a fragment shows that pane and marks that tab" "$(want profile)" "$(probe '#tab-profile')"
is "  ...for the password pane" "$(want password)" "$(probe '#tab-password')"
is "  ...and for the access pane" "$(want access)" "$(probe '#tab-access')"

  # Each link must resolve inside this page's own directory, or clicking it navigates away and the
  # pane never appears. A path-carrying href would resolve to the same place, so this pins the bare
  # fragment to actually staying put. The browser does the resolving, via a.href.
is "every tab link resolves within the account page, not elsewhere" \
  "pane:tab-profile tab:profile $LINKS" "$(probe '')"

if [ "$fails" -gt 0 ]; then
  echo "  $fails check(s) failed"
  exit 1
fi
echo "  all browser tab checks passed"
