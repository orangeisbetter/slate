<?php
// The status form as the contents of a dialog: one .modal-content, laid out as Bootstrap's modal
// example lays it out, which htmx drops into the empty div inside the .modal-dialog on
// /admin/statuses/.
//
// Its twin, admin/statuses/form.php, is the same form as a page of its own, for a browser with no
// htmx to upgrade the button. They are separate scripts rather than one branching on the HX-Request
// header so that neither is two things at once: this file is a fragment and nothing else. The fields
// are drawn by status_form_fields(), so the two cannot disagree about them.
//
// The id on the .modal-content is the one the button's hx-target named, so it has to travel with the
// content: an outerHTML swap replaces the div it was aimed at, and without the id here a second
// visit to this dialog would have no target left to swap into and would open empty.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
$s = null;
if ($id) {
    foreach (statuses() as $row) {
        if ((int)$row['id'] === $id) {
            $s = $row;
        }
    }
    if (!$s) {
        bad('No such status', 404);
    }
}
?>
<div class="modal-content" id="status-content">
    <div class="modal-header">
        <h5 class="modal-title" id="status-title">
            <?= $id ? 'Edit status &mdash; ' . h($s['label']) : 'New status' ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <?php /* The form is the body's and the footer sits beside it, which is the example's shape:
                 a .modal-content holding a header, a body and a footer as siblings. So the footer
                 cannot be inside the form, and its button says which form to submit through its form
                 attribute, which is what that attribute is for. */
        ?>
        <form method="post" action="/admin/statuses/save.php" id="status-form">
            <input type="hidden" name="id" value="<?= $id ?>">
            <?php status_form_fields($s); ?>
        </form>
    </div>
    <?php /* Delete sits at the far end of the same row as saving and cancelling, which it can only
             do by pointing at a form of its own: a <form> inside the other one is invalid, and a
             second form after this one has nowhere to go in a dialog whose content is a header, a
             body and a footer. When there is no status there is nothing to delete, so the row is
             right-aligned instead of pushed apart. */
    ?>
    <div class="modal-footer">
        <?php if ($id): ?>
            <button type="submit" form="delete_status" class="btn btn-outline-danger me-auto"
                    formnovalidate>Delete</button>
        <?php endif ?>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" form="status-form"><?= $id ? 'Save status' : 'Create status' ?></button>
    </div>
    <?php if ($id): ?>
        <form id="delete_status" method="post" action="/admin/statuses/delete.php"
              onsubmit="return confirm('Delete this status? It can only be deleted once no projects are on it.')">
            <input type="hidden" name="id" value="<?= $id ?>">
        </form>
    <?php endif ?>
</div>
