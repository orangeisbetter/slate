"""The admin area: who may reach it, and the project statuses it administers.

The statuses used to be constants in src/lib.php; they are rows in project_statuses now, created,
renamed, reordered and deleted at /admin/statuses/. These tests pin what an admin can do to them
and what that does to the rest of the app.
"""
from urllib.parse import unquote

import itertools
import pytest
import sqlite3

from helpers import is_redirect, redirect_to, soup, text_of


# --- helpers ------------------------------------------------------------------------------------

@pytest.fixture(autouse=True)
def statuses_snapshot(app):
    """One server for the whole session, so a status created here would still be there for the next
    module — and would show up in the dropdown assertions everywhere else. The table is snapshotted
    and put back after each test, which is the only isolation on offer when the writes happen over
    HTTP in another process."""
    cols = "slug,label,tone,sort_order,is_finished"
    before = app.sql(f"select {cols} from project_statuses order by id")
    yield
    con = sqlite3.connect(app.db_path)
    try:
        con.execute("delete from project_statuses")
        con.executemany(f"insert into project_statuses({cols}) values(?,?,?,?,?)", before)
        con.commit()
    finally:
        con.close()


def make_status(signed_in, **fields):
    r = signed_in.post("/admin/statuses/save.php", data=fields)
    assert is_redirect(r), f"save failed: {r.status_code} {r.text[:200]}"
    return r


def id_of(app, slug):
    return app.sql("select id from project_statuses where slug=?", slug)[0][0]


def order_of(app):
    return [r[0] for r in app.sql("select slug from project_statuses order by sort_order, id")]


def new_project(signed_in, title, **fields):
    r = signed_in.post("/new/add.php", data={"title": title, **fields})
    assert is_redirect(r), f"create failed: {r.status_code} {r.text[:200]}"
    return int(r.headers["Location"].rsplit("id=", 1)[1])


# --- who may reach it ---------------------------------------------------------------------------

def test_an_anonymous_visitor_is_sent_to_sign_in(anon):
    for path in ("/admin/", "/admin/statuses/", "/admin/users/"):
        r = anon.get(path)
        assert r.status_code == 302, f"{path} should redirect, got {r.status_code}"


def test_a_member_is_refused_everywhere_under_admin(member):
    for path in ("/admin/", "/admin/statuses/", "/admin/users/",
                 "/admin/statuses/form.php", "/admin/statuses/form-dialog.php"):
        assert member.get(path).status_code == 403, f"{path} should be admins only"

    assert member.post("/admin/statuses/save.php", data={"label": "Nope"}).status_code == 403
    assert member.delete("/admin/statuses/delete.php", data={"id": 1}).status_code == 403
    assert member.patch("/admin/statuses/move.php", data={"id": 1, "dir": "up"}).status_code == 403


def test_the_old_users_page_is_gone(signed_in, member):
    """The file is gone, and with it the page. /users/ itself cannot 404: the PHP server falls back
    to the front controller for any path with no file behind it, so the old URL lands on the
    dashboard instead — which is not a users page either, for anyone."""
    assert signed_in.get("/users/index.php").status_code == 404, "the page itself is gone"
    for client in (signed_in, member):
        page = client.get("/users/")
        assert page.status_code == 200
        assert "here is where things stand" in page.text, "/users/ serves the dashboard now"
        assert "Invite" not in page.text


def test_the_navbar_offers_admin_only_to_an_admin(signed_in, member):
    assert 'href="/admin/"' in signed_in.get("/admin/").text
    assert 'href="/admin/"' not in member.get("/index.php").text


def test_a_members_name_is_text_without_a_link_to_the_admin_list(app, signed_in, member):
    """user_link() points an admin at /admin/users/#user-N and gives anyone else the name as
    plain text: a href a member cannot follow is worse than no href at all."""
    mid = app.sql("select id from users where email='member@example.com'")[0][0]
    pid = new_project(signed_in, "Named test", **{f"as[]": [mid]})

    page = soup(signed_in.get(f"/project/?id={pid}"))
    assert page.select_one(f'a.link-quiet[href="/admin/users/#user-{mid}"]'), "an admin gets the link"

    page = soup(member.get(f"/project/?id={pid}"))
    assert page.select_one(f'a[href*="user-{mid}"]') is None, "a member gets no dead link"
    assert "Member Person" in text_of(page)


