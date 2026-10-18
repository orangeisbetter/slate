<?php
// The status dropdown on the project page. An htmx PATCH swaps the re-rendered history list
// back in (the board drag is the pattern), with the "Last updated" line alongside it
// out-of-band — both change, so swapping only one leaves the page claiming otherwise.
// A non-htmx PATCH answers JSON carrying the project's id and its status as stored — read back
// from the row, never the request body, so the payload a client posted is not echoed back.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    bad('PATCH only', 405);
}

$raw_input = file_get_contents("php://input");
parse_str($raw_input, $data);

$p = project((int)($data['id'] ?? 0));
$st = $data['status'] ?? '';

// An archived project is frozen: it has been retired, so its status must not move even though a
// crafted PATCH can still name it. project() is used rather than editable_project() because an
// unknown id is an error for both callers rather than an editable thing to reach.
if ($p && $p->archived) {
    bad('This project is archived and cannot be changed', 409);
}

if ($p && status_exists($st) && $st !== $p->status) {
    q('UPDATE projects SET status = ?, updated_at = ? WHERE id = ?', [$st, gmdate('c'), $p->id]);
    logStatus($p->id, $p->status, $st, $me);
    $p = project($p->id);
}

if (!$p) {
    bad('Project not found', 404);
}

if (isset($_SERVER['HTTP_HX_REQUEST'])) {
    status_history_list($p->id);
    // Out-of-band: htmx swaps this where its id lives, alongside the history list above.
    ?>
    <p class="text-body-secondary small mb-4" id="last-updated" hx-swap-oob="true">Created <?= dt($p->created_at) ?>. Last updated <?= dt($p->updated_at) ?>.</p>
    <?php
    exit;
}

// The status as stored, not the status as asked for: a PATCH naming an unknown status reads back
// the project's real one, and id/status are cast rather than trusted as the request spelled them.
header('Content-Type: application/json');
echo json_encode(['id' => (string)$p->id, 'status' => (string)$p->status]);
