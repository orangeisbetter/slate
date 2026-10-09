"""Project search: found by title, description, comments and assignees. Card #38."""
from helpers import soup

HTMX = {"HX-Request": "true"}


def result_ids(signed_in, q):
    from urllib.parse import quote
    page = soup(signed_in.get(f"/search.php?q={quote(q)}"))
    ids = set()
    for a in page.select('a[href^="/project/?id="]'):
        try:
            ids.add(int(a["href"].rsplit("id=", 1)[1]))
        except ValueError:
            pass
    return ids


def menu_ids(signed_in, q):
    from urllib.parse import quote
    page = soup(signed_in.get(f"/search.php?q={quote(q)}", headers=HTMX))
    return {int(a["href"].rsplit("id=", 1)[1])
            for a in page.select('a[href^="/project/?id="]')}


def test_found_by_a_word_in_the_comment(signed_in, make_project):
    pid = make_project("Comment search base")
    signed_in.post("/project/comment.php", data={"id": pid, "body": "the transformer hums at mains frequency"})
    assert pid in result_ids(signed_in, "transformer")


def test_found_by_assignee_name_and_address(signed_in, make_project):
    pid = make_project("Assignee search base", assignees=[1])
    assert pid in result_ids(signed_in, "Lovelace")
    assert pid in result_ids(signed_in, "ada@example.com")


def test_found_by_a_word_in_the_description(signed_in, make_project):
    pid = make_project("Description search base", description="check R14 before fitting the panel")
    assert pid in result_ids(signed_in, "R14")


def test_every_keyword_must_match_somewhere(signed_in, make_project):
    pid = make_project("Zebra query base", description="qwark crystal lattice")
    assert pid in result_ids(signed_in, "zebra qwark")
    assert pid not in result_ids(signed_in, "zebra nothingmatchesme")


def test_the_header_menu_looks_past_the_title_too(signed_in, make_project):
    pid = make_project("Menu search base")
    signed_in.post("/project/comment.php", data={"id": pid, "body": "menuvisibleword synchroniser"})
    assert pid in menu_ids(signed_in, "menuvisibleword")
