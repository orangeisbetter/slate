<?php
// Updates the project being viewed. Adding a project is add.php next door, which is a different
// operation and a different function. Assignees and boards are not touched here either: they are
// assign.php and project_boards.php.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
update_project_from_post();
