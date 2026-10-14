"""Archiving projects (card #36).

Archiving is the only way a project leaves the screens, and it is one-way: there is no un-archive
and no delete. An archived project disappears from the dashboard, every board, the list's default
filter and search, while its row and everything attached to it stay where they are. The archived
view and the project's own page are where it is read afterwards, and neither offers a way back.

One server for the whole session, so the fixture puts the project back to unarchived when a test is
done: a project left archived would decide a later module's view. The title is unique per fixture
call, because projects from other tests stay in the database and a fixed title would match theirs.
"""
import itertools
import sqlite3

import pytest

from helpers import is_redirect, soup, text_of

HTMX = {"HX-Request": "true"}

_counter = itertools.count(1)


def _unarchive(app, pid):
    """Put a row back to unarchived directly, with its own committed connection.

    Undoing it here is a test affordance, not a feature -- the app has no way back. App.sql() does
    not commit writes, so this opens its own connection.
    """
    con = sqlite3.connect(app.db_path)
    try:
        con.execute("update projects set archived=0, archived_at=NULL where id=?", (pid,))
        con.commit()
    finally:
        con.close()


@pytest.fixture
def subject(app, signed_in, make_project):
    """A project of this test's own, assigned to Ada so it would show on the dashboard, archived.

    Teardown unarchives it: a project left archived would decide a later module's view. The title is
    unique per fixture call, because projects from other tests stay in the database and a fixed
    title would match theirs.
    """
    title = f"Archive subject {next(_counter)}"
    pid = make_project(title, assignees=[1])
    r = signed_in.post("/project/archive.php", data={"id": pid})
    assert is_redirect(r)
    yield title, pid
    _unarchive(app, pid)


@pytest.fixture
def archivable(app, signed_in, make_project, request):
    """A fresh project of this test's own that the test archives itself.

    For the immutability checks, which need to build up state (a comment, say) before archiving.
    Teardown unarchives it, so an archived row does not decide a later module's view.
    """
    title = f"Mutable subject {next(_counter)}"
    pid = make_project(title, assignees=[1])
    request.addfinalizer(lambda: _unarchive(app, pid))
    return title, pid


def text(signed_in, path, **params):
    return text_of(soup(signed_in.get(path, params=params)))


# --- what archiving does ------------------------------------------------------------------------

def test_archiving_keeps_the_project_with_its_history(app, signed_in, subject):
    title, pid = subject
    assert app.sql("select archived from projects where id=?", pid) == [(1,)], \
        "the flag is set rather than the row removed"
    assert signed_in.get(f"/project/?id={pid}").status_code == 200, "the page still opens"


def test_archiving_records_when_it_happened(app, subject):
    title, pid = subject
    (stamp,) = app.sql("select archived_at from projects where id=?", pid)[0]
    assert stamp, "the one-way step is dated, since nothing else records when it happened"


def test_archived_project_leaves_the_dashboard(app, signed_in, subject):
    title, _ = subject
    assert title not in text(signed_in, "/")


def test_archived_project_leaves_the_list_default(app, signed_in, subject):
    title, _ = subject
    assert title not in text(signed_in, "/list/")


def test_archived_project_leaves_a_board_it_was_on(app, signed_in, make_project, request):
    board = app.sql("select id from boards order by id limit 1")[0][0]
    pid = make_project("On a board", boards=[board])
    request.addfinalizer(lambda: _unarchive(app, pid))

    assert "On a board" in text(signed_in, f"/board/?id={board}")
    signed_in.post("/project/archive.php", data={"id": pid})
    assert "On a board" not in text(signed_in, f"/board/?id={board}")


def test_archived_project_leaves_search(app, signed_in, subject):
    title, pid = subject
    hits = soup(signed_in.get("/search.php", params={"q": title}))
    ids = {int(a["href"].rsplit("id=", 1)[1]) for a in hits.select('a[href^="/project/?id="]')}
    assert pid not in ids


