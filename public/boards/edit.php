<?php
// The board form as a page of its own, for the browser that is not running htmx. The Edit and New
// board buttons on /boards/ are real hrefs aimed here, and their hx-get points at edit-dialog.php
// instead, so this is where they land when the upgrade does not happen. Editing a board must not
// depend on a dialog being openable.
//
// Its twin, boards/edit-dialog.php, answers the same board with the dialog's contents. The two are
// separate scripts rather than one branching on the HX-Request header because each of them is then
// one thing: this one is a page, that one is a fragment, and neither has to decide while it is
// halfway through drawing. The fields themselves are drawn by board_form_fields() in views.php, so
// what they are called and how long they may be is decided once.
//
// With no id it is the New board form instead, which is why one script serves both: the two forms
// differ in whether there is a board behind them, and a second script would differ in more ways than
// that.
//
// It posts to save_board.php and delete_board.php, the endpoints the inline forms used, so there is
// no saving of its own to drift out of step with them: one place that writes a board is one place to
// be wrong about what a board needs.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
$b = $id ? board($id) : null;
if ($id && !$b) {
    bad('No such board', 404);
}

page_start($id ? 'Edit board' : 'New board');
?>
<div class="page-center p-3 p-md-4">
    <div class="row justify-content-center w-100">
        <div class="col-sm-9 col-md-7 col-lg-5">
            <h1 class="h4"><?= $id ? 'Edit board' : 'New board' ?></h1>
            <?php if ($b): ?>
                <p class="text-body-secondary"><?= h($b['name']) ?></p>
            <?php endif ?>
            <form method="post" action="boards/save_board.php">
                <input type="hidden" name="id" value="<?= $id ?>">
                <?php board_form_fields($b); ?>
                <?php /* Delete sits at the far end of the same row as saving and cancelling, which it
                         can only do by pointing at a form of its own: a <form> inside this one is
                         invalid, and a second form after it puts a destructive button on a row of its
                         own. When there is no board there is nothing to delete, so the row is
                         right-aligned instead of pushed apart. On a page there is no dialog and so no
                         .modal-footer to be a row of, which is the only thing that differs here. */
                ?>
                <div class="d-flex align-items-center gap-2<?= $id ? '' : ' justify-content-end' ?>">
                    <?php if ($id): ?>
                        <button type="submit" form="delete_board" class="btn btn-outline-danger me-auto"
                                formnovalidate>Delete board</button>
                    <?php endif ?>
                    <a class="btn btn-outline-secondary" href="boards/">Cancel</a>
                    <button class="btn btn-primary"><?= $id ? 'Save board' : 'Create board' ?></button>
                </div>
            </form>
            <?php if ($id): ?>
                <form id="delete_board" method="post" action="boards/delete_board.php"
                      onsubmit="return confirm('Delete this board? Its projects stay, but on no board.')">
                    <input type="hidden" name="id" value="<?= $id ?>">
                </form>
            <?php endif ?>
        </div>
    </div>
</div>
<?php
page_end();
