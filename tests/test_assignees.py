"""Assignees and the people picker. Ported from the old shell suite.

The markup tests (the last group) pin the contract between the server's HTML and htmx. They are the
tests most worth replacing with a browser test eventually; they are here so the port is complete.
"""
import json

import pytest

from helpers import is_hidden, is_redirect, picker_options, picker_rows, redirect_to, soup

HTMX = {"HX-Request": "true"}


@pytest.fixture
def project(make_project):
    """Assigned to Grace (2) and user 4, the state the old shell suite put the project in before the picker."""
    return make_project("Assignee test", assignees=[2, 4])


def assignees(app, pid):
    return [r[0] for r in app.sql(
        "select user_id from project_assignees where project_id=? order by user_id", pid)]


# --- changing assignees ----------------------------------------------------------------------

def test_posting_a_new_set_replaces_the_old_one(app, signed_in, make_project):
    pid = make_project("Replace test", assignees=[1, 3])
    r = signed_in.patch("/project/assign.php", data={"id": pid, "as[]": [2, 4]})
    assert is_redirect(r)
    assert redirect_to(app, r) == f"/project/?id={pid}"

    page = soup(signed_in.get(f"/project/?id={pid}"))
    assert picker_rows(page) == ["2", "4"]
    assert assignees(app, pid) == [2, 4]


# --- server-side search in the dialog --------------------------------------------------------

def search(signed_in, pid, q):
    return soup(signed_in.get("/project/assign.php", params={"id": pid, "mode": "options", "q": q}))


def test_search_matches_on_name(signed_in, project):
    options = picker_options(search(signed_in, project, "hopper"))
    assert "2" in options
    assert "1" not in options


def test_search_matches_on_email_as_the_box_promises(signed_in, project):
    assert "1" in picker_options(search(signed_in, project, "ada@example.com"))


def test_search_says_so_when_nothing_matches(signed_in, project):
    page = search(signed_in, project, "nobody")
    assert "No matches." in page.get_text()
    assert picker_options(page) == {}


# --- a pick over htmx ------------------------------------------------------------------------

def test_a_pick_saves_and_returns_only_the_rows(app, signed_in, project):
    """add=1 with no as[] replaces the set with just user 1: the form posts its current rows
    alongside add, so a bare add means 'the set is empty, plus this person'."""
    r = signed_in.patch("/project/assign.php", headers=HTMX, data={"id": project, "add": 1})
    frag = soup(r)

    assert picker_rows(frag) == ["1"]
    assert assignees(app, project) == [1], "the rest should be replaced, not added to"


def test_the_fragments_buttons_patch_an_absolute_path(signed_in, project):
    """Pages live at different depths, so a bare assign.php would resolve to /assign.php and 404.
    The page and the fragment are rendered by different files, so the page alone proves nothing."""
    frag = soup(signed_in.patch("/project/assign.php", headers=HTMX, data={"id": project, "add": 1}))
    patches = [el["hx-patch"] for el in frag.select("[hx-patch]")]
    assert patches, "no buttons in the fragment"
    assert set(patches) == {"/project/assign.php"}


def test_the_fragment_is_not_page_furniture(signed_in, project):
    """A form or a second dialog in the swap would duplicate ids on the page."""
    frag = soup(signed_in.patch("/project/assign.php", headers=HTMX, data={"id": project, "add": 1}))
    assert frag.find("form") is None
    assert not frag.select(".modal")


def test_the_dialogs_list_is_refreshed_out_of_band(signed_in, project):
    frag = soup(signed_in.patch("/project/assign.php", headers=HTMX, data={"id": project, "add": 1}))
    oob = frag.select_one("#picker-people-options")
    assert oob is not None and oob.get("hx-swap-oob") == "true"
    picked = picker_options(oob)["1"]
    assert is_hidden(picked), "the person just picked should no longer be offered"


def test_remove_drops_one_and_keeps_the_rest(app, signed_in, project):
    # [assumption] mirrors project_boards.php, where boards[]=a&boards[]=b&remove=b keeps a.
    signed_in.patch("/project/assign.php", headers=HTMX,
                   data={"id": project, "as[]": [2, 4], "remove": 2})
    assert assignees(app, project) == [4]


# --- markup contract of the picker ------------------------------------------------------------

@pytest.fixture
def page(signed_in, project):
    return soup(signed_in.get(f"/project/?id={project}"))


def hx_vals(page):
    return [json.loads(el["hx-vals"]) for el in page.select("[hx-vals]")]


def test_each_row_carries_its_field_and_can_be_removed(page):
    row = page.select_one('[data-picker-row][data-value="2"]')
    assert row.select_one('input[name="as[]"][value="2"]') is not None
    assert page.select_one('[aria-label="Remove Grace Hopper"]') is not None, \
        "the x should name what it removes"
    assert {"remove": 2} in hx_vals(page)


def test_the_dialog_offers_those_not_yet_chosen(page):
    # Scoped to the people picker: the page carries a second picker for boards, whose ids are 1 and
    # 2 and so collide with the first two people. Unscoped, the board named "Lab Hardware" would
    # answer for Grace Hopper.
    options = picker_options(page, "#picker-people-options")
    assert not is_hidden(options["1"]) and not is_hidden(options["3"])
    assert is_hidden(options["2"]) and is_hidden(options["4"])  # [assumption] same as the board picker
    assert {"add": 1} in hx_vals(page)
    assert page.select_one('[hx-target="#people-form"]') is not None


def test_the_dialog_is_opened_by_an_add_button_and_searches_on_the_server(page):
    assert page.select_one('[data-bs-toggle="modal"][data-bs-target="#picker-people-modal"]')
    search_box = page.select_one("#picker-people-search")
    assert search_box is not None
    assert search_box.get("hx-get") == "/project/assign.php"
    assert any("shown.bs.modal from:#picker-people-modal" in el["hx-trigger"]
               for el in page.select("[hx-trigger]")), "dialog should re-ask when it opens"


def test_no_checkbox_list_and_no_noscript_scaffolding_left_behind(page):
    # The pickers moved off checkbox lists; the description flip card's toggle is the one
    # checkbox allowed on the page, and it is a switch, not a list.
    assert [el.get("id") for el in page.select('input[type="checkbox"]')] == ["desc-toggle"]
    assert page.find("noscript") is None
