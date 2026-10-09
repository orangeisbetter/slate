"""Login flow: who gets in, who doesn't, and what a session does and doesn't allow.

Grounded in flows.sh: POST /login/login.php with email + password redirects to /index.php; signed-in
pages show the user's identity; /login/ and /setup/ redirect away from a signed-in visitor;
SLATE_REQUIRE_LOGIN sends anonymous requests to a redirect.

Tests marked [assumption] encode something I inferred rather than saw in the app's source. If one
fails, check the assumption before assuming the app is wrong. Tests marked [expectation] are
properties a login system should have; a failure there is a finding, not a bad test.
"""
import re
from urllib.parse import parse_qs, urljoin, urlparse

import pytest
from bs4 import BeautifulSoup

LEAK = re.compile(r"fatal error|uncaught|<b>(warning|notice|deprecated)</b>", re.I)

PROTECTED_PAGES = ["/index.php", "/list/", "/boards/", "/admin/", "/admin/statuses/", "/admin/users/",
                   "/new/", "/account/", "/search.php?q=x"]


def redirect_path(app, response):
    """Where a redirect points, as a path, whether the server sent a relative or absolute URL."""
    loc = response.headers.get("Location")
    return urlparse(urljoin(app.base + "/", loc)).path if loc else None


def is_redirect(response):
    return response.status_code in (301, 302, 303, 307, 308)


# --- the login page --------------------------------------------------------------------------

def test_login_page_renders_for_a_signed_out_visitor(anon):
    r = anon.get("/login/")
    assert r.status_code == 200
    assert "</html>" in r.text[-400:], "page was cut off before it closed"
    assert not LEAK.search(r.text), "an error leaked into the page"


def test_login_form_has_the_fields_the_endpoint_reads(anon):  # [assumption] input names match POST names
    page = BeautifulSoup(anon.get("/login/").text, "html.parser")
    assert page.select_one("input[name=email]") is not None
    assert page.select_one("input[name=password]") is not None


# --- success ---------------------------------------------------------------------------------

def test_valid_login_redirects_to_the_board(app, anon, user):
    r = anon.post("/login/login.php", data={"email": user["email"], "password": user["password"]})
    assert is_redirect(r)
    assert redirect_path(app, r) == "/index.php"


def test_valid_login_sets_a_session(anon, user):
    anon.post("/login/login.php", data={"email": user["email"], "password": user["password"]})
    assert anon.cookies, "no cookie was set, so nothing could carry the session"


def test_a_signed_in_visitor_sees_their_identity(signed_in, user, project_id):
    r = signed_in.get(f"/project/?id={project_id}")
    assert r.status_code == 200
    assert user["identity"] in r.text


def test_the_session_persists_across_pages(signed_in):
    for page in ("/index.php", "/boards/", "/account/"):
        assert signed_in.get(page).status_code == 200, page


# --- failure ---------------------------------------------------------------------------------

BAD_CREDENTIALS = [
    pytest.param("ada@example.com", "wrong-password", id="wrong-password"),
    pytest.param("ada@example.com", "CORRECTHORSE1", id="password-is-case-sensitive"),
    pytest.param("nobody@example.com", "correcthorse1", id="unknown-email"),
    pytest.param("ada@example.com", "", id="empty-password"),
    pytest.param("", "correcthorse1", id="empty-email"),
    pytest.param("", "", id="both-empty"),
]


@pytest.mark.parametrize("email, password", BAD_CREDENTIALS)
def test_bad_credentials_do_not_sign_in(app, anon, user, email, password):
    r = anon.post("/login/login.php", data={"email": email, "password": password})
    assert redirect_path(app, r) != "/index.php", "bad credentials were sent to the board"
    follow_up = anon.get("/index.php")
    assert user["identity"] not in follow_up.text, "bad credentials produced a signed-in session"


def test_failures_look_the_same_for_a_wrong_password_and_an_unknown_email(app, user):  # [expectation]
    """If the two differ in status or destination, the form tells an attacker which emails exist."""
    from conftest import Client
    wrong_pw = Client(app.base).post("/login/login.php",
                                     data={"email": user["email"], "password": "wrong-password"})
    unknown = Client(app.base).post("/login/login.php",
                                    data={"email": "nobody@example.com", "password": "wrong-password"})
    assert wrong_pw.status_code == unknown.status_code
    assert redirect_path(app, wrong_pw) == redirect_path(app, unknown)


def test_credentials_in_a_query_string_do_not_sign_in(anon, user):  # [expectation] login is POST-only
    anon.get("/login/login.php", params={"email": user["email"], "password": user["password"]})
    assert user["identity"] not in anon.get("/index.php").text


# --- access control --------------------------------------------------------------------------

@pytest.mark.parametrize("page", PROTECTED_PAGES)
def test_anonymous_visitors_are_sent_to_login(app, anon, user, page):
    r = anon.get(page)
    assert is_redirect(r), f"{page} answered {r.status_code} instead of redirecting"
    assert redirect_path(app, r).startswith("/login")
    assert user["identity"] not in r.text


def test_the_redirect_remembers_where_the_visitor_was_going(anon):
    # Seen with curl: GET /index.php -> 302 Location: /login/?next=%2Findex.php
    r = anon.get("/index.php")
    query = parse_qs(urlparse(r.headers["Location"]).query)
    assert query.get("next") == ["/index.php"]


def test_an_anonymous_visitor_cannot_open_a_project(app, anon, project_id):
    r = anon.get(f"/project/?id={project_id}")
    assert is_redirect(r)
    assert redirect_path(app, r).startswith("/login")


@pytest.mark.parametrize("page", ["/login/", "/setup/"])
def test_signed_in_visitors_are_moved_off_login_and_setup(signed_in, page):
    # flows.sh: "login/ and setup/ redirect away from here" once signed in.
    assert is_redirect(signed_in.get(page))


def test_one_visitors_login_does_not_sign_in_another(app, signed_in, user):
    from conftest import Client
    stranger = Client(app.base)
    r = stranger.get("/index.php")
    assert is_redirect(r)
    assert user["identity"] not in r.text


# --- session hygiene [expectation] -----------------------------------------------------------

def test_session_id_changes_at_login(anon, user):
    """Session fixation: an id handed out before login must not become the logged-in session."""
    anon.get("/login/")
    before = anon.cookies.get_dict()
    if not before:
        pytest.skip("the login page sets no cookie, so there is no pre-login session id to compare")
    anon.post("/login/login.php", data={"email": user["email"], "password": user["password"]})
    after = anon.cookies.get_dict()
    for name, value in before.items():
        assert after.get(name) != value, f"cookie {name} survived login unchanged"


def test_session_cookie_is_httponly(app, user):
    from conftest import Client
    r = Client(app.base).post("/login/login.php",
                              data={"email": user["email"], "password": user["password"]})
    set_cookie = r.headers.get("Set-Cookie", "")
    assert set_cookie, "login set no cookie"
    assert "httponly" in set_cookie.lower()


def test_the_password_is_not_stored_in_plaintext(app, user):
    """Schema-agnostic: the plaintext must not appear anywhere in the database file or its journal."""
    blobs = [app.db_path.read_bytes()]
    for suffix in ("-wal", "-journal"):
        extra = app.db_path.with_name(app.db_path.name + suffix)
        if extra.exists():
            blobs.append(extra.read_bytes())
    assert all(user["password"].encode() not in b for b in blobs)
