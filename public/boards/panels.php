<?php
// The panel settings for one board as a page of its own: one row per status, saying what the board
// should do with it. For the browser that is not running htmx, because the Panels button on the boards
// page is a real href aimed here and its hx-get points at panels-dialog.php instead. Setting a panel
// does not depend on a dialog being openable.
//
// Its twin, boards/panels-dialog.php, answers the same board with the dialog's contents. They are
// separate scripts rather than one branching on the HX-Request header because each of them is then one
// thing: this one is a page, that one is a fragment, and neither has to decide while it is halfway
// through drawing. The rows themselves are drawn by board_panel_rows() in views.php, so what a status
// is called and what choosing Never would cost cannot differ between the two.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
$b = $id ? board($id) : null;
if (!$b) {
    bad('No such board', 404);
}

// Rewritten rather than diffed by save_panels.php, so nothing here decides whether a setting is
// worth a row. All this does is read what the board already has.
$modes = board_panel_modes($id);
$counts = board_status_counts()[$id] ?? [];

page_start('Panels');
?>
<div class="page-wide mx-auto p-4">
    <h1 class="h4">Panels</h1>
    <p class="text-body-secondary"><?= h($b['name']) ?></p>
    <form method="post" action="boards/save_panels.php">
        <input type="hidden" name="id" value="<?= $id ?>">
        <p class="small text-body-secondary">
            Every status can be a column on this board. A column set to <strong>Never</strong> takes its
            projects off the board without changing them: they keep their status, and they are still in
            the list view.
        </p>
        <?php board_panel_rows($id, $modes, $counts); ?>
        <?php // A right-aligned row rather than a .modal-footer, because there is no dialog here to
              // put a footer in. That is the only thing the page and the fragment disagree about. ?>
        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="boards/">Cancel</a>
            <button class="btn btn-primary">Save panels</button>
        </div>
    </form>
</div>
<?php
page_end();
