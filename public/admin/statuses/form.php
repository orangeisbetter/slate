<?php
// The status form as a page of its own, for the browser that is not running htmx. The Edit and New
// status buttons on /admin/statuses/ are real hrefs aimed here, and their hx-get points at
// form-dialog.php instead, so this is where they land when the upgrade does not happen. Editing a
// status must not depend on a dialog being openable.
//
// Its twin, admin/statuses/form-dialog.php, answers the same status with the dialog's contents.
// They are separate scripts rather than one branching on the HX-Request header because each is then
// one thing: this one is a page, that one is a fragment. The fields themselves are drawn by
// status_form_fields() in views.php, so what they are called is decided once.
//
// With no id it is the New status form instead, which is why one script serves both: the two forms
// differ in whether there is a status behind them.
//
// It posts to save.php and delete.php, the endpoints the row buttons use, so there is no saving of
// its own to drift out of step with them.
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

page_start($id ? 'Edit status' : 'New status');
?>
<div class="page-center p-3 p-md-4">
    <div class="row justify-content-center w-100">
        <div class="col-sm-9 col-md-7 col-lg-5">
            <h1 class="h4"><?= $id ? 'Edit status' : 'New status' ?></h1>
            <form method="post" action="/admin/statuses/save.php">
                <input type="hidden" name="id" value="<?= $id ?>">
                <?php status_form_fields($s); ?>
                <?php /* Delete sits at the far end of the same row as saving and cancelling, which it
                         can only do by pointing at a form of its own: a <form> inside this one is
                         invalid, and a second form after it puts a destructive button on a row of
                         its own. When there is no status there is nothing to delete, so the row is
                         right-aligned instead of pushed apart. */
                ?>
                <div class="d-flex align-items-center gap-2<?= $id ? '' : ' justify-content-end' ?>">
                    <?php if ($id): ?>
                        <button type="submit" form="delete_status" class="btn btn-outline-danger me-auto"
                                formnovalidate>Delete</button>
                    <?php endif ?>
                    <a class="btn btn-outline-secondary" href="/admin/statuses/">Cancel</a>
                    <button class="btn btn-primary"><?= $id ? 'Save status' : 'Create status' ?></button>
                </div>
            </form>
            <?php if ($id): ?>
                <form id="delete_status" method="post" action="/admin/statuses/delete.php"
                      onsubmit="return confirm('Delete this status? It can only be deleted once no projects are on it.')">
                    <input type="hidden" name="id" value="<?= $id ?>">
                </form>
            <?php endif ?>
        </div>
    </div>
</div>
<?php
page_end();
