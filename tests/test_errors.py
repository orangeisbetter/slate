"""The error page (card #54).

One page serves every failure. A request that is not an htmx fragment gets it -- with the status
code and the reason -- and an htmx fragment keeps the plain-text sentence the toast reads instead,
because htmx will not swap a non-2xx. The unknown-path 404 is the web server's to serve, so only
the script it points at is exercised here.
"""


def test_an_unknown_project_is_an_error_page(signed_in):
    r = signed_in.get("/project/?id=999999")
    assert r.status_code == 404
    assert "Project not found" in r.text
    assert "</html>" in r.text, "the error page should be a complete document"


def test_an_unknown_board_is_an_error_page(signed_in):
    r = signed_in.get("/board/?id=999999")
    assert r.status_code == 404
    assert "Board not found" in r.text
    assert "</html>" in r.text


def test_a_member_is_refused_with_a_page(member):
    r = member.get("/admin/")
    assert r.status_code == 403
    assert "Admins only" in r.text
    assert "</html>" in r.text


def test_the_404_script_answers_a_direct_request(anon):
    """What the web server points ErrorDocument/error_page at. It answers on its own, signed out."""
    r = anon.get("/404.php")
    assert r.status_code == 404
    assert "does not exist" in r.text
    assert "</html>" in r.text


def test_a_signed_out_error_leaks_no_navigation(anon):
    """The error page is standalone: no navbar, so no board names to an anonymous request."""
    r = anon.get("/login/login.php")            # a GET on a POST-only endpoint
    assert r.status_code == 405
    assert "POST only" in r.text
    assert 'href="/boards/"' not in r.text and "Boards" not in r.text


def test_an_htmx_refusal_stays_plain_text(signed_in):
    """The toast path: non-2xx, plain text, no page to swap in."""
    r = signed_in.patch("/project/update_status.php", data={"id": 999999, "status": "todo"},
                        headers={"HX-Request": "true"})
    assert r.status_code == 404
    assert r.text.strip() == "Project not found"
    assert "<html>" not in r.text


def test_a_board_drag_of_an_unknown_project_is_refused(signed_in):
    """Card #58: a drag answers a bad id the way the status dropdown does, not by silently
    re-rendering the board as though the move had happened."""
    r = signed_in.post("/board/move.php", data={"id": 999999, "status": "todo", "board": "0"},
                       headers={"HX-Request": "true"})
    assert r.status_code == 404
    assert r.text.strip() == "Project not found"
    assert "<html>" not in r.text
