<?php
// Loaded by bootstrap.php for pages and actions that declared SLATE_REQUIRE_LOGIN, which is every
// one that needs a signed-in user. They require this file instead of views.php.
// It opens the session, resolves $me, and stops the request when there is no identity,
// so an endpoint can never be reached anonymously by forgetting to check.
require_once __DIR__ . '/views.php';

start_session();

/** The signed-in user id, or a redirect to sign in (setup on first run). */
function require_login(): int {
    global $db;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid && user_exists($uid) && user_is_active($uid)) {
        // A password change bumps users.session_version, which invalidates every session that
        // was stamped with the old value — this one included. One extra indexed read on a table
        // the checks above have already touched, rather than a session store of its own.
        $sv = (int)$db->query('SELECT session_version FROM users WHERE id=' . $uid)->fetchColumn();
        if ((int)($_SESSION['sv'] ?? -1) === $sv) {
            return $uid;
        }
    }
    // A session whose account has since been deleted, deactivated or had its credential changed
    // is thrown away rather than just sent back to the sign-in form. Redirecting alone would leave
    // the cookie in place, and re-activating or re-typing the password would then hand the old
    // session straight back its access without anyone signing in again -- which is the one thing
    // deactivating an account or changing a password must not do.
    if ($uid) {
        logout_user();
    }
    if (isset($_SERVER['HTTP_HX_REQUEST'])) {
        http_response_code(401);
        exit;
    }
    // Only ask for the key before the first account exists; after that this link is
    // how a self-hosted operator can mint a replacement administrator.
    if (!credentials_count()) {
        go('/setup/');
    }
    // Remember where they were headed so sign-in can return them there.
    $next = $_SERVER['REQUEST_URI'] ?? '';
    go('/login/' . ($next ? '?next=' . rawurlencode($next) : ''));
}

/** As require_login, but for admin-only endpoints. */
function require_admin(): int {
    $uid = require_login();
    if (!has_role($uid, Role::Admin)) {
        bad('Admins only', 403);
    }
    return $uid;
}

$me = require_login();
