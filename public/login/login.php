<?php
// Checks credentials and starts a session. The form is /login/; this endpoint
// always redirects rather than rendering, so a reload cannot repost the password.
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}

start_session();

// A fixed, valid bcrypt hash. Every refusal verifies against it when there is no usable stored
// hash, so a miss costs exactly one bcrypt whether or not the address has an account — the reply
// time stops being a way to enumerate addresses. The value verifies nothing: nothing ever hashes
// to it, and it is not a hash of any known password.
const LOGIN_DUMMY_HASH = '$2y$10$1PTVqiFUDjrnfdTMZmLTMOkJtMmqCRF715mSk5x4b0lp0dtt.J9lS';

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$next = safe_next((string)($_POST['next'] ?? ''));

function login_refused(string $email): never {
    start_session();
    $_SESSION['login_error'] = login_throttled($email)
        ? 'Too many attempts. Wait a few minutes and try again.'
        : 'That email and password do not match an account.';
    go('/login/');
}

// Checked before the password so the throttle actually costs an attacker the work.
if (login_throttled($email)) {
    login_refused($email);
}

// A seeded user with no password_hash can never match, and needs an admin to invite
// them again rather than a password reset. A deactivated account is refused in the same breath
// and for the same reason as a wrong password: this form must not be a way of finding out which
// addresses have accounts, or which of those are switched off.
$user = user_by_email($email);
$live = $user !== null && $user->active && is_string($user->password_hash) && $user->password_hash !== '';
// The verify itself runs unconditionally — $stored is the dummy when the account is missing,
// switched off or passwordless — because a branch that skipped it would be quick to notice.
$stored = $live ? $user->password_hash : LOGIN_DUMMY_HASH;
$ok = check_password($password, $stored);
if (!$live || !$ok) {
    record_login_failure($email);
    login_refused($email);
}

clear_login_failures($email);

// Pick up a stronger algorithm if PHP's default has moved on since the password was set.
if (password_needs_rehash($user->password_hash, PASSWORD_DEFAULT)) {
    q('UPDATE users SET password_hash=? WHERE id=?', [hash_password($password), $user->id]);
}

login_user((int)$user->id);
go($next);
