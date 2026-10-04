<?php
// Adds a project underneath the one being viewed. new/add.php adds one with no parent and reaches
// the same function in src/lib.php.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
create_project_from_post($me);
