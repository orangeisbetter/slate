<?php
// One user's roles as the contents of a dialog: one .modal-content, laid out as Bootstrap's modal
// example lays it out, which htmx drops into the empty div inside the .modal-dialog on /admin/users/.
//
// Its twin, admin/users/roles-edit.php, is the same form as a page of its own, for a browser with no htmx
// to upgrade the button. They are separate scripts rather than one branching on the HX-Request
// header so that neither is two things at once. The fields are drawn by roles_form_fields() in
// views.php, so the two cannot disagree about them.
//
// The id on the .modal-content is the one the button's hx-target named, so it has to travel with the
// content: an outerHTML swap replaces the div it was aimed at, and without the id here a second visit
// to this dialog would have no target left to swap into and would open empty.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
if (!user_exists($id)) {
    bad('No such user', 404);
}
$target = user($id);
?>
<div class="modal-content" id="roles-content">
    <div class="modal-header">
        <h5 class="modal-title" id="roles-title">Roles &mdash; <?= h($target->name) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <form method="post" action="/admin/users/roles.php" id="roles-form">
            <input type="hidden" name="user_id" value="<?= $id ?>">
            <?php roles_form_fields($id); ?>
        </form>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" form="roles-form">Save roles</button>
    </div>
</div>
