"""The page footer: links to the repository and its issues page, on every page that has a navbar.

The footer hangs off page_end(), which every page calls, so a signed-in page proving it renders is
enough for the pages that have it. The pages without a navbar -- sign in and first-run setup -- must
not carry it, since a footer under a lone centred form would undo the layout they exist for.
"""
from helpers import soup

REPO = "https://github.com/orangeisbetter/slate"
ISSUES = "https://github.com/orangeisbetter/slate/issues"


def test_footer_links_to_the_repository_and_issues(signed_in):
    page = soup(signed_in.get("/"))
    hrefs = [a.get("href") for a in page.select("footer a")]
    assert REPO in hrefs
    assert ISSUES in hrefs


def test_no_footer_without_a_navbar(anon):
    page = soup(anon.get("/login/"))
    assert page.select("footer") == []
