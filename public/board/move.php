<?php
// Called by drag and drop. Returns the re-rendered board for htmx to swap in, which is
// why the board id is posted back: a drop must not spill the board's cards into #board.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
$p = project((int)($_POST['id'] ?? 0));
$st = $_POST['status'] ?? '';
// A drop names a card the board drew, so an id that is not a project is a crafted or stale request
// rather than a move. update_status.php refuses the same way over htmx: the two endpoints must not
// disagree about a bad id.
if (!$p) {
    bad('Project not found', 404);
}
// An archived project is frozen and is not drawn on any board, so a drag can never legitimately
// name one; a crafted post that does must not move it.
if ($p->archived) {
    bad('This project is archived and cannot be changed', 409);
}
if (status_exists($st) && $st !== $p->status) {
    q('UPDATE projects SET status=?, updated_at=? WHERE id=?', [$st, gmdate('c'), $p->id]);
    logStatus($p->id, $p->status, $st, $me);
}
$board_id = (int)($_POST['board'] ?? 0);
render_board($board_id ?: null);
