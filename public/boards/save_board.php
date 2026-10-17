<?php
// Admin only: creates a board, or renames one when an id arrives. PUT renames and POST creates,
// but a plain form posts either way, and so the verb is not what tells the two apart: the id is.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

// PUT renames a board; POST creates one (or renames, from a plain form without an id).
require_method('PUT');
$me = require_admin();

$in = request_data();
$id = (int)($in['id'] ?? 0);
$name = trim((string)($in['name'] ?? ''));
$description = trim((string)($in['description'] ?? ''));

if ($name === '') {
    go('/boards/?error=' . rawurlencode('A board needs a name'));
}
if (chars($name) > 80) {
    go('/boards/?error=' . rawurlencode('Board names are limited to 80 characters'));
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

go('/boards/');
