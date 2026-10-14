<?php
// Handles Slack OAuth callback.
require __DIR__ . '/../../src/bootstrap.php';

start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    bad('GET only', 405);
}

$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
$error = (string)($_GET['error'] ?? '');

if ($error !== '') {
    $_SESSION['login_error'] = 'Slack sign-in was cancelled or failed.';
    go('/login/');
}

if ($state === '' || $code === '' || !isset($_SESSION['slack_state']) || $_SESSION['slack_state'] !== $state) {
    $_SESSION['login_error'] = 'Invalid Slack sign-in request.';
    go('/login/');
}
unset($_SESSION['slack_state']);
if (isset($_SESSION['slack_nonce'])) {
    $expected_nonce = $_SESSION['slack_nonce'];
    unset($_SESSION['slack_nonce']);
} else {
    $expected_nonce = null;
}
$linking_to = isset($_SESSION['slack_linking']) ? (int)$_SESSION['slack_linking'] : 0;
unset($_SESSION['slack_linking']);

$client_id = (string)getenv('SLACK_CLIENT_ID');
$client_secret = (string)getenv('SLACK_CLIENT_SECRET');
if ($client_id === '' || $client_secret === '') {
    bad('Slack sign-in is not configured', 500);
}

$redirect_uri = (string)getenv('SLACK_REDIRECT_URI');
if ($redirect_uri === '') {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $redirect_uri = ($https ? 'https://' : 'http://') . $host . '/login/slack-callback.php';
}

$post_data = http_build_query([
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'code' => $code,
    'redirect_uri' => $redirect_uri,
    'grant_type' => 'authorization_code',
]);
$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $post_data,
        'timeout' => 30,
    ],
];
$context = stream_context_create($opts);
$res = @file_get_contents('https://slack.com/api/openid.connect.token', false, $context);
if ($res === false) {
    $_SESSION['login_error'] = 'Failed to complete Slack sign-in.';
    go('/login/');
}
$token = json_decode($res, true);

if (empty($token['ok']) || empty($token['access_token'])) {
    $_SESSION['login_error'] = 'Failed to complete Slack sign-in.';
    go('/login/');
}

$opts = [
    'http' => [
        'method'  => 'GET',
        'header'  => 'Authorization: Bearer ' . $token['access_token'] . "\r\nContent-Type: application/json",
        'timeout' => 30,
    ],
];
$context = stream_context_create($opts);
$res = @file_get_contents('https://slack.com/api/openid.connect.userInfo', false, $context);
if ($res === false) {
    $_SESSION['login_error'] = 'Failed to retrieve Slack user info.';
    go('/login/');
}
$userinfo = json_decode($res, true);

if (empty($userinfo['ok'])) {
    $_SESSION['login_error'] = 'Failed to retrieve Slack user info.';
    go('/login/');
}

$slack_id = (string)($userinfo['sub'] ?? $userinfo['user_id'] ?? '');
$name = (string)($userinfo['name'] ?? $userinfo['display_name'] ?? $userinfo['real_name'] ?? 'Slack User');
$email = (string)($userinfo['email'] ?? '');

if ($slack_id === '') {
    $_SESSION['login_error'] = 'Slack sign-in did not return a user id.';
    go('/login/');
}

// Optional workspace gate: with SLACK_ALLOWED_TEAM_ID set, only members of that workspace
// can sign in or link, so Slack itself keeps external people out rather than invite-only
// accounts alone. Checked here, before linking and before sign-in, so all three paths
// (link, login, signup) pass through it. A response naming no workspace is refused rather
// than waved through, since waving it through would be the gate left open.
$allowed_team = trim((string)getenv('SLACK_ALLOWED_TEAM_ID'));
if ($allowed_team !== '') {
    $team_id = slack_team_id($userinfo, is_array($token) ? $token : []);
    if ($team_id === '') {
        $_SESSION['login_error'] = 'Could not verify your Slack workspace. Contact an administrator.';
        go('/login/');
    }
    if ($team_id !== $allowed_team) {
        $_SESSION['login_error'] = 'Slack sign-in is limited to members of a specific workspace.';
        go('/login/');
    }
}

$next = isset($_SESSION['slack_next']) ? (string)$_SESSION['slack_next'] : '/index.php';
unset($_SESSION['slack_next']);

if ($linking_to > 0) {
    $linking_user = user($linking_to);
    if (!$linking_user || !$linking_user->active) {
        $_SESSION['login_error'] = 'That account has been deactivated.';
        go('/login/');
    }
    $existing_slack = user_by_slack_id($slack_id);
    if ($existing_slack && (int)$existing_slack->id !== $linking_to) {
        $_SESSION['login_error'] = 'This Slack account is already linked to another account.';
        go('/login/');
    }
    link_slack($linking_to, $slack_id);
    login_user($linking_to);
    go($next);
}

$user = user_by_slack_id($slack_id);
if ($user) {
    if (!$user->active) {
        $_SESSION['login_error'] = 'That account has been deactivated.';
        go('/login/');
    }
    login_user((int)$user->id);
    go($next);
}

if ($email !== '') {
    $by_email = user_by_email($email);
    if ($by_email) {
        $_SESSION['login_error'] = 'This email is already associated with an account. Sign in with your password, then link Slack from your account settings.';
        go('/login/');
    }
}

$allow_signup = (bool)getenv('SLACK_ALLOW_SIGNUP');

if (!$allow_signup) {
    $_SESSION['login_error'] = 'Slack sign-ups are disabled. Contact an administrator.';
    go('/login/');
}

$role_slugs = [];
try {
    $id = create_user_from_slack($name, $email !== '' ? $email : null, $slack_id, $role_slugs);
    login_user($id);
    go($next);
} catch (Throwable $e) {
    $_SESSION['login_error'] = 'Could not create account from Slack sign-in.';
    go('/login/');
}
