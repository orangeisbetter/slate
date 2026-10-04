<?php
// Admin only: creates a board, or renames one when an id is posted.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
$me = require_admin();

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));

if ($name === '') {
    go(base_url() . '/boards/?error=' . rawurlencode('A board needs a name'));
}
if (mb_strlen($name) > 80) {
    go(base_url() . '/boards/?error=' . rawurlencode('Board names are limited to 80 characters'));
}

if ($id) {
    if (!board($id)) {
        bad('No such board', 404);
    }
    q('UPDATE boards SET name=?, description=? WHERE id=?', [$name, $description, $id]);
} else {
    q('INSERT INTO boards(name,description,created_by,created_at) VALUES(?,?,?,?)',
      [$name, $description, $me, gmdate('c')]);
}

go(base_url() . '/boards/');
