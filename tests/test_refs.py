"""Automatic #NN project links in comments and descriptions. Card #45."""
from helpers import soup

HTMX = {"HX-Request": "true"}


def post_comment(signed_in, pid, body):
    return signed_in.post("/project/comment.php", headers=HTMX, data={"id": pid, "body": body})


def test_a_reference_becomes_a_link(signed_in, make_project):
    target = make_project("Link target")
    pid = make_project("Link source")
    r = post_comment(signed_in, pid, f"see #{target} for the wiring")
    link = soup(r).select_one(f'a[href="/project/?id={target}"]')
    assert link is not None
    assert link.get_text(strip=True) == f"#{target}"


def test_a_reference_to_nothing_stays_text(signed_in, make_project):
    pid = make_project("Dangling ref")
    r = post_comment(signed_in, pid, "see #999999, not yet filed")
    assert soup(r).select_one('a[href="/project/?id=999999"]') is None
    assert "#999999" in r.text


def test_a_reference_needs_a_boundary(signed_in, make_project):
    target = make_project("Boundary target")
    pid = make_project("Boundary source")
    r = post_comment(signed_in, pid, f"ABC#{target} is not one, but (#{target}) is")
    frag = soup(r)
    assert frag.select_one(f'a[href="/project/?id={target}"]') is not None
    assert "ABC#" in r.text


def test_references_inside_code_stay_text(signed_in, make_project):
    target = make_project("Code target")
    pid = make_project("Code source")
    r = post_comment(signed_in, pid, f"run `grep #{target} log` first")
    assert soup(r).select_one(f'a[href="/project/?id={target}"]') is None
    r = post_comment(signed_in, pid, f"```\nsee #{target}\n```")
    assert soup(r).select_one(f'a[href="/project/?id={target}"]') is None


def test_links_survive_escaping(signed_in, make_project):
    target = make_project("Escape target")
    pid = make_project("Escape source")
    r = post_comment(signed_in, pid, f"<b>bold</b> see #{target}")
    assert "&lt;b&gt;" in r.text
    assert soup(r).select_one(f'a[href="/project/?id={target}"]') is not None


def test_the_description_links_too(signed_in, make_project):
    target = make_project("Description link target")
    pid = make_project("Description link source", description=f"blocked on #{target}, see wiring")
    page = soup(signed_in.get(f"/project/?id={pid}"))
    desc = page.select_one("#project-description")
    assert desc is not None
    link = desc.select_one(f'a[href="/project/?id={target}"]')
    assert link is not None
    assert link.get_text(strip=True) == f"#{target}"


def test_no_description_means_no_rendered_block(signed_in, make_project):
    pid = make_project("No description")
    assert soup(signed_in.get(f"/project/?id={pid}")).select_one("#project-description") is None


# --- the description flip card: read where it is written -------------------------------------

def test_the_main_form_leaves_the_description_to_the_flip_card(signed_in, make_project):
    page = soup(signed_in.get(f"/project/?id={make_project('Flip card')}"))
    assert page.select_one("#desc-view") is not None
    assert page.select_one("#desc-edit") is not None
    # the switch flips a checkbox, not the fragment: navigating nowhere scrolls nothing
    assert page.select_one(".desc-card input#desc-toggle[type=checkbox]") is not None
    assert page.select_one('#desc-view label[for="desc-toggle"]') is not None
    assert page.select_one('#desc-edit label[for="desc-toggle"]') is not None
    form = page.select_one('#desc-edit form[action="/project/description.php"]')
    assert form is not None
    assert form.select_one("textarea[name=description]") is not None
    assert page.select_one("#pf textarea[name=description]") is None


def test_saving_the_description_redirects_to_the_page(app, signed_in, make_project):
    pid = make_project("Desc save")
    r = signed_in.post("/project/description.php", data={"id": pid, "description": "fresh words"})
    assert r.status_code in (301, 302, 303, 307, 308)
    assert r.headers["Location"].endswith(f"/project/?id={pid}")
    assert app.sql("select description from projects where id=?", pid) == [("fresh words",)]
    assert app.sql("select title from projects where id=?", pid) == [("Desc save",)]


def test_updating_the_project_keeps_an_absent_description(app, signed_in, make_project):
    pid = make_project("Desc kept", description="stays put")
    signed_in.post("/project/update.php", data={"id": pid, "title": "Desc kept renamed"})
    assert app.sql("select description from projects where id=?", pid) == [("stays put",)]


def test_updating_with_a_description_still_writes_it(app, signed_in, make_project):
    pid = make_project("Desc written", description="old")
    signed_in.post("/project/update.php", data={"id": pid, "title": "Desc written", "description": "new"})
    assert app.sql("select description from projects where id=?", pid) == [("new",)]


def test_description_endpoint_refuses_get_and_unknown_ids(signed_in, make_project):
    assert signed_in.get("/project/description.php").status_code == 405
    r = signed_in.post("/project/description.php", data={"id": 999999, "description": "x"})
    assert r.status_code == 404
