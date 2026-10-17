<?php
// The signed-in user renaming themselves. Their own account only: there is no user_id to read, so
// this cannot be pointed at somebody else even by a hand-rolled request. Redirects back to the tab this
// form is on, so a save lands where the reader was rather than on whichever tab is first.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_method('PATCH');
$me = require_login();

$name = trim((string)(request_data()['name'] ?? ''));
if ($name === '') {
    go('/account/?error=' . rawurlencode('A name is needed') . '#tab-profile');
}

q('UPDATE users SET name=? WHERE id=?', [$name, $me]);
go('/account/?saved=' . rawurlencode('Name saved') . '#tab-profile');
