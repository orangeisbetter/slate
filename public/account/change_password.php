<?php
// The signed-in user changing their own password. The current one is required: without it, anyone
// who reached an already-signed-in browser -- a shared machine left open, a borrowed session -- could
// take the account over permanently, and the owner would have no way back in.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

// The new password replaces the old one outright, which is what a PUT means; a plain form posts.
require_method('PUT');
$me = require_login();

$account = user($me);
$in = request_data();
$current = (string)($in['current'] ?? '');
$password = (string)($in['password'] ?? '');

// Both outcomes land back on the Sign-in pane. The fragment is applied by the browser after
// it follows the redirect, so the server does not need to be told which pane to render.
$refuse = fn(string $why) => go('/account/?error=' . rawurlencode($why) . '#tab-signin');

// Checked before the new one is looked at, so a wrong current password is the only thing reported.
if (!check_password($current, $account->password_hash)) {
    $refuse('That is not your current password');
}
if (strlen($password) < MIN_PASSWORD) {
    $refuse('A password needs at least ' . MIN_PASSWORD . ' characters');
}
if ($password !== (string)($in['password2'] ?? '')) {
    $refuse('The two new passwords do not match');
}

// The rows go with the hash: every other session had one of its own, and they are deleted
// alongside the old hash, so those browsers are refused by require_login() on their next
// request -- changing the password signs everyone else out of the account rather than only
// rotating this one id. This session keeps its row, refreshed below by login_user().
q('UPDATE users SET password_hash=? WHERE id=?', [hash_password($password), $me]);
q('DELETE FROM sessions WHERE user_id=? AND id<>?', [$me, session_id_hash()]);

// A new session id, as at sign-in: the credential behind this session has just changed, so it is a
// privilege change like any other and the old id should not still be good.
login_user($me);

go('/account/?saved=' . rawurlencode('Password changed') . '#tab-signin');