def test_the_admin_hub_links_to_both_pages(signed_in):
    page = soup(signed_in.get("/admin/"))
    assert page.select_one('a[href="/admin/statuses/"]')
    assert page.select_one('a[href="/admin/users/"]')


# --- the statuses page --------------------------------------------------------------------------

def test_a_fresh_database_has_the_nine_shipped_statuses(panel_order):
    """What migration 008 seeds, in the order the app used to hold PANEL_ORDER in. Deleting one is
    a legal admin action afterwards; this is about what a new install starts with."""
    assert panel_order == ["draft", "backlog", "todo", "blocked", "in_progress",
                           "review_ready", "review", "done", "cancelled"]


def test_the_statuses_page_lists_every_status(app, signed_in, panel_order):
    page = soup(signed_in.get("/admin/statuses/"))
    assert len(page.select("tbody tr")) == len(panel_order)
    text = text_of(page)
    for slug in panel_order:
        assert slug in text, f"{slug} should be listed by its slug"


def test_the_dropdown_offers_exactly_the_statuses_in_order(signed_in, panel_order):
    pid = new_project(signed_in, "Admin order test")
    page = soup(signed_in.get(f"/project/?id={pid}"))
    assert [o["value"] for o in page.select("select[name=status] option")] == panel_order


# --- creating -----------------------------------------------------------------------------------

def test_a_new_status_is_created_with_a_slug_derived_from_its_label(app, signed_in):
    before = app.sql("select count(*) from project_statuses")

    make_status(signed_in, label="Waiting on QA", tone="info")

    assert app.sql("select slug, label, tone, is_finished from project_statuses where slug='waiting_on_qa'") \
        == [("waiting_on_qa", "Waiting on QA", "info", 0)]
    assert app.sql("select count(*) from project_statuses") == [(before[0][0] + 1,)]


def test_a_status_label_is_escaped_on_every_surface(app, signed_in):
    """The label is admin-written text and renders as itself, not as markup — on every surface at
    once, because escaping lives in status_label() rather than in whichever call site remembers.
    The & also proves nothing escapes twice: that would read back as &amp;amp;."""
    make_status(signed_in, label="<b>R&D</b>", slug="escaped_label", tone="info")

    pid = new_project(signed_in, "Escape probe")
    project_page = signed_in.get(f"/project/?id={pid}").text
    assert "&lt;b&gt;R&amp;D&lt;/b&gt;" in project_page
    assert "<b>R&D</b>" not in project_page
    assert "&amp;amp;" not in project_page, "the label was escaped twice"

    list_page = signed_in.get("/list/").text
    assert "&lt;b&gt;R&amp;D&lt;/b&gt;" in list_page
    assert "<b>R&D</b>" not in list_page


def test_a_new_status_is_offered_everywhere_statuses_are(app, signed_in, panel_order):
    make_status(signed_in, label="Waiting on QA", tone="info")

    pid = new_project(signed_in, "Placement test")
    page = soup(signed_in.get(f"/project/?id={pid}"))
    assert [o["value"] for o in page.select("select[name=status] option")] == panel_order + ["waiting_on_qa"], \
        "the dropdown takes it, at the end"

    board = app.sql("select id from boards order by id limit 1")[0][0]
    dialog = signed_in.get(f"/boards/panels-dialog.php?id={board}").text
    assert 'name="panels[waiting_on_qa]"' in dialog, "the Panels dialog offers it too"


def test_an_explicit_slug_is_honoured(app, signed_in):
    make_status(signed_in, label="Parked", slug="parked", tone="secondary")
    assert app.sql("select label from project_statuses where slug='parked'") == [("Parked",)]


def test_a_duplicate_slug_is_refused(app, signed_in):
    before = app.sql("select count(*) from project_statuses")
    r = signed_in.post("/admin/statuses/save.php", data={"label": "Todo again", "slug": "todo"})
    assert "error=" in redirect_to(app, r)
    assert app.sql("select count(*) from project_statuses") == before


