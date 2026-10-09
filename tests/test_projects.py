"""Projects: create, render, update, change status. Ported from flows.sh.

Each test makes its own project (see make_project in conftest.py).
"""
import pytest

from helpers import ADA_ID, is_redirect, redirect_to, soup, text_of

FIELDS = dict(title="Flow Test", description="Body & <b>text</b>", status="todo", type="bug",
              start_date="2026-10-01", due_date="2026-12-01")


@pytest.fixture
def project(make_project):
    return make_project(**FIELDS, assignees=[1, 3])


@pytest.fixture
def response(signed_in, project):
    return signed_in.get(f"/project/?id={project}")


@pytest.fixture
def page(response):
    return soup(response)


# --- create ----------------------------------------------------------------------------------

def test_create_redirects_to_the_new_project_and_records_the_creator(app, signed_in):
    r = signed_in.post("/new/add.php", data={**FIELDS, "as[]": [1, 3]})
    assert is_redirect(r)
    pid = int(r.headers["Location"].rsplit("id=", 1)[1])
    assert redirect_to(app, r) == f"/project/?id={pid}"
    assert app.sql("select creator_id from projects where id=?", pid) == [(ADA_ID,)]
    assert app.sql("select user_id from project_assignees where project_id=? order by user_id", pid) \
        == [(1,), (3,)]


# --- the saved values render ------------------------------------------------------------------

def test_page_shows_title_and_reference_number(page, project):
    assert "Flow Test" in page.select_one("h1").get_text()
    assert page.select_one("h1 span.ref").get_text() == f"#{project}"


def test_page_shows_dates(page):
    assert page.select_one('input[value="2026-10-01"]') is not None
    assert page.select_one('input[value="2026-12-01"]') is not None


def test_description_is_escaped_not_rendered(response, page):
    assert "Body &amp; &lt;b&gt;text&lt;/b&gt;" in response.text
    assert "<b>text</b>" not in response.text


def test_bug_type_is_badged_as_danger(page):  # coupled to Bootstrap class names, as in flows.sh
    assert page.select(".bg-danger-subtle.text-danger-emphasis")


def test_new_project_starts_with_history_and_no_comments_or_switcher(page, user):
    text = text_of(page)
    assert "No comments yet." in text
    assert "Created in To do" in text
    assert "Acting as" not in text, "the identity switcher is supposed to be gone"
    assert user["identity"] in text


def test_only_chosen_assignees_are_rows(page):
    values = [el["data-value"] for el in page.select("[data-picker-row]")]
    assert sorted(values) == ["1", "3"]


# --- update ----------------------------------------------------------------------------------

def test_update_changes_title_status_and_type(app, signed_in, project):
    r = signed_in.post("/project/update.php", data={
        "id": project, "title": "Flow Test Renamed", "status": "review", "type": "research"})
    assert redirect_to(app, r) == f"/project/?id={project}"

    page = soup(signed_in.get(f"/project/?id={project}"))
    assert "Flow Test Renamed" in page.select_one("h1").get_text()
    assert "To do to In review" in text_of(page), "the status change was not logged"
    assert page.select(".text-info-emphasis"), "type badge did not change"
    assert app.sql("select status from projects where id=?", project) == [("review",)]


# --- the status dropdown ---------------------------------------------------------------------

def test_status_dropdown_follows_the_order_work_moves_through(signed_in, make_project, panel_order):
    pid = make_project("Order test", status="review")
    page = soup(signed_in.get(f"/project/?id={pid}"))
    values = [o["value"] for o in page.select("select[name=status] option")]
    assert values == panel_order


def test_both_review_stages_come_before_the_states_they_end_in(signed_in, make_project):
    """The regression: review_ready was appended last and landed below Cancelled. Only this part of
    the order is pinned; the rest is a board-layout decision that should be free to change."""
    pid = make_project("Order test")
    page = soup(signed_in.get(f"/project/?id={pid}"))
    wanted = {"review_ready", "review", "done", "cancelled"}
    values = [o["value"] for o in page.select("select[name=status] option") if o["value"] in wanted]
    assert values == ["review_ready", "review", "done", "cancelled"]


def test_the_projects_own_status_is_selected(signed_in, make_project):
    pid = make_project("Selected test", status="review")
    page = soup(signed_in.get(f"/project/?id={pid}"))
    assert page.select_one("select[name=status] option[selected]")["value"] == "review"


# --- PATCH status (what a drag or the dropdown sends) ----------------------------------------

def test_patch_status_echoes_the_payload_and_persists(app, signed_in, make_project):
    pid = make_project("Patch test")
    r = signed_in.patch("/project/update_status.php", data={"id": pid, "status": "in_progress"})
    assert r.json() == {"id": str(pid), "status": "in_progress"}
    assert app.sql("select status from projects where id=?", pid) == [("in_progress",)]


# --- status refresh over htmx (card #42) -----------------------------------------------------

HTMX = {"HX-Request": "true"}


def test_status_change_over_htmx_returns_the_history_list(app, signed_in, make_project):
    pid = make_project("Refresh test")
    r = signed_in.patch("/project/update_status.php", headers=HTMX,
                        data={"id": pid, "status": "in_progress"})
    assert r.status_code == 200
    frag = soup(r)
    assert frag.select_one("#status-history-list") is not None
    assert "In progress" in frag.get_text()
    # the "Last updated" line rides along out-of-band, so the page stops claiming otherwise
    oob = frag.select_one('#last-updated[hx-swap-oob="true"]')
    assert oob is not None
    assert "Last updated" in oob.get_text()


def test_the_dropdown_targets_the_history_list(signed_in, make_project):
    page = soup(signed_in.get(f"/project/?id={make_project('Target test')}"))
    sel = page.select_one("select[name=status]")
    assert sel.get("hx-target") == "#status-history-list"
    assert sel.get("hx-swap") == "outerHTML"
    assert page.select_one("#status-history-list") is not None
    assert page.select_one("#last-updated") is not None


# --- status history records who (card #33) ---------------------------------------------------

def test_status_moves_record_the_actor(app, signed_in, make_project):
    pid = make_project("Actor test")
    signed_in.patch("/project/update_status.php", data={"id": pid, "status": "in_progress"})
    assert app.sql("select changed_by from status_log where project_id=? order by id desc limit 1",
                   pid) == [(ADA_ID,)]


def test_the_history_names_the_actor(signed_in, make_project):
    pid = make_project("Actor shown test")
    signed_in.patch("/project/update_status.php", data={"id": pid, "status": "in_progress"})
    text = text_of(soup(signed_in.get(f"/project/?id={pid}")))
    assert "by Ada Lovelace" in text


# --- search shortcut (card #41) --------------------------------------------------------------

def test_the_search_box_carries_an_id_for_the_shortcut(signed_in, make_project):
    page = soup(signed_in.get(f"/project/?id={make_project('Shortcut test')}"))
    assert page.select_one('.search-wrap input[type="search"]#site-search') is not None
