<?php
// The panel settings for one board: one row per status, saying what the board should do with it.
// Admin only, because it changes the board.
//
// This is an htmx endpoint that is also a whole page. The Panels button on the boards page is a link
// to here carrying hx-get, so with JavaScript the fragment lands in the dialog and without it the
// browser follows the link and gets the same form on a page of its own. Setting a panel does not
// depend on a dialog being openable.
//
// It only ever answers a GET. The form posts to save_panels.php wherever it was rendered from, which
// is why there is no second copy of the saving here to drift out of step.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$me = require_admin();

$id = (int)($_GET['id'] ?? 0);
$b = $id ? board($id) : null;
if (!$b) {
    bad('No such board', 404);
}

$modes = board_panel_modes($id);
$counts = board_status_counts()[$id] ?? [];
$fragment = isset($_SERVER['HTTP_HX_REQUEST']);

if (!$fragment) {
    page_start('Panels');
}
?>
<?php if (!$fragment): ?>
    <div class="page-wide mx-auto p-4">
        <h1 class="h4">Panels</h1>
        <p class="text-body-secondary"><?= h($b['name']) ?></p>
<?php endif ?>
<form method="post" action="boards/save_panels.php">
    <input type="hidden" name="id" value="<?= $id ?>">
    <p class="small text-body-secondary">
        Every status can be a column on this board. A column set to <strong>Never</strong> takes its
        projects off the board without changing them: they keep their status, and they are still in
        the list view.
    </p>
    <div class="picker-list">
        <?php foreach (PANEL_ORDER as $status): $n = $counts[$status] ?? 0; ?>
            <?php // Plain rows, no card around each one: a name and its answer, in a list. A border
                  // and a fill per row made nine little boxes out of what is really one list. The
                  // picker-list wrapper is what keeps the list from growing past the dialog. ?>
            <div class="d-flex align-items-center gap-2 mb-1">
                <label class="text-truncate" for="panel-select-<?= $id ?>-<?= $status ?>">
                    <?= LABELS[$status] ?>
                    <?php if ($n): ?>
                        <span class="small text-body-secondary"><?= $n ?></span>
                    <?php endif ?>
                </label>
                <?php // A select rather than a segmented control: three buttons wide did not fit the
                      // dialog, and the answers do not need to be on screen together when the count
                      // beside the name already says what choosing Never would cost. That cost rides
                      // on the option itself, so it is there at the moment it is picked. ?>
                <select class="form-select w-auto ms-auto"
                        id="panel-select-<?= $id ?>-<?= $status ?>" name="panels[<?= $status ?>]">
                    <?php foreach (PANEL_MODES as $mode => $label):
                        $cost = $mode === 'hidden' && $n ? ' (' . $n . ')' : ''; ?>
                        <option value="<?= $mode ?>"<?= $modes[$status] === $mode ? ' selected' : '' ?>>
                            <?= $label ?><?= $cost ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
        <?php endforeach ?>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-3">
        <?php if (!$fragment): ?>
            <a class="btn btn-outline-secondary" href="boards/">Cancel</a>
        <?php endif ?>
        <button class="btn btn-primary">Save panels</button>
    </div>
</form>
<?php if (!$fragment): ?>
    </div>
    <?php
    page_end();
endif;
