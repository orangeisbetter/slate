<?php
// The one file a page or an action names, in the spirit of an auto_prepend_file: after this,
// nothing in the project spells out a path into src/. A real auto_prepend_file would be tidier
// still, but PHP ignores it under the built-in server (php -S) that the tests and a quick local
// run both use, so a request has to ask for this by name.
//
// Declare SLATE_REQUIRE_LOGIN before requiring this and the request stops here unless it is signed
// in, which is what keeps an endpoint from being reached anonymously by forgetting a check.
// Without the constant the same shared code loads without the check, for sign in, first-run
// setup and accepting an invitation: the pages a visitor has to reach before they can sign in.
//
// errors.php goes first because it is the one file that has to work when nothing else does, and
// require_same_origin() last because it is the only cross-site check every entry point needs and
// therefore the only one that has to live here rather than in each endpoint.
require __DIR__ . '/errors.php';

if (defined('SLATE_REQUIRE_LOGIN') && SLATE_REQUIRE_LOGIN) {
    require __DIR__ . '/app.php';   // resolves $me, or redirects to sign in
} else {
    require __DIR__ . '/views.php';
}

require_same_origin();
