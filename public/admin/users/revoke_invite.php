<?php
// Admin only: deletes a pending invite so its link stops working.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_method('DELETE');
require_admin();

revoke_invite((string)(request_data()['token'] ?? ''));
go('/admin/users/');
