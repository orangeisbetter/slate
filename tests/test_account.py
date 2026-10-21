"""The account page: two panes, Profile and Sign-in, and the endpoints behind each.

The page used to be three — Profile, Password, Access. Project #30 re-cut them: the name form and
the role badges on Profile, and email, password and the Slack connection on Sign-in. The panes are
switched by CSS (:target/:has()), so what is in the *document* is pinned here; which one is shown is
test_browser_tabs.py's job, since that is decided by the cascade and not by the HTML. Project #34
added the signed-in devices list to the Sign-in pane, and the revoke endpoints behind it.
"""
import hashlib
import sqlite3
import subprocess
from urllib.parse import unquote

from conftest import Client, SEED_EMAIL, SEED_PASSWORD
from helpers import is_redirect, soup, text_of

# A browser the app can name, unlike the python-requests default the other clients carry.
CHROME_MAC = ("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")


# --- helpers ------------------------------------------------------------------------------------

def php_hash(password):
    """A PHP password_hash() from Python. Standalone: PASSWORD_DEFAULT needs no app bootstrap."""
    out = subprocess.run(
        ["php", "-r", "echo password_hash($argv[1], PASSWORD_DEFAULT);", password],
        capture_output=True, text=True, check=True)
    return out.stdout


def insert_user(app, **cols):
    """A user row of our own, committed: App.sql's connection closes without committing a write."""
    names = ",".join(cols)
    marks = ",".join("?" * len(cols))
    con = sqlite3.connect(app.db_path)
    try:
        con.execute(f"insert into users({names}) values({marks})", list(cols.values()))
        con.commit()
        uid = con.execute("select id from users where email=?", [cols["email"]]).fetchone()[0]
        return uid
    finally:
        con.close()


def set_column(app, table, column, value, where, arg):
    con = sqlite3.connect(app.db_path)
    try:
        con.execute(f"update {table} set {column}=? where {where}=?", [value, arg])
        con.commit()
    finally:
        con.close()


def session_rows(client):
    """The Sign-in pane's device rows, from the browser asking.

    Each row is the label text, the hashed session id its revoke form carries (None on the row
    for this device, which has no form), and whether it is this device. The suite shares one
    database, so earlier tests leave their own signed-in browsers behind: assertions about "a
    second session" are relative to a snapshot taken before it appears, never a total count.
    """
    table = soup(client.get("/account/")).select_one("#tab-signin table")
    rows = []
    for tr in table.select("tbody tr"):
        tds = tr.select("td")
        form = tr.select_one('form[action="/account/revoke_session.php"]')
        rows.append({
            "device": text_of(tds[0]),
            "id": form.select_one("input[name=id]")["value"] if form else None,
            "here": "This device" in text_of(tds[0]),
        })
    return rows


# --- the two panes ------------------------------------------------------------------------------

def test_the_account_page_has_exactly_two_panes(signed_in):
    page = soup(signed_in.get("/account/"))
    assert [a.get("href") for a in page.select(".account-tabs .nav-link")] == \
        ["#tab-profile", "#tab-signin"]
    assert page.select_one("#tab-profile") and page.select_one("#tab-signin")
    assert page.select_one("#tab-password") is None, "the Password pane is gone"
    assert page.select_one("#tab-access") is None, "the Access pane is gone"


def test_the_name_form_is_on_profile_and_roles_are_read_only(app, signed_in):
    page = soup(signed_in.get("/account/"))
    profile = page.select_one("#tab-profile")

    assert profile.select_one('form[action="/account/update_name.php"]')
    assert profile.select_one('input[name="name"]')
    assert profile.select_one('input[name="roles"]') is None, \
        "roles are the admin's to change, not a form on your own page"
    assert profile.select_one('input[name="active"]') is None
    assert "admin" in text_of(profile), "the role you hold is still shown"


def test_the_signin_pane_holds_email_password_and_slack(signed_in):
    signin = soup(signed_in.get("/account/")).select_one("#tab-signin")

    assert signin.select_one('form[action="/account/change_password.php"]')
    assert signin.select_one('input[readonly]'), "the address is there, read-only"
    assert signin.select_one('form[action="/account/unlink_slack.php"]') is None, \
        "nothing is linked, so there is nothing to unlink"
    assert signin.select_one('a[href^="/login/slack.php"]')["href"] == \
        "/login/slack.php?next=/account/#tab-signin"


