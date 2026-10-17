<?php
// Admin only: deletes a board. project_boards cascades, so the projects themselves survive
// and simply end up on no board, where the list view and their own pages still find them.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_method('DELETE');
require_admin();

$id = (int)(request_data()['id'] ?? 0);
if (!board($id)) {
    bad('No such board', 404);
}

q('DELETE FROM boards WHERE id=?', [$id]);
go('/boards/');
