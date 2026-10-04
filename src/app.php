<?php
// Loaded by bootstrap.php for pages and actions that declared SLATE_REQUIRE_LOGIN, which is every
// one that needs a signed-in user. They require this file instead of views.php.
// It opens the session, resolves $me, and stops the request when there is no identity,
// so an endpoint can never be reached anonymously by forgetting to check.
require_once __DIR__ . '/views.php';

start_session();

/** The signed-in user id, or a redirect to sign in (setup on first run). */
function require_login(): int {
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid && user_exists($uid)) {
        return $uid;
    }
    if (isset($_SERVER['HTTP_HX_REQUEST'])) {
        http_response_code(401);
        exit;
    }
    // Only ask for the key before the first account exists; after that this link is
    // how a self-hosted operator can mint a replacement administrator.
    if (!credentials_count()) {
        go(base_url() . '/setup/');
    }
    // Remember where they were headed so sign-in can return them there.
    $next = $_SERVER['REQUEST_URI'] ?? '';
    go(base_url() . '/login/' . ($next ? '?next=' . rawurlencode($next) : ''));
}

/** As require_login, but for admin-only endpoints. */
function require_admin(): int {
    $uid = require_login();
    if (!is_admin($uid)) {
        bad('Admins only', 403);
    }
    return $uid;
}

$me = require_login();
