<?php
// Edit a comment in place. GET draws the form as a page; PATCH saves it (a plain form posts).
// An htmx PATCH answers with the comment list, like comment.php next door, so the
// edit lands without a reload; a plain post redirects back to the project.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

function comment_or_404(int $cid): Comment {
    $stmt = q('SELECT * FROM comments WHERE id=?', [$cid]);
    $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Comment::class);
    $c = $stmt->fetch();
    if (!$c) bad('Comment not found', 404);
    return $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $c = comment_or_404((int)($_GET['id'] ?? 0));
    if (!can_edit_comment($me, $c->author_id)) bad('You cannot edit this comment', 403);
    $p = editable_project($c->project_id);   // an archived project is read-only, comments included
    page_start('Edit comment');
    ?>
    <div class="page-wide mx-auto p-3 p-md-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="/project/?id=<?= $p->id ?>"><?= h($p->title) ?></a></li>
                <li class="breadcrumb-item active">Edit comment</li>
            </ol>
        </nav>
        <h1 class="h3">Edit comment</h1>
        <form method="post" action="/project/comment_edit.php">
            <input type="hidden" name="comment_id" value="<?= (int)$c->id ?>">
            <textarea class="form-control mb-2" name="body" rows="4" required><?= h($c->body) ?></textarea>
            <div class="form-text mb-2">Markdown: <code>**bold**</code> for <strong>bold</strong>, <code>*italic*</code> for <em>italic</em>, <code>`code`</code> for <code>code</code>, <code>- list</code>, <code>#12</code> for a project, and a bare <code>https://</code> URL links too</div>
            <button class="btn btn-primary">Save comment</button>
            <a class="btn btn-outline-secondary" href="/project/?id=<?= $p->id ?>#comment-<?= (int)$c->id ?>">Cancel</a>
        </form>
    </div>
    <?php
    page_end();
    exit;
}

require_method('PATCH');
$in = request_data();
$c = comment_or_404((int)($in['comment_id'] ?? 0));
if (!can_edit_comment($me, $c->author_id)) bad('You cannot edit this comment', 403);
editable_project($c->project_id);   // an archived project is read-only, comments included
$body = trim($in['body'] ?? '');
if ($body === '') bad('A comment needs a body');
q('UPDATE comments SET body=?, edited_at=? WHERE id=?', [$body, gmdate('c'), $c->id]);
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
go("/project/?id={$c->project_id}#comment-{$c->id}");
