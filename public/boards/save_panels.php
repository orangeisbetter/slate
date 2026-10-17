<?php
// Admin only: sets how a board treats each of its panels. Separate from save_board.php because the
// panels and the name are unrelated, and folding them into one form would let a rename quietly reset
// them to the default.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

// The posted set replaces the board's panels outright, which is what a PUT means; a plain form
// reaches it by posting.
require_method('PUT');
$me = require_admin();

$in = request_data();
$id = (int)($in['id'] ?? 0);
if (!$id || !board($id)) {
    bad('No such board', 404);
}

// One field per status, named panels[todo] and so on. Anything not named is left alone, and a status
// that is not a column is refused rather than stored: the columns are panel_order(), which is every
// status a project can hold, so a row for anything else could only ever be dead weight.
$posted = $in['panels'] ?? [];
if (!is_array($posted)) {
    bad('Panels are posted as a set', 400);
}
foreach (array_keys($posted) as $status) {
    if (!in_array($status, panel_order(), true)) {
        go('/boards/?error=' . rawurlencode('Unknown panel: ' . $status));
    }
    if (!isset(PANEL_MODES[$posted[$status]])) {
        go('/boards/?error=' . rawurlencode('Unknown panel setting: ' . $posted[$status]));
    }
}

// Rewritten rather than diffed: the board has one small set of rows, and deleting them all first
// means a panel dropped from the form goes back to the default instead of keeping a stale setting.
q('DELETE FROM board_panels WHERE board_id=?', [$id]);
foreach (panel_order() as $status) {
    $mode = $posted[$status] ?? null;
    if ($mode === null) {
        continue;   // not named: whatever the default is, which is what an absent row means
    }
    $default = in_array($status, default_board_panels(), true) ? 'required' : 'hidden';
    if ($mode !== $default) {
        q('INSERT INTO board_panels(board_id,status,mode) VALUES(?,?,?)', [$id, $status, $mode]);
    }
}

go('/boards/');