def test_the_admin_card_is_gone(signed_in):
    """The navbar offers Admin to admins now, so the account page's own card was a second door.
    Scoped to the panes: the navbar's own Admin link is the one that stays."""
    page = soup(signed_in.get("/account/"))
    assert page.select_one('.tab-content a[href="/admin/"]') is None


# --- what each endpoint returns to -------------------------------------------------------------

def test_saving_the_name_returns_to_profile(app, signed_in):
    """Renamed and then put straight back: test_comments reads the seeded name off an author."""
    r = signed_in.patch("/account/update_name.php", data={"name": "Ada L."})
    assert is_redirect(r)
    assert r.headers["Location"].startswith("/account/?saved=")
    assert r.headers["Location"].endswith("#tab-profile")
    assert "Ada L." in soup(signed_in.get("/account/")).select_one("#tab-profile").select_one("input").get("value")

    signed_in.patch("/account/update_name.php", data={"name": "Ada Lovelace"})


def test_an_empty_name_is_refused_back_on_profile(signed_in):
    r = signed_in.patch("/account/update_name.php", data={"name": "  "})
    assert "error=" in r.headers["Location"] and r.headers["Location"].endswith("#tab-profile")
    assert "A name is needed" in unquote(r.headers["Location"])


# --- site theme ---------------------------------------------------------------------------------

def test_the_theme_picker_is_on_profile(signed_in):
    profile = soup(signed_in.get("/account/")).select_one("#tab-profile")
    form = profile.select_one('form[action="/account/update_theme.php"]')
    assert form, "the theme form is on the profile pane"
    chosen = form.select_one("select[name=theme] option[selected]")
    assert chosen and chosen["value"] == "default", "the seeded account is on the default theme"
    assert soup(signed_in.get("/account/")).select_one("html").get("data-bs-theme") is None


def test_choosing_a_theme_changes_the_page(app, signed_in):
    """Changed and then put straight back: the session is shared, and a theme left on the seeded
    admin would decide what every later test's pages carry."""
    r = signed_in.patch("/account/update_theme.php", data={"theme": "halloween"})
    assert is_redirect(r) and r.headers["Location"].endswith("#tab-profile")

    page = soup(signed_in.get("/account/"))
    assert page.select_one('link[href="/assets/themes/halloween.css"]'), \
        "the theme's own stylesheet is loaded"
    assert page.select_one("html")["data-bs-theme"] == "dark", \
        "the palette comes from Bootstrap's dark mode, not a hand-written copy"

    signed_in.patch("/account/update_theme.php", data={"theme": "default"})
    page = soup(signed_in.get("/account/"))
    assert page.select_one('link[href="/assets/themes/halloween.css"]') is None
    assert page.select_one("html").get("data-bs-theme") is None


def test_an_unknown_theme_is_refused(app, signed_in):
    r = signed_in.patch("/account/update_theme.php", data={"theme": "neon"})
    assert "error=" in r.headers["Location"] and r.headers["Location"].endswith("#tab-profile")
    assert "That is not a theme" in unquote(r.headers["Location"])
    assert app.sql("select theme from users where id=1") == [("default",)]


def test_the_theme_applies_to_every_page(app, signed_in):
    """The header is on every page, so the theme is chosen once in page_start(), not per page."""
    signed_in.patch("/account/update_theme.php", data={"theme": "halloween"})
    assert soup(signed_in.get("/index.php")).select_one("html")["data-bs-theme"] == "dark"
    signed_in.patch("/account/update_theme.php", data={"theme": "default"})


def test_changing_the_password_returns_to_signin(signed_in):
    """Changed and then put straight back: the seeded password is what every other fixture signs
    in with, and one test changing it for the session would fail the whole rest of the suite."""
    r = signed_in.put("/account/change_password.php", data={
        "current": SEED_PASSWORD, "password": "temporarypass1", "password2": "temporarypass1"})
    assert is_redirect(r) and r.headers["Location"].endswith("#tab-signin")

    r = signed_in.put("/account/change_password.php", data={
        "current": "temporarypass1", "password": SEED_PASSWORD, "password2": SEED_PASSWORD})
    assert is_redirect(r) and r.headers["Location"].endswith("#tab-signin")


