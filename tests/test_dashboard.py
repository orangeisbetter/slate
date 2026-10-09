"""The dashboard. Ported from the old shell suite.

Not ported: that suite also asserted the empty state ("everything assigned to you is finished") after
finishing the epic. That holds only if Ada has no other unfinished assignment, and other tests
create projects assigned to her, so it would pass or fail depending on test order. If you want it,
give the test its own user who is assigned nothing else.
"""
import pytest

from helpers import soup, text_of


@pytest.fixture
def epic(app, signed_in):
    """The seeded epic Ada is assigned to. Whatever a test does to its status, it goes back."""
    pid = app.sql("select id from projects where title='Motor Interface'")[0][0]
    was = app.sql("select status from projects where id=?", pid)[0][0]
    yield pid
    signed_in.patch("/project/update_status.php", data={"id": pid, "status": was})


def dashboard_text(signed_in):
    return text_of(soup(signed_in.get("/")))


def test_dashboard_shows_totals_and_assigned_work(signed_in):
    text = dashboard_text(signed_in)
    assert "All projects" in text
    assert "Assigned to you" in text
    assert "Open board" not in text, "the per-board cards are gone"


def test_assigned_work_is_listed(signed_in, epic):
    assert "Motor Interface" in dashboard_text(signed_in)


def test_a_finished_project_leaves_the_dashboard_but_nothing_is_deleted(app, signed_in, epic):
    boards_before = app.sql("select count(*) from project_boards where project_id=?", epic)

    signed_in.patch("/project/update_status.php", data={"id": epic, "status": "done"})

    assert "Motor Interface" not in dashboard_text(signed_in)
    # The list view's default filter is the open statuses, so a finished one is reached by asking
    # for it — which is the claim: finished work stays reachable rather than disappearing.
    kept = text_of(soup(signed_in.get("/list/", params={"status[]": "done"})))
    assert "Motor Interface" in kept, "the list view keeps it"
    assert app.sql("select status from projects where id=?", epic) == [("done",)]
    assert app.sql("select count(*) from project_boards where project_id=?", epic) == boards_before


def test_reopening_a_finished_project_puts_it_back(app, signed_in, epic):
    was = app.sql("select status from projects where id=?", epic)[0][0]
    signed_in.patch("/project/update_status.php", data={"id": epic, "status": "done"})

    signed_in.patch("/project/update_status.php", data={"id": epic, "status": was})

    assert "Motor Interface" in dashboard_text(signed_in)
    assert app.sql("select status from projects where id=?", epic) == [(was,)]
