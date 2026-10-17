<?php
// Admin only: switches an account's sign-in on or off. Deactivating keeps the account and everything
// it authored; it just stops the account signing in, and stops any session it already has.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_method('PATCH');
$me = require_admin();

$in = request_data();
$target = (int)($in['user_id'] ?? 0);
if (!user_exists($target)) {
    bad('No such user', 404);
}

// The posted state is read as an explicit yes or no rather than flipped from what it is now, so a
// form submitted twice cannot undo the first submission by landing on the other end of a toggle.
$posted = $in['active'] ?? '';
if ($posted !== '0' && $posted !== '1') {
    bad('Say whether the account should be active');
}
$active = $posted === '1';

if (!$active) {
    // Same reasoning as roles.php, and for the same reason: with no admin left who can sign in,
    // nobody can invite or promote anybody again, and recovery needs SLATE_SETUP_KEY, which a plain
    // install does not set. This is also what covers an admin switching themselves off while they
    // are the last one -- there is nobody left to switch them back on, and the account page they
    // would use to fix it needs a session they no longer have.
    if (has_role($target, Role::Admin) && admin_count(true) <= 1) {
        bad('This is the only admin who can still sign in. Promote someone else first.');
    }
    // Deactivating yourself is allowed as long as the guard above is satisfied, because that is how
    // the last admin hands over: promote a colleague, then switch yourself off. The session you are
    // holding dies on your next request, which is the same thing that happens to anybody else.
}

set_user_active($target, $active);
go('/admin/users/');
