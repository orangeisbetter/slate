<?php
// Turns an invite into an account and signs the new user straight in.
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}

start_session();

$token = (string)($_POST['token'] ?? '');
// Re-checked here: the token came from a form, and it may have been used or revoked
// since the page was rendered.
$invite = invite_by_token($token);

$name = trim((string)($_POST['name'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if (!$invite) {
    go('/invite/?error=' . rawurlencode('This invitation is no longer valid'));
}
if ($name === '') {
    go('/invite/?token=' . rawurlencode($token) . '&error=' . rawurlencode('Name is required'));
}
if (strlen($password) < MIN_PASSWORD) {
    go('/invite/?token=' . rawurlencode($token)
       . '&error=' . rawurlencode('Passwords need at least ' . MIN_PASSWORD . ' characters'));
}
if ($password !== (string)($_POST['password2'] ?? '')) {
    go('/invite/?token=' . rawurlencode($token) . '&error=' . rawurlencode('Passwords do not match'));
}
if (user_by_email($invite['email'])) {
    go('/invite/?token=' . rawurlencode($token)
       . '&error=' . rawurlencode('That email address already has an account'));
}

$slugs = $invite['role_id'] && isset(all_roles()[$invite['role_id']])
    ? [q('SELECT slug FROM roles WHERE id=?', [$invite['role_id']])->fetchColumn()]
    : ['member'];

$id = create_user($name, $invite['email'], $password, $slugs);
q('UPDATE invites SET accepted_at=? WHERE token=?', [gmdate('c'), $token]);

login_user($id);
go('/index.php');
