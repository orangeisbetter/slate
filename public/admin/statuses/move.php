<?php
// Admin only: moves a status one place up or down the order. The whole order is rewritten rather
// than two rows swapped, so sort_order stays the dense 1..n the table was seeded with and no two
// rows can end up sharing a position.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_method('PATCH');
require_admin();

$in = request_data();
$id = (int)($in['id'] ?? 0);
$dir = $in['dir'] ?? '';
if ($dir !== 'up' && $dir !== 'down') {
    bad('Move up or down', 400);
}

$rows = statuses();
$order = array_column($rows, 'id');
$at = array_search($id, array_map('intval', $order), true);
if ($at === false) {
    bad('No such status', 404);
}
$to = $dir === 'up' ? $at - 1 : $at + 1;
if ($to < 0 || $to >= count($order)) {
    go('/admin/statuses/');   // already first or last: nothing to do, nothing to complain about
}

[$order[$at], $order[$to]] = [$order[$to], $order[$at]];
foreach ($order as $i => $sid) {
    q('UPDATE project_statuses SET sort_order=? WHERE id=?', [$i + 1, $sid]);
}
status_cache_clear();
go('/admin/statuses/');
