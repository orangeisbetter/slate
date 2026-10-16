<?php
// The signed-in user choosing a site theme. Their own account only: there is no user_id to read, so
// this cannot be pointed at somebody else even by a hand-rolled POST. Redirects back to the tab this
// form is on, so a save lands where the reader was rather than on whichever tab is first.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
$me = require_login();

// A slug that is not in THEMES is refused rather than stored: user_theme() would silently fold it
// back to the default on the way out, so nothing would look broken and the row would still be wrong.
$theme = (string)($_POST['theme'] ?? '');
if (!theme_exists($theme)) {
    go('/account/?error=' . rawurlencode('That is not a theme') . '#tab-profile');
}

q('UPDATE users SET theme=? WHERE id=?', [$theme, $me]);
go('/account/?saved=' . rawurlencode('Theme saved') . '#tab-profile');
