<?php
// Signing one browser out of your own account. The row has to be yours: an account can revoke
// its own sessions and nothing else, so an id from another account is a 404 rather than a
// refusal that confirms the id names a session somewhere. There is no second confirmation --
// the button on /account/ asks the browser's confirm() like every other destructive form here.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_method('DELETE');
$me = require_login();

$id = (string)(request_data()['id'] ?? '');
$row = q('SELECT user_id FROM sessions WHERE id=?', [$id])->fetch();
if (!$row || (int)$row['user_id'] !== $me) {
    bad('No such session', 404);
}

if ($id === session_id_hash()) {
    // This device asked for its own row: that is signing out, not revoking from afar, so the
    // session goes the way /logout.php would take it rather than being left half-alive.
    logout_user();
    go('/login/');
}

q('DELETE FROM sessions WHERE id=?', [$id]);
go('/account/?saved=' . rawurlencode('That browser has been signed out') . '#tab-signin');
