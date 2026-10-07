<?php
// Handles first-run setup. Sets the account up and signs it in, so the operator is not
// left on a page they cannot get back to. Failures redirect back with ?error=...
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}

start_session();

if ((int)($_SESSION['uid'] ?? 0)) {
    go('/index.php');
}

// SLATE_SETUP_KEY is optional on a fresh install and mandatory once an account exists,
// which is what keeps this endpoint from being a way to add admins anonymously.
$setup_key = (string)(getenv('SLATE_SETUP_KEY') ?: '');
if (credentials_count() && $setup_key === '') {
    bad('Setup is already complete', 403);
}
if ($setup_key !== '' && !hash_equals($setup_key, (string)($_POST['setup_key'] ?? ''))) {
    go('/setup/?error=' . rawurlencode('Incorrect setup key'));
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($name === '') {
    go('/setup/?error=' . rawurlencode('Name is required'));
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    go('/setup/?error=' . rawurlencode('Enter a valid email address'));
}
if (strlen($password) < MIN_PASSWORD) {
    go('/setup/?error=' . rawurlencode('Passwords need at least ' . MIN_PASSWORD . ' characters'));
}
if ($password !== (string)($_POST['password2'] ?? '')) {
    go('/setup/?error=' . rawurlencode('Passwords do not match'));
}
if (user_by_email($email)) {
    go('/setup/?error=' . rawurlencode('That email address already has an account'));
}

$id = create_user($name, $email, $password, ['admin', 'member']);
login_user($id);
go('/index.php');
