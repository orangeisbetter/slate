"""Recent activity page. Card #39."""
from helpers import text_of, soup

HTMX = {"HX-Request": "true"}


def test_activity_shows_creation_move_and_comment_newest_first(make_project, signed_in):
    pid = make_project("Activity probe")
    signed_in.patch("/project/update_status.php", headers=HTMX,
                    data={"id": pid, "status": "in_progress"})
    signed_in.post("/project/comment.php", headers=HTMX,
                   data={"id": pid, "body": "standup note"})
    r = signed_in.get("/activity/")
    assert r.status_code == 200
    text = text_of(soup(r))
    assert "Activity probe" in text
    assert "standup note" in text
    assert "In progress" in text
    # newest first: the creation reads last; the move and the comment (same second,
    # so their relative order is a tie) read above it
    items = [li.get_text(" ", strip=True) for li in soup(r).select("li.list-group-item")]
    probe = [t for t in items if "Activity probe" in t]
    assert len(probe) == 3
    assert "created" in probe[-1]
    assert any("standup note" in t for t in probe[:2])
    assert any("In progress" in t for t in probe[:2])


def test_activity_requires_login(anon):
    r = anon.get("/activity/")
    assert r.status_code in (302, 303)


def test_activity_links_back_to_the_project(make_project, signed_in):
    pid = make_project("Activity link probe")
    frag = soup(signed_in.get("/activity/"))
    assert frag.select_one(f'a[href="/project/?id={pid}"]') is not None
