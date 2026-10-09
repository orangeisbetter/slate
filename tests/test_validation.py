"""Bad input and wrong methods. One table, as in the 'reject bad input' section of the old shell suite.

Each response body is the whole message (the old suite compared it for equality), and no request here
may leave a project behind.
"""
import pytest

CASES = [
    ("blank title",          "POST", "/new/add.php",              {"title": ""},                          "Title is required"),
    ("bad date",             "POST", "/new/add.php",              {"title": "x", "due_date": "1-2-3"},    "Dates must be YYYY-MM-DD"),
    ("bad status",           "POST", "/new/add.php",              {"title": "x", "status": "nope"},       "Invalid status"),
    ("bad type",             "POST", "/new/add.php",              {"title": "x", "type": "nope"},         "Invalid type"),
    ("missing parent",       "POST", "/new/add.php",              {"title": "x", "parent_id": "9999"},    "Parent project not found"),
    ("get on add",           "GET",  "/new/add.php",              None,                                   "POST only"),
    ("get on update",        "GET",  "/project/update.php",       None,                                   "POST only"),
    ("get on assign",        "GET",  "/project/assign.php",       None,                                   "Project not found"),
    ("unknown project",      "POST", "/project/assign.php",       {"id": "9999"},                         "Project not found"),
    ("update needs an id",   "POST", "/project/update.php",       {"title": "x"},                         "Project not found"),
    ("update blank title",   "POST", "/project/update.php",       {"id": "9999"},                         "Title is required"),
    ("status needs PATCH",   "POST", "/project/update_status.php", {"id": "1", "status": "done"},         "PATCH only"),
]


@pytest.mark.parametrize("method, path, data, message", [c[1:] for c in CASES], ids=[c[0] for c in CASES])
def test_rejected_with_a_clear_message_and_no_side_effects(app, signed_in, method, path, data, message):
    before = app.sql("select count(*) from projects")
    r = signed_in.request(method, path, data=data)
    assert r.text.strip() == message
    assert app.sql("select count(*) from projects") == before


def test_the_removed_identity_switcher_is_gone(signed_in):
    assert signed_in.post("/admin/users/user.php", data={"uid": "4"}).status_code == 404
