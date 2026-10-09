<?php
// Delete a comment. A full-page mutation, so a plain POST redirects back; an htmx
// POST answers with the comment list, like comment.php next door.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
$cid = (int)($_POST['comment_id'] ?? 0);
$stmt = q('SELECT * FROM comments WHERE id=?', [$cid]);
$stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Comment::class);
$c = $stmt->fetch();
if (!$c) bad('Comment not found', 404);
if (!can_edit_comment($me, $c->author_id)) bad('You cannot delete this comment', 403);
q('DELETE FROM comments WHERE id=?', [$c->id]);
q('UPDATE projects SET updated_at=? WHERE id=?', [gmdate('c'), $c->project_id]);
if (isset($_SERVER['HTTP_HX_REQUEST'])) {
    render_comments($c->project_id);
    $p = project($c->project_id);
    if ($p) {
        ?>
        <p class="text-body-secondary small mb-4" id="last-updated" hx-swap-oob="true">Created <?= dt($p->created_at) ?>. Last updated <?= dt($p->updated_at) ?>.</p>
        <?php
    }
    exit;
}
go("/project/?id={$c->project_id}#comments");
