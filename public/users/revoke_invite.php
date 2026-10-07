<?php
// Admin only: deletes a pending invite so its link stops working.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
require_admin();

revoke_invite((string)($_POST['token'] ?? ''));
go('/users/');