def test_changing_the_password_signs_every_other_session_out(app, signed_in):
    """The credential behind every other session has changed, so those sessions are refused on
    their next request — while the one that made the change stays signed in throughout. Same
    put-it-back discipline as the test above: the seeded password outlives this test."""
    other = Client(app.base)
    r = other.post("/login/login.php", data={"email": SEED_EMAIL, "password": SEED_PASSWORD})
    assert r.status_code in (302, 303), "a second session on the same account"
    assert other.get("/account/").status_code == 200
    assert signed_in.get("/account/").status_code == 200

    r = signed_in.put("/account/change_password.php", data={
        "current": SEED_PASSWORD, "password": "temporarypass1", "password2": "temporarypass1"})
    assert is_redirect(r)

    # The other session is thrown away outright — logged out, cookie cleared — rather than merely
    # refused a page, so re-activating the account cannot hand it back its access.
    r = other.get("/index.php")
    assert is_redirect(r)
    assert "/login/" in r.headers["Location"]
    assert other.cookies.get_dict() == {}, "the dead session kept no cookie"
    # Its row went with the hash change too: the table the account page lists is not left holding
    # a browser that is already refused. One row remains, and it is this device's.
    assert app.sql("select count(*) from sessions where user_id=1") == [(1,)]
    # The session that changed the password kept its own.
    assert signed_in.get("/account/").status_code == 200

    r = signed_in.put("/account/change_password.php", data={
        "current": "temporarypass1", "password": SEED_PASSWORD, "password2": SEED_PASSWORD})
    assert is_redirect(r)


def test_a_wrong_current_password_is_refused_on_signin(signed_in):
    r = signed_in.put("/account/change_password.php", data={
        "current": "not-the-password", "password": "temporarypass1", "password2": "temporarypass1"})
    assert "error=" in r.headers["Location"] and r.headers["Location"].endswith("#tab-signin")
    assert "That is not your current password" in unquote(r.headers["Location"])


def test_the_account_endpoints_refuse_get(signed_in):
    for path in ("/account/update_name.php", "/account/change_password.php",
                 "/account/unlink_slack.php", "/account/update_theme.php",
                 "/account/revoke_session.php", "/account/revoke_other_sessions.php"):
        assert signed_in.get(path).status_code == 405, f"{path} should not answer GET"


# --- unlinking Slack ---------------------------------------------------------------------------

def test_unlink_is_refused_when_nothing_is_linked(signed_in):
    r = signed_in.delete("/account/unlink_slack.php")
    assert "No Slack account is linked" in unquote(r.headers["Location"])
    assert r.headers["Location"].endswith("#tab-signin")


def test_unlink_takes_the_connection_off(app, signed_in):
    uid = insert_user(app, name="Linkable", email="linkable@example.com",
                      password_hash=php_hash("temporarypass1"), slack_id="U-linked", active=1)
    c = Client(app.base)
    assert c.post("/login/login.php",
                  data={"email": "linkable@example.com", "password": "temporarypass1"}).status_code in (302, 303)

    page = soup(c.get("/account/"))
    assert page.select_one('form[action="/account/unlink_slack.php"]'), "the button is there while linked"

    r = c.delete("/account/unlink_slack.php")
    assert is_redirect(r) and "Slack account unlinked" in unquote(r.headers["Location"])
    assert r.headers["Location"].endswith("#tab-signin")
    assert app.sql("select slack_id from users where id=?", uid) == [(None,)]
    assert c.get("/account/").status_code == 200


def test_unlink_is_refused_when_it_would_leave_no_way_in(app, signed_in):
    """The one guard. A Slack-only account has no password, so unlinking would strand it: set a
    password first, then it goes through. The session is taken while the password still exists and
    kept across its removal, the way a shared browser would hold one."""
    uid = insert_user(app, name="Slack Only", email="slackonly@example.com",
                      password_hash=php_hash("temporarypass1"), slack_id="U-guard", active=1)
    c = Client(app.base)
    assert c.post("/login/login.php",
                  data={"email": "slackonly@example.com", "password": "temporarypass1"}).status_code in (302, 303)

    set_column(app, "users", "password_hash", None, "id", uid)

    r = c.delete("/account/unlink_slack.php")
    assert "Set a password first" in unquote(r.headers["Location"])
    assert app.sql("select slack_id from users where id=?", uid) == [("U-guard",)], "the link stays"

    set_column(app, "users", "password_hash", php_hash("temporarypass1"), "id", uid)
    r = c.delete("/account/unlink_slack.php")
    assert "Slack account unlinked" in unquote(r.headers["Location"])
    assert app.sql("select slack_id from users where id=?", uid) == [(None,)]


# --- signed-in devices -------------------------------------------------------------------------