def test_archiving_does_not_touch_the_assignees_or_history(app, signed_in, subject):
    title, pid = subject
    before = app.sql("select count(*) from project_assignees where project_id=?", pid)
    assert before == [(1,)], "made with an assignee, and it is still there"
    assert app.sql("select count(*) from status_log where project_id=?", pid) != [(0,)], \
        "the history is intact"


# --- there is no way back -----------------------------------------------------------------------

def test_archiving_is_one_way(app, signed_in, subject):
    title, pid = subject
    page = soup(signed_in.get(f"/project/?id={pid}"))
    assert page.select_one('form[action="/project/archive.php"]') is None, "no control to undo it"
    assert "cannot be restored" in text_of(page), "the page says so"


def test_posting_the_old_unarchive_state_does_not_bring_it_back(app, signed_in, subject):
    title, pid = subject
    signed_in.post("/project/archive.php", data={"id": pid, "archived": "0"})
    assert app.sql("select archived from projects where id=?", pid) == [(1,)]
    assert title not in text(signed_in, "/list/")


def test_re_archiving_does_not_move_the_date(app, signed_in, subject):
    title, pid = subject
    (before,) = app.sql("select archived_at from projects where id=?", pid)[0]
    signed_in.post("/project/archive.php", data={"id": pid})
    assert app.sql("select archived_at from projects where id=?", pid) == [(before,)]


def test_the_archived_view_lists_it_without_a_restore(app, signed_in, subject):
    title, _ = subject
    page = soup(signed_in.get("/list/", params={"archived": "1"}))
    assert title in text_of(page)
    assert "Archived" in text_of(page)
    assert not page.select('form[action="/project/archive.php"]'), "no restore control of any kind"


# --- there is no delete -------------------------------------------------------------------------

def test_the_delete_route_is_gone(signed_in, make_project):
    pid = make_project("No longer deletable")
    assert signed_in.post("/project/delete.php", data={"id": pid}).status_code == 404
    assert signed_in.get("/project/delete.php").status_code == 404


def test_the_page_offers_only_archive(app, signed_in, make_project):
    pid = make_project("Only archive")
    page = soup(signed_in.get(f"/project/?id={pid}"))
    assert page.select_one('form[action="/project/archive.php"]') is not None
    assert page.select_one('form[action="/project/delete.php"]') is None, "delete is not an option"


# --- who may do it ------------------------------------------------------------------------------

def test_a_member_may_not_archive(app, member):
    pid = app.sql("select id from projects order by id limit 1")[0][0]
    r = member.post("/project/archive.php", data={"id": pid})
    assert r.status_code == 403
    assert app.sql("select archived from projects where id=?", pid) == [(0,)]


def test_an_anonymous_visitor_is_sent_to_sign_in(anon):
    r = anon.post("/project/archive.php", data={"id": 1})
    assert r.status_code == 302


def test_archive_is_post_only(signed_in):
    assert signed_in.get("/project/archive.php").status_code == 405


def test_an_unknown_project_is_404(signed_in):
    assert signed_in.post("/project/archive.php", data={"id": 999999}).status_code == 404


# --- an archived project is read-only -----------------------------------------------------------

def test_the_status_cannot_change(app, signed_in, archivable):
    title, pid = archivable
    was = app.sql("select status from projects where id=?", pid)[0][0]
    signed_in.post("/project/archive.php", data={"id": pid})

    assert signed_in.patch("/project/update_status.php",
                           data={"id": pid, "status": "done"}).status_code == 409
    assert app.sql("select status from projects where id=?", pid) == [(was,)]


def test_a_board_drag_cannot_change_the_status(app, signed_in, archivable):
    title, pid = archivable
    was = app.sql("select status from projects where id=?", pid)[0][0]
    signed_in.post("/project/archive.php", data={"id": pid})

    r = signed_in.post("/board/move.php", data={"id": pid, "status": "done", "board": "0"})
    assert r.status_code == 409
    assert app.sql("select status from projects where id=?", pid) == [(was,)]


