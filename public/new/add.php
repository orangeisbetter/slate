<?php
// Adds a project. This is the create form's own script. Adding a project underneath an existing
// one is project/add.php, which reaches the same function in src/lib.php.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
create_project_from_post($me);
