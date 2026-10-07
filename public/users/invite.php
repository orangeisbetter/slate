<?php
// Admin only: mints an invite link. Redirects back with ?invite=<token> so the page can
// show the link once, rather than storing it in the session.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
$me = require_admin();

$email = trim((string)($_POST['email'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$role_id = (int)($_POST['role_id'] ?? 0);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bad('Enter a valid email address');
}
if ($role_id && !isset(all_roles()[$role_id])) {
    bad('Unknown role');
}
// An existing account cannot be invited: there would be no way to tell the two apart,
// and setting a new password would hand out a second way into the same identity.
if (user_by_email($email)) {
    bad('That email address already has an account');
}

$token = new_invite($email, $name ?: null, $role_id ?: null, $me);
go('/users/?invite=' . rawurlencode($token));
