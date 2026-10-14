<?php
// Admin only, and one-way: retires a project so it leaves every default view and board for good.
// There is no un-archive and no delete -- archiving is the only way a project leaves the screens,
// and this is where it happens. The project and everything attached to it -- subprojects, comments,
// status history and board memberships -- stay exactly where they are; the flag only hides it.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
require_admin();

$p = project((int)($_POST['id'] ?? 0));
if (!$p) {
    bad('Project not found', 404);
}

// Nothing is read from the body: an archive request means archive, and there is no other state to
// name. archive_project() leaves an already-archived project untouched, so a resubmitted form is
// harmless and the original date stands.
archive_project($p->id);
go('/project/?id=' . $p->id);
