<?php
// The panel settings for one board as the contents of a dialog: one .modal-content, laid out as
// Bootstrap's modal example lays it out, which htmx drops into the empty div inside the .modal-dialog
// on /boards/.
//
// Its twin, boards/panels.php, is the same settings as a page of its own, for a browser with no htmx
// to upgrade the button. They are separate scripts rather than one branching on the HX-Request header
// so that neither is two things at once: this file is a fragment and nothing else, which is what lets
// it be the example's markup with no conditionals threaded through it. The rows are drawn by
// board_panel_rows(), so the two cannot disagree about them.
//
// The id on the .modal-content is the one the button's hx-target named, so it has to travel with the
// content: an outerHTML swap replaces the div it was aimed at, and without the id here a second visit
// to this dialog would have no target left to swap into and would open empty.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
$b = $id ? board($id) : null;
if (!$b) {
    bad('No such board', 404);
}

$modes = board_panel_modes($id);
$counts = board_status_counts()[$id] ?? [];
?>
<div class="modal-content" id="panels-content">
    <div class="modal-header">
        <h5 class="modal-title" id="panels-title">Panels &mdash; <?= h($b->name) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <?php /* The form is the body's and the footer sits beside it, which is the example's shape: a
                 .modal-content holding a header, a body and a footer as siblings. So the footer
                 cannot be inside the form, and its button says which form to submit through its form
                 attribute, which is what that attribute is for. */
        ?>
        <form method="post" action="/boards/save_panels.php" id="panels-form">
              <input type="hidden" name="id" value="<?= $id ?>">
              <?php board_panel_rows($id, $modes, $counts); ?>
        </form>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" form="panels-form">Save panels</button>
    </div>
</div>