def test_this_device_is_listed_and_offers_no_revoke_for_itself(signed_in):
    """The card is on the Sign-in pane, and the browser asking is named rather than offered a
    button: signing out of where you already are is the navbar's Sign out, and it works."""
    page = soup(signed_in.get("/account/"))
    assert "Signed-in devices" in text_of(page.select_one("#tab-signin"))

    here = [r for r in session_rows(signed_in) if r["here"]]
    assert len(here) == 1, "exactly one row is the browser asking"
    assert here[0]["id"] is None, "this device carries no revoke form"


def test_a_second_browser_appears_and_can_be_signed_out(app, signed_in):
    before = {r["id"] for r in session_rows(signed_in)}

    other = Client(app.base)
    other.headers["User-Agent"] = CHROME_MAC
    assert other.post("/login/login.php",
                      data={"email": SEED_EMAIL, "password": SEED_PASSWORD}).status_code in (302, 303)

    new = {r["id"] for r in session_rows(signed_in)} - before
    assert len(new) == 1, "the second browser is listed"
    new_id = new.pop()
    row = [r for r in session_rows(signed_in) if r["id"] == new_id][0]
    assert row["device"] == "Chrome on macOS", "the label is read from the User-Agent"
    assert not row["here"]

    r = signed_in.delete("/account/revoke_session.php", data={"id": new_id})
    assert is_redirect(r) and r.headers["Location"].endswith("#tab-signin")
    assert "signed out" in unquote(r.headers["Location"])

    # The revoked browser is thrown away on its next request, cookie and all -- the same way a
    # password change treats it, but for one session rather than every other one.
    r = other.get("/index.php")
    assert is_redirect(r)
    assert "/login/" in r.headers["Location"]
    assert other.cookies.get_dict() == {}
    assert signed_in.get("/account/").status_code == 200, "the device that asked is unaffected"
    assert new_id not in {r["id"] for r in session_rows(signed_in)}, "the row is gone"


def test_sign_out_everywhere_else_keeps_this_device(app, signed_in):
    others = []
    for _ in range(2):
        c = Client(app.base)
        assert c.post("/login/login.php",
                      data={"email": SEED_EMAIL, "password": SEED_PASSWORD}).status_code in (302, 303)
        others.append(c)
    assert len([r for r in session_rows(signed_in) if not r["here"]]) >= 2

    r = signed_in.post("/account/revoke_other_sessions.php")
    assert is_redirect(r) and r.headers["Location"].endswith("#tab-signin")
    assert "Everywhere else" in unquote(r.headers["Location"])

    # Every other row goes, the ones earlier tests left behind included; this device stays.
    assert [r["here"] for r in session_rows(signed_in)] == [True]
    for c in others:
        assert is_redirect(c.get("/index.php"))
        assert c.cookies.get_dict() == {}
    assert signed_in.get("/account/").status_code == 200


def test_revoking_this_device_signs_it_out(app, signed_in):
    """The endpoint allows the current session's own id, and answering it is signing out --
    the session goes the way /logout.php would take it rather than being left half-alive."""
    raw = signed_in.cookies.get("slate")
    mine = hashlib.sha256(raw.encode()).hexdigest()
    assert app.sql("select user_id from sessions where id=?", mine) == [(1,)], \
        "the table keys sessions by the hash of the cookie, not the cookie itself"

    r = signed_in.delete("/account/revoke_session.php", data={"id": mine})
    assert is_redirect(r) and r.headers["Location"] == "/login/"
    assert signed_in.cookies.get_dict() == {}
    assert is_redirect(signed_in.get("/account/"))
    assert app.sql("select id from sessions where id=?", mine) == []


def test_revoking_a_session_that_is_not_yours_is_a_404(app, signed_in, member):
    """Another account's session, and an id naming none: both a 404, because a 403 would
    confirm the id names a session somewhere. The member is session-scoped, so the attempt
    has to leave it signed in for the tests after this one."""
    member_id = app.sql("select id from users where email='member@example.com'")[0][0]
    their_session = app.sql(
        "select id from sessions where user_id=? order by last_seen_at desc limit 1",
        member_id)[0][0]

    for stolen in (their_session, "0" * 64):
        r = signed_in.delete("/account/revoke_session.php", data={"id": stolen})
        assert r.status_code == 404

    assert app.sql("select id from sessions where id=?", their_session) == [(their_session,)]
    assert member.get("/index.php").status_code == 200