def test_an_unknown_tone_is_refused(app, signed_in):
    before = app.sql("select count(*) from project_statuses")
    r = signed_in.post("/admin/statuses/save.php", data={"label": "Loud", "tone": "chartreuse"})
    assert "error=" in redirect_to(app, r)
    assert app.sql("select count(*) from project_statuses") == before


def test_an_empty_label_is_refused(app, signed_in):
    before = app.sql("select count(*) from project_statuses")
    r = signed_in.post("/admin/statuses/save.php", data={"label": "   "})
    assert "error=" in redirect_to(app, r)
    assert app.sql("select count(*) from project_statuses") == before


def test_save_is_post_only(signed_in):
    assert signed_in.get("/admin/statuses/save.php").status_code == 405


# --- editing ------------------------------------------------------------------------------------

def test_renaming_changes_the_label_and_leaves_the_slug_alone(app, signed_in):
    make_status(signed_in, label="Waiting", slug="waiting", tone="info")
    sid = id_of(app, "waiting")

    make_status(signed_in, id=sid, label="On hold", tone="warning", is_finished="1")

    assert app.sql("select slug, label, tone, is_finished from project_statuses where id=?", sid) \
        == [("waiting", "On hold", "warning", 1)]


def test_the_edit_form_shows_the_slug_as_text_not_a_field(app, signed_in):
    make_status(signed_in, label="Waiting", slug="waiting")
    page = soup(signed_in.get(f"/admin/statuses/form.php?id={id_of(app, 'waiting')}"))
    assert page.select_one('input[name="slug"]') is None, "the slug is not editable after creation"
    assert "waiting" in text_of(page)


def test_the_new_status_form_offers_the_slug_field(app, signed_in):
    page = soup(signed_in.get("/admin/statuses/form.php"))
    assert page.select_one('input[name="slug"]') is not None


def test_a_finished_status_leaves_the_list_views_default_filter(app, signed_in):
    """Which statuses count as finished is the admin's call: the list opens on the rest."""
    make_status(signed_in, id=id_of(app, "blocked"), label="Blocked", tone="danger", is_finished="1")

    page = soup(signed_in.get("/list/"))
    assert not page.select_one('option[value="blocked"]').has_attr("selected")
    assert page.select_one('option[value="todo"]').has_attr("selected")


def test_the_finished_statuses_leave_the_dashboards_open_count(app, signed_in):
    def open_count():
        for card in soup(signed_in.get("/")).select(".card"):
            small = card.select_one(".text-body-secondary")
            if small and small.get_text(strip=True) == "Open":
                return int(card.select_one(".fs-3").get_text(strip=True))
        raise AssertionError("no Open tile")

    before = open_count()
    blocked = app.sql("select count(*) from projects where status='blocked'")[0][0]
    make_status(signed_in, id=id_of(app, "blocked"), label="Blocked", tone="danger", is_finished="1")
    assert open_count() == before - blocked


# --- reordering ---------------------------------------------------------------------------------

def test_up_and_down_swap_neighbours(app, signed_in, panel_order):
    todo = id_of(app, "todo")

    signed_in.patch("/admin/statuses/move.php", data={"id": todo, "dir": "up"})
    assert order_of(app) == ["draft", "todo", "backlog"] + panel_order[3:]

    signed_in.patch("/admin/statuses/move.php", data={"id": todo, "dir": "down"})
    assert order_of(app) == panel_order


def test_moving_the_first_one_up_changes_nothing(app, signed_in, panel_order):
    signed_in.patch("/admin/statuses/move.php", data={"id": id_of(app, panel_order[0]), "dir": "up"})
    assert order_of(app) == panel_order


def test_move_is_post_only_and_takes_a_direction(signed_in):
    assert signed_in.get("/admin/statuses/move.php").status_code == 405
    assert signed_in.patch("/admin/statuses/move.php", data={"id": 1, "dir": "sideways"}).status_code == 400


# --- deleting -----------------------------------------------------------------------------------

_SLUGS = itertools.count(1)


