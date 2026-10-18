"""The page shell as it leaves the server: the assets every page loads, and the response headers
every response carries.

The stylesheet question is the old CDN split now settled the other way: Bootstrap and htmx are
served from public/assets/vendor/, so no page names a third-party host. The headers come from
slate_security_headers() in src/errors.php, which every entry point loads first — including the
ones that die during bootstrap — so an error response has to prove it carries them too.
"""


def test_every_asset_is_served_from_the_app_itself(anon):
    html = anon.get("/login/").text
    assert "/assets/vendor/bootstrap.min.css" in html
    assert "/assets/vendor/htmx.min.js" in html
    assert "/assets/vendor/bootstrap.bundle.min.js" in html
    assert "cdn.jsdelivr.net" not in html, "no page should name a third-party host"


def test_the_vendor_files_actually_answer(anon):
    for path in ("/assets/vendor/bootstrap.min.css",
                 "/assets/vendor/bootstrap.bundle.min.js",
                 "/assets/vendor/htmx.min.js"):
        r = anon.get(path)
        assert r.status_code == 200, f"{path} answered {r.status_code}"
        assert len(r.content) > 10000, f"{path} looks truncated"


def test_security_headers_are_on_a_normal_page(anon):
    r = anon.get("/login/")
    assert r.headers.get("X-Frame-Options") == "DENY"
    assert r.headers.get("X-Content-Type-Options") == "nosniff"
    # The suite talks plain http: a browser ignores HSTS there, so the app does not send it.
    assert "Strict-Transport-Security" not in r.headers


def test_security_headers_are_on_an_error_page(anon):
    """slate_security_headers() runs when errors.php loads, before anything can fail, so the
    405 the error page answers carries the same frame protection as a rendered page."""
    r = anon.get("/login/login.php")
    assert r.status_code == 405
    assert r.headers.get("X-Frame-Options") == "DENY"
    assert r.headers.get("X-Content-Type-Options") == "nosniff"
