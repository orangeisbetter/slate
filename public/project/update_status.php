<?php
// The status dropdown on the project page. An htmx PATCH swaps the re-rendered history list
// back in (the board drag is the pattern), with the "Last updated" line alongside it
// out-of-band — both change, so swapping only one leaves the page claiming otherwise.
// A non-htmx PATCH keeps the old JSON echo, which is what the tests and curl drive.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    bad('PATCH only', 405);
}

$raw_input = file_get_contents("php://input");
parse_str($raw_input, $data);

$p = project((int)($data['id'] ?? 0));
$st = $data['status'] ?? '';

if ($p && status_exists($st) && $st !== $p->status) {
    q('UPDATE projects SET status = ?, updated_at = ? WHERE id = ?', [$st, gmdate('c'), $p->id]);
    logStatus($p->id, $p->status, $st, $me);
    $p = project($p->id);
}

if (isset($_SERVER['HTTP_HX_REQUEST'])) {
    if (!$p) {
        bad('Project not found', 404);
    }
    status_history_list($p->id);
    // Out-of-band: htmx swaps this where its id lives, alongside the history list above.
    ?>
    <p class="text-body-secondary small mb-4" id="last-updated" hx-swap-oob="true">Created <?= dt($p->created_at) ?>. Last updated <?= dt($p->updated_at) ?>.</p>
    <?php
    exit;
}

echo json_encode($data);
