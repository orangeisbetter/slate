<?php
// Admin only: sets how a board treats each of its panels. Separate from save_board.php because the
// panels and the name are unrelated, and folding them into one form would let a rename quietly reset
// them to the default.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
$me = require_admin();

$id = (int)($_POST['id'] ?? 0);
if (!$id || !board($id)) {
    bad('No such board', 404);
}

// One field per status, named panels[todo] and so on. Anything not named is left alone, and a status
// that is not a column is refused rather than stored: the columns are PANEL_ORDER, which is every
// status a project can hold, so a row for anything else could only ever be dead weight.
$posted = $_POST['panels'] ?? [];
if (!is_array($posted)) {
    bad('Panels are posted as a set', 400);
}
foreach (array_keys($posted) as $status) {
    if (!in_array($status, PANEL_ORDER, true)) {
        go('/boards/?error=' . rawurlencode('Unknown panel: ' . $status));
    }
    if (!isset(PANEL_MODES[$posted[$status]])) {
        go('/boards/?error=' . rawurlencode('Unknown panel setting: ' . $posted[$status]));
    }
}

// Rewritten rather than diffed: the board has one small set of rows, and deleting them all first
// means a panel dropped from the form goes back to the default instead of keeping a stale setting.
q('DELETE FROM board_panels WHERE board_id=?', [$id]);
foreach (PANEL_ORDER as $status) {
    $mode = $posted[$status] ?? null;
    if ($mode === null) {
        continue;   // not named: whatever the default is, which is what an absent row means
    }
    $default = in_array($status, DEFAULT_BOARD_PANELS, true) ? 'required' : 'hidden';
    if ($mode !== $default) {
        q('INSERT INTO board_panels(board_id,status,mode) VALUES(?,?,?)', [$id, $status, $mode]);
    }
}

go('/boards/');
