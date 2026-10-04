<?php
// Checks credentials and starts a session. The form is /login/; this endpoint
// always redirects rather than rendering, so a reload cannot repost the password.
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}

start_session();

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$next = safe_next((string)($_POST['next'] ?? ''));

function login_refused(string $email): never {
    start_session();
    $_SESSION['login_error'] = login_throttled($email)
        ? 'Too many attempts. Wait a few minutes and try again.'
        : 'That email and password do not match an account.';
    go(base_url() . '/login/');
}

// Checked before the password so the throttle actually costs an attacker the work.
if (login_throttled($email)) {
    login_refused($email);
}

// A seeded user with no password_hash can never match, and needs an admin to invite
// them again rather than a password reset.
$user = user_by_email($email);
if (!$user || !check_password($password, $user['password_hash'])) {
    record_login_failure($email);
    login_refused($email);
}

clear_login_failures($email);

// Pick up a stronger algorithm if PHP's default has moved on since the password was set.
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    q('UPDATE users SET password_hash=? WHERE id=?', [hash_password($password), $user['id']]);
}

login_user((int)$user['id']);
go('../' . ltrim($next, '/'));
