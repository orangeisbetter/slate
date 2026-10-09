<?php
// Admin only: replaces a user's roles. Members can be made admins and vice versa.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
require_admin();

$target = (int)($_POST['user_id'] ?? 0);
if (!user_exists($target)) {
    bad('No such user', 404);
}

// The form posts role ids; anything not in the roles table is discarded, so a
// hand-rolled POST cannot invent one.
$id_to_slug = q('SELECT id, slug FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
$wanted = [];
foreach ((array)($_POST['roles'] ?? []) as $posted) {
    $slug = $id_to_slug[(int)$posted] ?? null;
    if ($slug !== null) {
        $wanted[] = $slug;
    }
}

// Losing the last admin would leave nobody able to invite or promote anyone again, and
// recovery needs SLATE_SETUP_KEY, which a plain install does not set.
if (has_role($target, Role::Admin) && !in_array(Role::Admin->value, $wanted, true) && admin_count() <= 1) {
    bad('This is the only admin. Promote someone else first.');
}

set_roles($target, $wanted);
go('/admin/users/');