@pytest.fixture
def scratch(signed_in):
    """A status of this test's own, under a slug nothing else has used this session.

    The statuses table is restored after every test, but projects are not: one a test left behind
    still sits on its status, and a delete is refused while anything does. Reusing the slug would
    let an early test's leftovers decide a later test's outcome, so the slug is unique per call.
    The label stays "Scratch" — it is what the refusal messages name."""
    slug = f"scratch{next(_SLUGS)}"
    make_status(signed_in, label="Scratch", slug=slug)
    return slug


def test_a_status_with_nothing_on_it_is_deleted(app, signed_in, scratch):
    sid = id_of(app, scratch)
    board = app.sql("select id from boards order by id limit 1")[0][0]
    signed_in.put("/boards/save_panels.php", data={"id": board, f"panels[{scratch}]": "required"})

    r = signed_in.delete("/admin/statuses/delete.php", data={"id": sid})

    assert redirect_to(app, r) == "/admin/statuses/"
    assert app.sql("select count(*) from project_statuses where id=?", sid) == [(0,)]
    assert app.sql("select count(*) from board_panels where status=?", scratch) == [(0,)], \
        "its panel rows go with it"


def test_a_status_with_projects_on_it_is_refused(app, signed_in, make_project, scratch):
    sid = id_of(app, scratch)
    pid = make_project("On scratch", status=scratch)

    r = signed_in.delete("/admin/statuses/delete.php", data={"id": sid})

    assert "still on Scratch" in unquote(r.headers["Location"])
    assert app.sql("select count(*) from project_statuses where id=?", sid) == [(1,)], "the status stays"
    assert app.sql("select status from projects where id=?", pid) == [(scratch,)], "the project stays"


def test_the_refusal_says_how_many(app, signed_in, make_project, scratch):
    sid = id_of(app, scratch)
    make_project("One", status=scratch)
    make_project("Two", status=scratch)

    r = signed_in.delete("/admin/statuses/delete.php", data={"id": sid})
    assert "2 projects are still on Scratch" in unquote(r.headers["Location"])


def test_once_the_last_project_has_moved_off_it_can_be_deleted(app, signed_in, make_project, scratch):
    sid = id_of(app, scratch)
    pid = make_project("Leaving", status=scratch)

    signed_in.patch("/project/update_status.php", data={"id": pid, "status": "todo"})
    r = signed_in.delete("/admin/statuses/delete.php", data={"id": sid})

    assert redirect_to(app, r) == "/admin/statuses/"
    assert app.sql("select count(*) from project_statuses where id=?", sid) == [(0,)]


def test_deleting_a_status_keeps_the_history_that_names_it(app, signed_in, make_project, scratch):
    """status_log is a record of what things were called at the time, so it is not touched by a
    delete. The entry still renders, with the slug standing in for the label that is gone."""
    pid = make_project("Historical", status=scratch)
    signed_in.patch("/project/update_status.php", data={"id": pid, "status": "todo"})
    signed_in.delete("/admin/statuses/delete.php", data={"id": id_of(app, scratch)})

    page = signed_in.get(f"/project/?id={pid}")
    assert page.status_code == 200
    assert f"{scratch} to To do" in text_of(soup(page)), "the history entry renders with its slug"


def test_delete_is_post_only(signed_in):
    assert signed_in.get("/admin/statuses/delete.php").status_code == 405


def test_an_unknown_status_is_404(signed_in):
    assert signed_in.delete("/admin/statuses/delete.php", data={"id": 99999}).status_code == 404
    assert signed_in.patch("/admin/statuses/move.php", data={"id": 99999, "dir": "up"}).status_code == 404


# --- a deleted status cannot be set again --------------------------------------------------------

def test_a_project_cannot_be_moved_onto_a_status_that_is_gone(app, signed_in, make_project, scratch):
    pid = make_project("Move test", status=scratch)
    signed_in.patch("/project/update_status.php", data={"id": pid, "status": "todo"})
    signed_in.delete("/admin/statuses/delete.php", data={"id": id_of(app, scratch)})

    signed_in.patch("/project/update_status.php", data={"id": pid, "status": scratch})
    assert app.sql("select status from projects where id=?", pid) == [("todo",)], "the PATCH is ignored"

    r = signed_in.post("/new/add.php", data={"title": "Bad status", "status": scratch})
    assert r.status_code == 400 and "Invalid status" in r.text
