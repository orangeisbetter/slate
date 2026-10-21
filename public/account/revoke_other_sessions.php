<?php
// Signing every other browser out of your own account at once -- the "all" of revoke_session.php.
// This session's own row is kept: signing out everywhere including here is /logout.php, which
// already exists, and a button that quietly took the presser with it would be a trap.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_method('DELETE');
$me = require_login();

q('DELETE FROM sessions WHERE user_id=? AND id<>?', [$me, session_id_hash()]);
go('/account/?saved=' . rawurlencode('Everywhere else has been signed out') . '#tab-signin');
