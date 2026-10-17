<?php
// Taking the Slack connection back off your own account. The one guard is the account's other way
// in: a Slack-only account that unlinked itself would be left with no sign-in method at all, so
// setting a password comes first. There is no second confirmation -- the row is one UPDATE to put
// back, and the button on /account/ asks the browser's confirm() like every other destructive form
// in this app.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_method('DELETE');
$me = require_login();

$account = user($me);
$refuse = fn(string $why) => go('/account/?error=' . rawurlencode($why) . '#tab-signin');

if (empty($account->slack_id)) {
    $refuse('No Slack account is linked');
}
if (empty($account->password_hash)) {
    $refuse('Set a password first, or this account would have no way to sign in');
}

unlink_slack($me);
go('/account/?saved=' . rawurlencode('Slack account unlinked') . '#tab-signin');
