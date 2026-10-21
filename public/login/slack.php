<?php
// Start Slack OAuth sign-in. Redirects to Slack's authorization URL.
require __DIR__ . '/../../src/bootstrap.php';

start_session();

$client_id = (string)getenv('SLACK_CLIENT_ID');
if ($client_id === '') {
    bad('Slack sign-in is not configured', 500);
}

$next = safe_next((string)($_GET['next'] ?? ''));
$_SESSION['slack_next'] = $next;
$_SESSION['slack_state'] = bin2hex(random_bytes(16));
$_SESSION['slack_nonce'] = bin2hex(random_bytes(16));
$me = session_user_id();
if ($me) {
    $_SESSION['slack_linking'] = $me;
} else {
    unset($_SESSION['slack_linking']);
}

$redirect_uri = (string)getenv('SLACK_REDIRECT_URI');
if ($redirect_uri === '') {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $redirect_uri = ($https ? 'https://' : 'http://') . $host . '/login/slack-callback.php';
}

$scopes = 'openid profile email';
$params = http_build_query([
    'client_id' => $client_id,
    'scope' => $scopes,
    'redirect_uri' => $redirect_uri,
    'state' => $_SESSION['slack_state'],
    'response_type' => 'code',
    'nonce' => $_SESSION['slack_nonce'],
]);
go('https://slack.com/openid/connect/authorize?' . $params);
