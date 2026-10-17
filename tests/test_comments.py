"""Comments over htmx. Ported from the old shell suite."""
from helpers import ADA_ID, soup, text_of

HTMX = {"HX-Request": "true"}
BODY = "Line one\nLine two & <b>x</b>"


def post_comment(signed_in, pid, body=BODY):
    return signed_in.post("/project/comment.php", headers=HTMX, data={"id": pid, "body": body})


def test_a_comment_is_rendered_escaped_with_its_line_break(make_project, signed_in):
    r = post_comment(signed_in, make_project("Comment test"))
    assert "Line two &amp; &lt;b&gt;x&lt;/b&gt;" in r.text
    assert soup(r).find("b") is None, "markup in a comment must not become markup"
    assert "<br />" in r.text, "the newline should be kept"
    assert "No comments yet" not in r.text


def test_the_author_is_shown_and_linked(make_project, signed_in):
    frag = soup(post_comment(signed_in, make_project("Comment test")))
    author = frag.select_one('a.link-quiet[href="/admin/users/#user-1"]')
    assert author is not None
    assert author.get_text(strip=True) == "Ada Lovelace"


def test_the_comment_is_stored_against_this_user(app, make_project, signed_in):
    pid = make_project("Comment test")
    post_comment(signed_in, pid)
    assert app.sql("select author_id from comments where project_id=?", pid) == [(ADA_ID,)]


def test_the_comment_is_there_when_the_page_is_reloaded(make_project, signed_in):
    pid = make_project("Comment test")
    post_comment(signed_in, pid)
    text = text_of(soup(signed_in.get(f"/project/?id={pid}")))
    assert "Line two & <b>x</b>" in text
    assert "No comments yet" not in text


def test_posting_a_comment_refreshes_last_updated_out_of_band(make_project, signed_in):
    pid = make_project("Comment timestamp test")
    r = post_comment(signed_in, pid, body="a fresh comment")
    oob = soup(r).select_one('#last-updated[hx-swap-oob="true"]')
    assert oob is not None, "the comment response should carry the Last updated line out-of-band"
    assert "Last updated" in oob.get_text()


def test_author_can_edit_their_comment(app, make_project, signed_in):
    pid = make_project("Comment edit test")
    post_comment(signed_in, pid, body="orig typo")
    cid = app.sql("select id from comments where project_id=?", pid)[0][0]
    r = signed_in.patch("/project/comment_edit.php", headers=HTMX,
                       data={"comment_id": cid, "body": "fixed text"})
    assert "fixed text" in r.text
    assert "edited" in r.text
    assert app.sql("select body from comments where id=?", cid)[0][0] == "fixed text"
    assert app.sql("select edited_at from comments where id=?", cid)[0][0] is not None


def test_author_can_delete_their_comment(app, make_project, signed_in):
    pid = make_project("Comment delete test")
    post_comment(signed_in, pid, body="bye")
    cid = app.sql("select id from comments where project_id=?", pid)[0][0]
    r = signed_in.delete("/project/comment_delete.php", headers=HTMX, data={"comment_id": cid})
    assert "No comments yet" in r.text
    assert app.sql("select count(*) from comments where id=?", cid)[0][0] == 0


def test_non_author_cannot_change_a_comment(app, make_project, signed_in, member):
    pid = make_project("Comment permission test")
    post_comment(signed_in, pid, body="admin words")
    cid = app.sql("select id from comments where project_id=?", pid)[0][0]
    assert member.patch("/project/comment_edit.php",
                       data={"comment_id": cid, "body": "hijack"}).status_code == 403
    assert member.delete("/project/comment_delete.php",
                       data={"comment_id": cid}).status_code == 403
    assert app.sql("select body from comments where id=?", cid)[0][0] == "admin words"
