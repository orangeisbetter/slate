"""Bad input and wrong methods. One table, as in the 'reject bad input' section of the old shell suite.

A browser request that bad() refuses now gets the shared error page (card #54), so each response
carries its status code and the sentence somewhere in the page; no request here may leave a project
behind. The htmx half of the split -- plain text, not a page -- is pinned in test_errors.py.
"""
import pytest

CASES = [
    ("blank title",          "POST", "/new/add.php",              {"title": ""},                          "Title is required",        400),
    ("bad date",             "POST", "/new/add.php",              {"title": "x", "due_date": "1-2-3"},    "Dates must be YYYY-MM-DD", 400),
    ("bad status",           "POST", "/new/add.php",              {"title": "x", "status": "nope"},       "Invalid status",           400),
    ("bad type",             "POST", "/new/add.php",              {"title": "x", "type": "nope"},         "Invalid type",             400),
    ("missing parent",       "POST", "/new/add.php",              {"title": "x", "parent_id": "9999"},    "Parent project not found", 404),
    ("get on add",           "GET",  "/new/add.php",              None,                                   "POST only",                405),
    ("get on update",        "GET",  "/project/update.php",       None,                                   "POST or PATCH only",       405),
    ("get on assign",        "GET",  "/project/assign.php",       None,                                   "Project not found",        404),
    ("unknown project",      "POST", "/project/assign.php",       {"id": "9999"},                         "Project not found",        404),
    ("update needs an id",   "PATCH","/project/update.php",       {"title": "x"},                         "Project not found",        404),
    ("update blank title",   "POST", "/project/update.php",       {"id": "9999"},                         "Title is required",        400),
    ("status needs PATCH",   "POST", "/project/update_status.php", {"id": "1", "status": "done"},         "PATCH only",               405),
]


@pytest.mark.parametrize("method, path, data, message, code", [c[1:] for c in CASES], ids=[c[0] for c in CASES])
def test_rejected_with_a_clear_message_and_no_side_effects(app, signed_in, method, path, data, message, code):
    before = app.sql("select count(*) from projects")
    r = signed_in.request(method, path, data=data)
    assert r.status_code == code
    assert message in r.text, "the refusal should be on the error page"
    assert "</html>" in r.text, "the error page should be a complete document"
    assert app.sql("select count(*) from projects") == before


def test_the_removed_identity_switcher_is_gone(signed_in):
    assert signed_in.post("/admin/users/user.php", data={"uid": "4"}).status_code == 404
