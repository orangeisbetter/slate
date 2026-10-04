<?php
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
require_admin();   // deleting cascades to subprojects and comments, so it is admin-only
$p = project((int)($_POST['id'] ?? 0));
if (!$p) bad('Project not found', 404);
q('DELETE FROM projects WHERE id=?', [$p['id']]);   // subprojects and comments cascade
go(base_url() . ($p['parent_id'] ? '/project/?id=' . $p['parent_id'] : '/index.php'));
