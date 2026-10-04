<?php
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    bad('PATCH only', 405);
}

$raw_input = file_get_contents("php://input");
parse_str($raw_input, $data);

echo json_encode($data);

$p = project((int)($data['id'] ?? 0));
$st = $data['status'] ?? '';

if ($p && isset(LABELS[$st]) && $st !== $p['status']) {
    q('UPDATE projects SET status = ?, updated_at = ? WHERE id = ?', [$st, gmdate('c'), $p['id']]);
    logStatus($p['id'], $p['status'], $st);
}