def test_the_description_cannot_change(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/archive.php", data={"id": pid})

    r = signed_in.post("/project/description.php", data={"id": pid, "description": "changed"})
    assert r.status_code == 409
    assert app.sql("select description from projects where id=?", pid) != [("changed",)]


def test_the_fields_cannot_change(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/archive.php", data={"id": pid})

    r = signed_in.post("/project/update.php", data={"id": pid, "title": "Renamed"})
    assert r.status_code == 409
    assert app.sql("select title from projects where id=?", pid) == [(title,)]


def test_the_assignees_cannot_change(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/archive.php", data={"id": pid})
    before = app.sql("select user_id from project_assignees where project_id=? order by user_id", pid)

    assert signed_in.post("/project/assign.php", data={"id": pid, "add": 2}).status_code == 409
    after = app.sql("select user_id from project_assignees where project_id=? order by user_id", pid)
    assert after == before


def test_the_boards_cannot_change(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/archive.php", data={"id": pid})
    before = app.sql("select board_id from project_boards where project_id=? order by board_id", pid)

    assert signed_in.post("/project/project_boards.php", data={"id": pid, "add": 1}).status_code == 409
    after = app.sql("select board_id from project_boards where project_id=? order by board_id", pid)
    assert after == before


def test_no_new_subproject_under_an_archived_parent(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/archive.php", data={"id": pid})
    before = app.sql("select count(*) from projects where parent_id=?", pid)

    r = signed_in.post("/project/add.php", data={"parent_id": pid, "title": "Child"})
    assert r.status_code == 409
    assert app.sql("select count(*) from projects where parent_id=?", pid) == before


def test_no_new_comment_on_an_archived_project(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/archive.php", data={"id": pid})
    before = app.sql("select count(*) from comments where project_id=?", pid)

    r = signed_in.post("/project/comment.php", data={"id": pid, "body": "nope"})
    assert r.status_code == 409
    assert app.sql("select count(*) from comments where project_id=?", pid) == before


def test_a_comment_cannot_be_edited_or_deleted(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/comment.php", headers=HTMX, data={"id": pid, "body": "original"})
    cid = app.sql("select id from comments where project_id=? order by id desc limit 1", pid)[0][0]
    signed_in.post("/project/archive.php", data={"id": pid})

    assert signed_in.post("/project/comment_edit.php", headers=HTMX,
                          data={"comment_id": cid, "body": "changed"}).status_code == 409
    assert signed_in.post("/project/comment_delete.php", headers=HTMX,
                          data={"comment_id": cid}).status_code == 409
    assert app.sql("select body from comments where id=?", cid) == [("original",)]


def test_the_page_shows_no_control_that_would_change_it(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/archive.php", data={"id": pid})
    page = soup(signed_in.get(f"/project/?id={pid}"))

    assert page.select_one('select[name="status"]') is None, "no status picker"
    assert page.select_one("#desc-edit") is None, "no description editor"
    assert page.select_one("#pf") is None, "no edit form"
    assert page.select_one('form[action="/project/add.php"]') is None, "no add-subproject form"
    assert page.select_one('form[action="/project/comment.php"]') is None, "no add-comment form"
    assert page.select_one("#people-form") is None, "no assignee picker"
    assert page.select_one("#boards-form") is None, "no board picker"
    assert "cannot be restored" in text_of(page)


def test_an_editable_project_still_offers_them(app, signed_in, archivable):
    title, pid = archivable
    page = soup(signed_in.get(f"/project/?id={pid}"))

    assert page.select_one('select[name="status"]') is not None
    assert page.select_one("#pf") is not None
    assert page.select_one("#people-form") is not None
    assert page.select_one("#boards-form") is not None
    assert page.select_one('form[action="/project/comment.php"]') is not None


def test_comments_lose_their_edit_and_delete_when_archived(app, signed_in, archivable):
    title, pid = archivable
    signed_in.post("/project/comment.php", headers=HTMX, data={"id": pid, "body": "keep me"})
    signed_in.post("/project/archive.php", data={"id": pid})
    page = soup(signed_in.get(f"/project/?id={pid}"))

    assert "keep me" in text_of(page)
    assert page.select_one('form[action="/project/comment_delete.php"]') is None
    assert page.select_one('a[href^="/project/comment_edit.php"]') is None
