<?php
// Called by drag and drop. Returns the re-rendered board for htmx to swap in, which is
// why the board id is posted back: a drop must not spill the board's cards into #board.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
$p = project((int)($_POST['id'] ?? 0));
$st = $_POST['status'] ?? '';
// An archived project is frozen and is not drawn on any board, so a drag can never legitimately
// name one; a crafted post that does must not move it. The re-render below still runs for the
// board the request named.
if ($p && $p->archived) {
    bad('This project is archived and cannot be changed', 409);
}
if ($p && status_exists($st) && $st !== $p->status) {
    q('UPDATE projects SET status=?, updated_at=? WHERE id=?', [$st, gmdate('c'), $p->id]);
    logStatus($p->id, $p->status, $st, $me);
}
$board_id = (int)($_POST['board'] ?? 0);
render_board($board_id ?: null);
