<?php
// Works as a plain form post (redirects) and as an htmx request (returns the comment list).
// The htmx response carries the "Last updated" line out-of-band too: posting bumps
// updated_at, and refreshing only the comments leaves the old line stale on the page.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
$id = (int)($_POST['id'] ?? 0);
editable_project($id);   // an archived project takes no new comments
$body = trim($_POST['body'] ?? '');
if ($body !== '') {
    q('INSERT INTO comments(project_id,author_id,body,created_at) VALUES(?,?,?,?)', [$id, $me, $body, gmdate('c')]);
    q('UPDATE projects SET updated_at=? WHERE id=?', [gmdate('c'), $id]);
}
if (isset($_SERVER['HTTP_HX_REQUEST'])) {
    render_comments($id);
    $p = project($id);
    if ($p) {
        ?>
        <p class="text-body-secondary small mb-4" id="last-updated" hx-swap-oob="true">Created <?= dt($p->created_at) ?>. Last updated <?= dt($p->updated_at) ?>.</p>
        <?php
    }
    exit;
}
go("/project/?id=$id#comments");
