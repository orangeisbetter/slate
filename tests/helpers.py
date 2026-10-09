"""Small helpers shared by the test modules."""
from urllib.parse import urljoin, urlparse

from bs4 import BeautifulSoup

ADA_ID = 1  # the seeded user; the old shell suite asserted creator_id / author_id == 1


def soup(response):
    return BeautifulSoup(response.text, "html.parser")


def text_of(page):
    """Visible text with whitespace collapsed, so a phrase can be matched as one string."""
    return " ".join(page.get_text().split())


def is_redirect(response):
    return response.status_code in (301, 302, 303, 307, 308)


def redirect_to(app, response):
    """Where a redirect points, as path?query, whether the server sent a relative or absolute URL."""
    loc = response.headers.get("Location")
    if not loc:
        return None
    u = urlparse(urljoin(app.base + "/", loc))
    return u.path + (f"?{u.query}" if u.query else "")


def picker_rows(page):
    """User ids currently chosen, read from the current-member rows (not the picker dialog)."""
    return sorted(el["data-value"] for el in page.select("[data-picker-row]"))


def picker_options(page, root=None):
    """The dialog's entries, keyed by the id they carry.

    A page can carry two pickers whose ids overlap — people are user ids, boards are board ids, and
    both start at 1 — so pass the picker's own container (`#picker-people-options`) whenever the
    test means one of them in particular. Unscoped, the later picker wins the collision.
    """
    scope = page.select_one(root) if root else page
    if scope is None:
        return {}
    return {el["data-value"]: el for el in scope.select("[data-picker-option]")}


def is_hidden(element):
    """The picker hides an entry that is already chosen with Bootstrap's d-none."""
    return "d-none" in (element.get("class") or [])
