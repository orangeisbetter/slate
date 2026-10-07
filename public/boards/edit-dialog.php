<?php
// The board form as the contents of a dialog: one .modal-content, laid out as Bootstrap's modal
// example lays it out, which htmx drops into the empty div inside the .modal-dialog on /boards/.
//
// Its twin, boards/edit.php, is the same form as a page of its own, for a browser with no htmx to
// upgrade the button. They are separate scripts rather than one branching on the HX-Request header so
// that neither is two things at once: this file is a fragment and nothing else, which is what lets it
// be the example's markup with no conditionals threaded through it. The fields are drawn by
// board_form_fields(), so the two cannot disagree about them.
//
// The id on the .modal-content is the one the button's hx-target named, so it has to travel with the
// content: an outerHTML swap replaces the div it was aimed at, and without the id here a second visit
// to this dialog would have no target left to swap into and would open empty.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
$b = $id ? board($id) : null;
if ($id && !$b) {
    bad('No such board', 404);
}
?>
<div class="modal-content" id="board-content">
    <div class="modal-header">
        <h5 class="modal-title" id="board-title">
            <?= $id ? 'Edit board &mdash; ' . h($b['name']) : 'New board' ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <?php /* The form is the body's and the footer sits beside it, which is the example's shape: a
                 .modal-content holding a header, a body and a footer as siblings. So the footer
                 cannot be inside the form, and its button says which form to submit through its form
                 attribute, which is what that attribute is for. */
        ?>
        <form method="post" action="/boards/save_board.php" id="board-form">
            <input type="hidden" name="id" value="<?= $id ?>">
            <?php board_form_fields($b); ?>
        </form>
    </div>
    <?php /* Delete sits at the far end of the same row as saving and cancelling, which it can only do
             by pointing at a form of its own: a <form> inside the other one is invalid, and a second
             form after this one has nowhere to go in a dialog whose content is a header, a body and a
             footer. When there is no board there is nothing to delete, so the row is right-aligned
             instead of pushed apart. */
    ?>
    <div class="modal-footer">
        <?php if ($id): ?>
            <button type="submit" form="delete_board" class="btn btn-outline-danger me-auto"
                    formnovalidate>Delete board</button>
        <?php endif ?>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" form="board-form"><?= $id ? 'Save board' : 'Create board' ?></button>
    </div>
    <?php if ($id): ?>
        <form id="delete_board" method="post" action="/boards/delete_board.php"
              onsubmit="return confirm('Delete this board? Its projects stay, but on no board.')">
            <input type="hidden" name="id" value="<?= $id ?>">
        </form>
    <?php endif ?>
</div>
