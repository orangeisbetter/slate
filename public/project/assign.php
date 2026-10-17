<?php
// A project's assignees. Any member may do this: it is an edit.
//
// Two shapes of answer, both partial, because htmx is doing the work here:
//
//   PATCH  the form sends its own rows plus one add or remove, and gets the form's contents back
//          to swap in, plus the dialog's list out of band so a person just picked stops being
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
require_login();

$in = request_data();
$id = (int)($in['id'] ?? 0);
$p = editable_project($id);   // an archived project is read-only, assignees included

$q = trim((string)($in['q'] ?? ''));

if ($method === 'GET') {
    $chosen = array_map('intval', $p->assignees ?? []);
    $options = user_picker_options($q ?: null);
    foreach ($options as $uid => $u):
        ?>
        <button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$uid, $chosen, true) ? ' d-none' : '' ?>"
                data-picker-option data-value="<?= (int)$uid ?>"
                hx-patch="/project/assign.php" hx-target="#people-form" hx-swap="innerHTML"
                hx-include="#people-form, #picker-people-search" hx-vals='{"add":<?= (int)$uid ?>}'>
            <span class="text-truncate"><?= h($u['label']) ?></span>
            <?php if (!empty($u['sub'])): ?>
                <span class="small text-body-secondary text-truncate"><?= h($u['sub']) ?></span>
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
$ids = array_map('intval', (array)($in['as'] ?? []));
if ($add = (int)($in['add'] ?? 0)) {
    $ids[] = $add;
}
if ($drop = (int)($in['remove'] ?? 0)) {
    $ids = array_diff($ids, [$drop]);
}
// setAssignees has no check of its own and project_assignees points at users, so an id that is
// not a user has to be dropped here rather than turned into a constraint failure.
$ids = array_intersect(array_unique($ids), array_map('intval', array_keys(user_picker_options())));
setAssignees($id, $ids);
q('UPDATE projects SET updated_at=? WHERE id=?', [gmdate('c'), $id]);

if (!isset($_SERVER['HTTP_HX_REQUEST'])) {
    go("/project/?id=$id");
}

$chosen = array_map('intval', project($id)->assignees ?? []);
// $all is for the rows, which have to show everyone assigned whatever the search box says.
// $options is the dialog's list, which is the one the filter applies to.
$all = user_picker_options();
$options = user_picker_options($q ?: null);
?>
<input type="hidden" name="id" value="<?= $id ?>">
<?php foreach ($chosen as $uid): $u = $all[$uid] ?? null; ?>
    <div class="d-flex align-items-center gap-2 border rounded bg-body-tertiary px-2 py-1 mb-1" data-picker-row data-value="<?= $uid ?>">
        <input type="hidden" name="as[]" value="<?= $uid ?>">
        <span class="text-truncate"><?php user_link($uid, $u['label'] ?? 'Unknown') ?></span>
        <button type="button" class="btn-close ms-auto" aria-label="Remove <?= h($u['label'] ?? 'person') ?>"
                hx-patch="/project/assign.php" hx-target="#people-form" hx-swap="innerHTML"
                hx-include="closest form, #picker-people-search" hx-vals='{"remove":<?= $uid ?>}'></button>
    </div>
<?php endforeach ?>
<?php if (!$chosen): ?>
    <div class="small text-body-secondary mb-2">Nobody assigned yet.</div>
<?php endif ?>
<button type="button" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal"
        data-bs-target="#picker-people-modal">Add assignee</button>
<div class="picker-list" id="picker-people-options" hx-swap-oob="true">
    <?php foreach ($options as $uid => $u): ?>
        <button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$uid, $chosen, true) ? ' d-none' : '' ?>"
                data-picker-option data-value="<?= (int)$uid ?>"
                hx-patch="/project/assign.php" hx-target="#people-form" hx-swap="innerHTML"
                hx-include="#people-form, #picker-people-search" hx-vals='{"add":<?= (int)$uid ?>}'>
            <span class="text-truncate"><?= h($u['label']) ?></span>
            <?php if (!empty($u['sub'])): ?>
                <span class="small text-body-secondary text-truncate"><?= h($u['sub']) ?></span>
            <?php endif ?>
        </button>
    <?php endforeach ?>
    <?php if (!array_diff_key($options, array_flip($chosen))): ?>
        <div class="small text-body-secondary">No matches.</div>
    <?php endif ?>
</div>
