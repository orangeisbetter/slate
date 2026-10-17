<?php
// A project's board membership. Any member may do this: it is an edit, like changing assignees,
// and a project may end up on no board.
//
// Two shapes of answer, both partial, because htmx is doing the work here:
//
//   PATCH  the form sends its own rows plus one add or remove, and gets the form's contents back
//          to swap in, plus the dialog's list out of band so a thing just picked stops being
//          offered. Nothing in the answer contains a form or a dialog: both live on the page and
//          have to survive the swap. A plain form reaches the same endpoint by posting.
//   GET    mode=options returns just the dialog's list, filtered by q. That is what the search
//          box asks for, and it asks again each time the dialog opens.
//
// Without an HX-Request header it redirects instead, which is all a plain form post needs.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
    bad('GET, POST or PATCH only', 405);
}
$me = require_login();

$in = request_data();
$id = (int)($in['id'] ?? 0);
editable_project($id);   // an archived project is read-only, board membership included

$q = trim((string)($in['q'] ?? ''));

if ($method === 'GET') {
    // The dialog's list. Everything is rendered, one already chosen hidden rather than left out,
    // so removing it can put it back on offer.
    $chosen = project_board_ids($id);
    $options = board_picker_options($q ?: null);
    foreach ($options as $oid => $o):
        ?>
        <button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$oid, $chosen, true) ? ' d-none' : '' ?>"
                data-picker-option data-value="<?= (int)$oid ?>"
                hx-patch="/project/project_boards.php" hx-target="#boards-form" hx-swap="innerHTML"
                hx-include="#boards-form, #picker-boards-search" hx-vals='{"add":<?= (int)$oid ?>}'>
            <span class="text-truncate"><?= h($o['label']) ?></span>
            <?php if (!empty($o['sub'])): ?>
                <span class="small text-body-secondary text-truncate"><?= h($o['sub']) ?></span>
            <?php endif ?>
        </button>
    <?php endforeach;
    if (!array_diff_key($options, array_flip($chosen))) {
        echo '<div class="small text-body-secondary">No matches.</div>';
    }
    exit;
}

// The sent rows, plus at most one change to them. hx-include sends the rows already in the
// form, so a pick never has to invent an id the server did not already have.
$ids = array_map('intval', (array)($in['boards'] ?? []));
if ($add = (int)($in['add'] ?? 0)) {
    $ids[] = $add;
}
if ($drop = (int)($in['remove'] ?? 0)) {
    $ids = array_diff($ids, [$drop]);
}
set_project_boards($id, $ids, $me);

if (!isset($_SERVER['HTTP_HX_REQUEST'])) {
    go('/project/?id=' . $id);
}

$chosen = project_board_ids($id);
// $all is for the rows, which have to show every chosen board whatever the search box says.
// $options is the dialog's list, which is the one the filter applies to.
$all = board_picker_options();
$options = board_picker_options($q ?: null);
?>
<input type="hidden" name="id" value="<?= $id ?>">
<?php foreach ($chosen as $bid): $b = $all[$bid] ?? null; ?>
    <div class="d-flex align-items-center gap-2 border rounded bg-body-tertiary px-2 py-1 mb-1" data-picker-row data-value="<?= $bid ?>">
        <input type="hidden" name="boards[]" value="<?= $bid ?>">
        <span class="text-truncate"><a class="link-quiet" href="/board/?id=<?= $bid ?>"><?= h($b['label'] ?? 'Unknown') ?></a></span>
        <button type="button" class="btn-close ms-auto" aria-label="Remove <?= h($b['label'] ?? 'board') ?>"
                hx-patch="/project/project_boards.php" hx-target="#boards-form" hx-swap="innerHTML"
                hx-include="closest form, #picker-boards-search" hx-vals='{"remove":<?= $bid ?>}'></button>
    </div>
<?php endforeach ?>
<?php if (!$chosen): ?>
    <div class="small text-body-secondary mb-2">On no board.</div>
<?php endif ?>
<button type="button" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal"
        data-bs-target="#picker-boards-modal">Add board</button>
<div class="picker-list" id="picker-boards-options" hx-swap-oob="true">
    <?php foreach ($options as $oid => $o): ?>
        <button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$oid, $chosen, true) ? ' d-none' : '' ?>"
                data-picker-option data-value="<?= (int)$oid ?>"
                hx-patch="/project/project_boards.php" hx-target="#boards-form" hx-swap="innerHTML"
                hx-include="#boards-form, #picker-boards-search" hx-vals='{"add":<?= (int)$oid ?>}'>
            <span class="text-truncate"><?= h($o['label']) ?></span>
            <?php if (!empty($o['sub'])): ?>
                <span class="small text-body-secondary text-truncate"><?= h($o['sub']) ?></span>
            <?php endif ?>
        </button>
    <?php endforeach ?>
    <?php if (!array_diff_key($options, array_flip($chosen))): ?>
        <div class="small text-body-secondary">No matches.</div>
    <?php endif ?>
</div>
