<?php
// Saves the description flipped to its edit state on the project page. A full-page mutation,
// so it redirects back; the flip card opens on its view by default, so no fragment is needed.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
require_method('PATCH');
$in = request_data();
$id = (int)($in['id'] ?? 0);
editable_project($id);   // an archived project is read-only, description included
q('UPDATE projects SET description=?, updated_at=? WHERE id=?', [$in['description'] ?? '', gmdate('c'), $id]);
go("/project/?id=$id");
