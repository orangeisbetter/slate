"""Shared fixtures: a throwaway copy of the app served by `php -S`, plus HTTP clients.

Mirrors the setup at the top of flows.sh (copy src/public/db/bin, migrate, seed, serve), but the
port is picked by the OS so nothing can collide, and the server is a fixture rather than a global.

Requires: pip install pytest requests beautifulsoup4
"""
import os
import shutil
import socket
import sqlite3
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path

import pytest
import requests

ROOT = Path(__file__).resolve().parents[1]

SEED_EMAIL = "ada@example.com"
SEED_PASSWORD = "correcthorse1"
SEED_IDENTITY = "Ada Lovelace (admin)"  # as shown on signed-in pages (see flows.sh, "signed in identity")


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("localhost", 0))
        return s.getsockname()[1]


def start_php(docroot: Path, *php_args: str, env: dict | None = None):
    """Start the built-in server and wait until /login/ answers. Returns (process, base_url)."""
    port = _free_port()
    proc = subprocess.Popen(
        ["php", *php_args, "-S", f"localhost:{port}", "-t", str(docroot)],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env={**os.environ, **(env or {})},
    )
    base = f"http://localhost:{port}"
    deadline = time.time() + 6
    while time.time() < deadline:
        try:
            requests.get(f"{base}/login/", timeout=0.5)
            return proc, base
        except requests.ConnectionError:
            time.sleep(0.1)
    proc.terminate()
    raise RuntimeError(f"php -S did not come up on {base}")


class Client(requests.Session):
    """A cookie-keeping session that talks to one server and never follows redirects by default,
    so a test can look at the redirect itself (that is what most auth assertions are about)."""

    def __init__(self, base: str):
        super().__init__()
        self.base = base

    def request(self, method, url, **kw):
        kw.setdefault("allow_redirects", False)
        kw.setdefault("timeout", 10)
        if url.startswith("/"):
            url = self.base + url
        return super().request(method, url, **kw)

    # Session.get() sets allow_redirects=True itself before calling request(), so the default
    # above never applies to GETs. Without this, every GET silently followed redirects and a
    # "redirect to login" looked like a 200 on the login page.
    def get(self, url, **kw):
        kw.setdefault("allow_redirects", False)
        return super().get(url, **kw)


@dataclass
class App:
    dir: Path
    base: str

    @property
    def db_path(self) -> Path:
        return self.dir / "data" / "board.db"

    def sql(self, query: str, *args):
        con = sqlite3.connect(self.db_path)
        try:
            return con.execute(query, args).fetchall()
        finally:
            con.close()


@pytest.fixture(scope="session")
def app(tmp_path_factory):
    tmp = tmp_path_factory.mktemp("slate")
    for d in ("src", "public", "db", "bin"):
        shutil.copytree(ROOT / d, tmp / d)
    (tmp / "data").mkdir()
    subprocess.run(["php", "bin/migrate.php"], cwd=tmp, check=True, capture_output=True)
    subprocess.run(
        ["php", str(ROOT / "tests" / "seed.php"), str(tmp / "data" / "board.db"),
         SEED_EMAIL, SEED_PASSWORD],
        check=True, capture_output=True,
    )
    proc, base = start_php(tmp / "public")
    yield App(dir=tmp, base=base)
    proc.terminate()
    proc.wait(timeout=5)


@pytest.fixture
def user():
    return {"email": SEED_EMAIL, "password": SEED_PASSWORD, "identity": SEED_IDENTITY}


@pytest.fixture
def anon(app):
    """A visitor with no session."""
    return Client(app.base)


@pytest.fixture
def signed_in(app, user):
    """A visitor who has logged in as the seeded user."""
    c = Client(app.base)
    r = c.post("/login/login.php", data={"email": user["email"], "password": user["password"]})
    assert r.status_code in (302, 303), f"login fixture failed: {r.status_code}"
    return c


@pytest.fixture
def project_id(app):
    """A project that exists in the seeded database, for pages that need one."""
    return app.sql("select id from projects order by id limit 1")[0][0]


@pytest.fixture
def panel_order(app):
    """The order work moves through, as this app's own database has it. The statuses are rows now,
    so the source of truth is a query rather than a constant."""
    return [r[0] for r in app.sql("select slug from project_statuses order by sort_order, id")]


@pytest.fixture(scope="session")
def member(app):
    """A second account with no roles, created through the real invite flow and signed in as it.

    Session-scoped because it is one account: inviting the same address twice is refused, so a
    function-scoped fixture would work once and fail every test after. It logs in as the seeded
    admin with the credentials `app` seeded, rather than depending on the function-scoped `user`
    fixture, which session scope forbids. The seeded people cannot serve as the member either —
    only the first of them has a password, and the one who does is the admin every other fixture
    signs in as. Minting an invite and accepting it is the path a real member arrives by, so the
    fixture exercises it rather than inventing an account.
    """
    admin = Client(app.base)
    r = admin.post("/login/login.php", data={"email": SEED_EMAIL, "password": SEED_PASSWORD})
    assert r.status_code in (302, 303), f"admin login failed: {r.status_code}"
    r = admin.post("/admin/users/invite.php", data={"email": "member@example.com", "name": "Member Person"})
    assert r.status_code in (302, 303), f"invite failed: {r.status_code} {r.text[:200]}"
    token = r.headers["Location"].split("invite=", 1)[1]

    c = Client(app.base)
    r = c.post("/invite/accept_invite.php", data={
        "token": token, "name": "Member Person",
        "password": "memberpassword1", "password2": "memberpassword1"})
    assert r.status_code in (302, 303), f"accept failed: {r.status_code} {r.text[:200]}"
    return c


@pytest.fixture
def make_project(signed_in):
    """Create a project through the real endpoint and return its id.

    Each test makes the project it needs, so no test depends on one that ran before it.
    `assignees` is a list of user ids; any other field (status, type, due_date...) passes through.
    """
    def make(title="Test project", assignees=(), **fields):
        data = {"title": title, "status": "todo", "type": "bug", **fields}
        if assignees:
            data["as[]"] = list(assignees)
        r = signed_in.post("/new/add.php", data=data)
        assert r.status_code in (302, 303), f"create failed: {r.status_code} {r.text[:200]}"
        return int(r.headers["Location"].rsplit("id=", 1)[1])
    return make
