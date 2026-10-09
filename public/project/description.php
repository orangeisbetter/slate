<?php
// Saves the description flipped to its edit state on the project page. A full-page mutation,
// so it redirects back; the flip card opens on its view by default, so no fragment is needed.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
$id = (int)($_POST['id'] ?? 0);
if (!project($id)) bad('Project not found', 404);
q('UPDATE projects SET description=?, updated_at=? WHERE id=?', [$_POST['description'] ?? '', gmdate('c'), $id]);
go("/project/?id=$id");
