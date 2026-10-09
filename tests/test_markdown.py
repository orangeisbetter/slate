"""Markdown in comments and descriptions: one renderer for both. Card #44.

The subset is bold/italic, inline code and fenced blocks, bracket links with checked
schemes, and lists. Everything else stays escaped text, and single newlines still break.
"""
from helpers import soup

HTMX = {"HX-Request": "true"}


def post_comment(signed_in, pid, body):
    return signed_in.post("/project/comment.php", headers=HTMX, data={"id": pid, "body": body})


def frag_of(signed_in, make_project, body):
    return soup(post_comment(signed_in, make_project("Markdown base"), body))


def test_bold_italic_and_inline_code(signed_in, make_project):
    frag = frag_of(signed_in, make_project, "**bold** and *italic* and `code`")
    assert frag.select_one("strong").get_text(strip=True) == "bold"
    assert frag.select_one("em").get_text(strip=True) == "italic"
    assert frag.select_one("code").get_text(strip=True) == "code"


def test_fenced_block_renders_as_pre_without_links(signed_in, make_project):
    target = make_project("Markdown code target")
    frag = frag_of(signed_in, make_project, f"```\nsee #{target} & <b>\n```")
    pre = frag.select_one("pre code")
    assert pre is not None
    assert f"#{target}" in pre.get_text()
    assert frag.select_one(f'a[href="/project/?id={target}"]') is None
    assert "&lt;b&gt;" in str(frag)


def test_unordered_and_ordered_lists(signed_in, make_project):
    frag = frag_of(signed_in, make_project, "- check R14\n- fit panel")
    items = [li.get_text(strip=True) for li in frag.select("ul li")]
    assert items == ["check R14", "fit panel"]
    frag = frag_of(signed_in, make_project, "1. first\n2. second")
    assert [li.get_text(strip=True) for li in frag.select("ol li")] == ["first", "second"]


def test_bracket_link_and_scheme_check(signed_in, make_project):
    frag = frag_of(signed_in, make_project, "[Slate](https://example.com/x)")
    link = frag.select_one('a[href="https://example.com/x"]')
    assert link is not None and link.get_text(strip=True) == "Slate"
    frag = frag_of(signed_in, make_project, "[evil](javascript:alert(1))")
    body = frag.select("div[id^=comment-] > div:last-child")[0]
    assert body.select_one("a[href]") is None
    assert "[evil](javascript:alert(1))" in body.get_text()


def test_single_newlines_still_break(signed_in, make_project):
    r = post_comment(signed_in, make_project("Markdown breaks"), "one\ntwo")
    assert "<br />" in r.text


def test_raw_html_stays_text(signed_in, make_project):
    frag = frag_of(signed_in, make_project, "<b>nope</b> and **yes**")
    assert frag.select_one("b") is None
    assert frag.select_one("strong").get_text(strip=True) == "yes"
    assert "&lt;b&gt;" in str(frag)


def test_snake_case_survives_underscores(signed_in, make_project):
    frag = frag_of(signed_in, make_project, "check CTRL_REG before fitting")
    assert frag.select_one("em") is None
    assert "CTRL_REG" in frag.get_text()


def test_references_still_link_outside_code(signed_in, make_project):
    target = make_project("Markdown ref target")
    frag = frag_of(signed_in, make_project, f"**bold** see #{target}")
    link = frag.select_one(f'a[href="/project/?id={target}"]')
    assert link is not None and link.get_text(strip=True) == f"#{target}"


def test_markdown_inside_a_list_item(signed_in, make_project):
    target = make_project("Markdown list ref target")
    frag = frag_of(signed_in, make_project, f"- **bold** see #{target}")
    item = frag.select_one("ul li")
    assert item.select_one("strong") is not None
    assert item.select_one(f'a[href="/project/?id={target}"]') is not None


def test_the_description_renders_markdown_too(signed_in, make_project):
    pid = make_project("Markdown description", description="**shipped** and `R14`\n- fit")
    desc = soup(signed_in.get(f"/project/?id={pid}")).select_one("#project-description")
    assert desc.select_one("strong").get_text(strip=True) == "shipped"
    assert desc.select_one("code").get_text(strip=True) == "R14"
    assert [li.get_text(strip=True) for li in desc.select("ul li")] == ["fit"]


def test_comments_and_descriptions_agree(signed_in, make_project):
    """One renderer, not two: the same body reads the same in both places."""
    body = "**same** text"
    pid = make_project("Markdown agreement", description=body)
    post_comment(signed_in, pid, body)
    page = soup(signed_in.get(f"/project/?id={pid}"))
    assert page.select_one("#project-description strong").get_text(strip=True) == "same"
    comment = page.select_one("#cms strong")
    assert comment is not None and comment.get_text(strip=True) == "same"
