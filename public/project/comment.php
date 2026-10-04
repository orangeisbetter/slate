<?php
// Works as a plain form post (redirects) and as an htmx request (returns the comment list).
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
$id = (int)($_POST['id'] ?? 0);
if (!project($id)) bad('Project not found', 404);
$body = trim($_POST['body'] ?? '');
if ($body !== '') {
    q('INSERT INTO comments(project_id,author_id,body,created_at) VALUES(?,?,?,?)', [$id, $me, $body, gmdate('c')]);
    q('UPDATE projects SET updated_at=? WHERE id=?', [gmdate('c'), $id]);
}
if (isset($_SERVER['HTTP_HX_REQUEST'])) {
    render_comments($id);
    exit;
}
go(base_url() . "/project/?id=$id#comments");
