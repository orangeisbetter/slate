"""The account page's tabs, switched by the browser's cascade, checked in a real browser.

Ported from the old browser-tabs.sh. The other account tests read the server-rendered markup, so
they can confirm the panes are in the document and that a link points at each one -- and that is all.
Whether :target and :has() then *show* the right pane is decided by the cascade, not by the HTML, and
the whole feature lives or dies on that. Grepping the stylesheet cannot answer it, and asserting on
the CSS text would only prove the rules are spelled the way this file spells them.

So this drives headless Chromium against the real rendered page and asks it what is actually
displayed. It skips, loudly, if there is no Chromium or the Bootstrap stylesheet cannot be fetched,
so a machine without a browser can still run the rest of the suite.
"""
import re
import shutil
import subprocess

import pytest
import requests

from conftest import SEED_EMAIL, SEED_PASSWORD

BOOTSTRAP_CSS = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
BOOTSTRAP_JS = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
CHROMIUM_NAMES = ("chromium", "chromium-browser", "google-chrome", "google-chrome-stable")
PROBE_PATH = "/account/probe.html"

# Runs in the page and reports what the browser actually displays. Transitions are off because
# Bootstrap fades background-color on .nav-link over .15s, and a computed style read while that is
# still running reports the value it started from -- which looks exactly like the rule not applying.
# Turning transitions off changes neither which pane is shown nor which tab is current; it only stops
# the measurement from catching them mid-fade.
PROBE_SCRIPT = """
<script>
window.addEventListener('load', function () {
  var out = [];
  ['tab-profile','tab-signin'].forEach(function (id) {
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

LINKS = f"link:{PROBE_PATH}#tab-profile link:{PROBE_PATH}#tab-signin"


def _chromium_path():
    for name in CHROMIUM_NAMES:
        path = shutil.which(name)
        if path:
            return path
    return None


def _want(kind):
    """What the browser should show for one fragment: the one pane, the one tab marked current, and
    the two links resolved inside the account directory."""
    return f"pane:tab-{kind} tab:{kind} {LINKS}"


@pytest.fixture(scope="session")
def chromium():
    path = _chromium_path()
    if not path:
        pytest.skip("no Chromium found; the tab behaviour is unverified on this machine")
    return path


@pytest.fixture(scope="session")
def tabs_page(app, chromium):
    """Build the probe once: the account page as the browser gets it, with both stylesheets local so
    it loads without the network. Served from /account/ so a bare fragment resolves against that
    directory, the resolution a reader of /account/ actually gets."""
    try:
        css = requests.get(BOOTSTRAP_CSS, timeout=20)
        css.raise_for_status()
    except requests.RequestException as exc:
        pytest.skip(f"could not fetch the Bootstrap stylesheet; the panes would not be hidden ({exc})")

    c = requests.Session()
    c.post(f"{app.base}/login/login.php",
           data={"email": SEED_EMAIL, "password": SEED_PASSWORD}, allow_redirects=False)
    html = c.get(f"{app.base}/account/").text
    if 'id="tab-profile"' not in html:
        pytest.skip("the account page did not render; is the server up?")

    (app.dir / "public" / "bootstrap.min.css").write_text(css.text)
    html = html.replace(f'href="{BOOTSTRAP_CSS}"', 'href="/bootstrap.min.css"')
    html = html.replace(f'src="{BOOTSTRAP_JS}"', "")
    html = html.replace(
        "</head>",
        "<style>*,*::before,*::after{transition:none !important;animation:none !important}</style>\n</head>",
    )
    (app.dir / "public" / "account" / "probe.html").write_text(
        html.replace("</body>", PROBE_SCRIPT + "</body>"))


def _probe(chromium, app, fragment):
    out = subprocess.run(
        [chromium, "--headless", "--no-sandbox", "--disable-gpu",
         "--virtual-time-budget=4000", "--dump-dom", f"{app.base}{PROBE_PATH}{fragment}"],
        capture_output=True, text=True, timeout=30,
    )
    m = re.search(r'<pre id="RESULT">(.*?)</pre>', out.stdout, re.S)
    if not m:
        return ""
    return " ".join(re.sub(r"<[^>]+>", "", m.group(1)).split())


# --- the account tabs, in a browser -------------------------------------------------------------

def test_no_fragment_shows_the_first_pane_and_marks_the_first_tab(chromium, app, tabs_page):
    assert _probe(chromium, app, "") == _want("profile")


def test_a_fragment_shows_that_pane_and_marks_that_tab(chromium, app, tabs_page):
    assert _probe(chromium, app, "#tab-profile") == _want("profile")


def test_the_signin_fragment_shows_the_signin_pane(chromium, app, tabs_page):
    assert _probe(chromium, app, "#tab-signin") == _want("signin")


def test_every_tab_link_resolves_within_the_account_page(chromium, app, tabs_page):
    links = [part for part in _probe(chromium, app, "").split() if part.startswith("link:")]
    assert links == LINKS.split()
