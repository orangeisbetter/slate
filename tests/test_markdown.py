"""Markdown in comments and descriptions: one renderer for both. Card #44.

The subset is bold/italic, inline code and fenced blocks, lists, and links whose text repeats the
target — a bare http(s) URL and a #NN project reference. Labelled links (`[text](url)`) are not
parsed at all, so a body can never show one name and go somewhere else. Everything else stays
escaped text, and single newlines still break.
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


def test_bracket_links_stay_text(signed_in, make_project):
    """No label may differ from where it points, so the labelled form of a link is not a link.
    The source reads back exactly as written — and if an http URL inside still picks up the
    autolinker, it wears its own address as its text, never the label beside it."""
    frag = frag_of(signed_in, make_project, "[Slate](https://example.com/x)")
    body = frag.select("div[id^=comment-] > div:last-child")[0]
    for a in body.select("a[href]"):
        assert a.get_text(strip=True) == a["href"], "an anchor may only say where it goes"
    assert "[Slate](https://example.com/x)" in body.get_text()

    frag = frag_of(signed_in, make_project, "[evil](javascript:alert(1))")
    body = frag.select("div[id^=comment-] > div:last-child")[0]
    assert body.select_one("a[href]") is None
    assert "[evil](javascript:alert(1))" in body.get_text()


def test_single_newlines_still_break(signed_in, make_project):
    r = post_comment(signed_in, make_project("Markdown breaks"), "one\ntwo")
    assert "<br />" in r.text


def test_a_bare_url_becomes_a_link(signed_in, make_project):
    """Card #62: a typed http(s):// URL is linked without bracket syntax."""
    frag = frag_of(signed_in, make_project, "see https://example.com/x for details")
    link = frag.select_one('a[href="https://example.com/x"]')
    assert link is not None and link.get_text(strip=True) == "https://example.com/x"
    frag = frag_of(signed_in, make_project, "and http://example.com/y too")
    assert frag.select_one('a[href="http://example.com/y"]') is not None


def test_autolinking_drops_trailing_punctuation(signed_in, make_project):
    frag = frag_of(signed_in, make_project, "go to https://example.com/x.")
    assert frag.select_one('a[href="https://example.com/x"]') is not None
    assert "." in frag.get_text()


def test_autolinking_leaves_code_alone(signed_in, make_project):
    frag = frag_of(signed_in, make_project, "run `curl https://example.com/x` first")
    assert frag.select_one('a[href="https://example.com/x"]') is None


def test_autolinking_does_not_invent_links_from_hostnames(signed_in, make_project):
    """Only explicit http(s):// schemes link; a bare hostname and a filename stay text."""
    frag = frag_of(signed_in, make_project, "see www.example.com and index.php")
    body = frag.select("div[id^=comment-] > div:last-child")[0]
    assert body.select_one("a[href]") is None


def test_the_description_autolinks_too(signed_in, make_project):
    pid = make_project("Autolink description", description="docs at https://example.com/d")
    desc = soup(signed_in.get(f"/project/?id={pid}")).select_one("#project-description")
    assert desc.select_one('a[href="https://example.com/d"]') is not None


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


def test_image_syntax_does_not_become_a_remote_fetch(signed_in, make_project):
    """A comment must not make every reader's browser fetch a URL its author chose. The app
    never turned image syntax into an <img> and still does not; the bracket form behind it is
    refused as well, so the source reads back as itself."""
    frag = frag_of(signed_in, make_project, "![pixel](https://example.com/track.png)")
    assert frag.select_one("img") is None
