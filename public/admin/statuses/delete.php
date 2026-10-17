<?php
// Admin only: deletes a status. Refused while any project is on it — those projects would be left
// pointing at a slug with no row behind it, and the honest answer is to move them first rather than
// to decide for the admin where they should go.
//
// status_log is not touched: it is a record of what things were called at the time, and an entry
// for a deleted status still renders, with the slug as its label. board_panels rows for the status
// are deleted with it, since a column for a status that no longer exists is dead weight.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_method('DELETE');
require_admin();

$id = (int)(request_data()['id'] ?? 0);
$row = null;
foreach (statuses() as $s) {
    if ((int)$s['id'] === $id) {
        $row = $s;
    }
}
if (!$row) {
    bad('No such status', 404);
}

function statuses_error(string $msg): never {
    go('/admin/statuses/?error=' . rawurlencode($msg));
}

$on = (int)q('SELECT COUNT(*) FROM projects WHERE status=?', [$row['slug']])->fetchColumn();
if ($on > 0) {
    $where = $on === 1 ? '1 project is still on' : "$on projects are still on";
    statuses_error($where . ' ' . $row['label'] . ' — move them first');
}
if (count(statuses()) === 1) {
    statuses_error('A board needs somewhere for its projects to be: this is the last status');
}

q('DELETE FROM board_panels WHERE status=?', [$row['slug']]);
q('DELETE FROM project_statuses WHERE id=?', [$id]);
status_cache_clear();
go('/admin/statuses/');
