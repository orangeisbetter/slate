<?php
// Admin only: mints an invite link. Redirects back with ?invite=<token> so the page can
// show the link once, rather than storing it in the session.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
$me = require_admin();

$email = trim((string)($_POST['email'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bad('Enter a valid email address');
}
// Zero or more roles, posted as roles[] of role ids. Anything not in the roles
// table is refused outright, so a hand-rolled POST cannot invent one.
$slug_map = q('SELECT id, slug FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);   // id => slug
$wanted = [];
foreach ((array)($_POST['roles'] ?? []) as $posted) {
    $slug = $slug_map[(int)$posted] ?? null;
    if ($slug === null) {
        bad('Unknown role');
    }
    $wanted[] = $slug;
}
$wanted = array_values(array_unique($wanted));

// An existing account cannot be invited: there would be no way to tell the two apart,
// and setting a new password would hand out a second way into the same identity.
if (user_by_email($email)) {
    bad('That email address already has an account');
}

$token = new_invite($email, $name ?: null, $wanted, $me);
go('/admin/users/?invite=' . rawurlencode($token));
