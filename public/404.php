<?php
// What the web server serves when a path has no file behind it. The app has no front controller --
// /project/?id=4 is a file, a path is a fact about the filesystem -- so an unknown URL never reaches
// PHP on its own; the server has to be pointed here:
//
//   Apache:  ErrorDocument 404 /404.php
//   nginx:   error_page 404 /404.php;
//
// Both do an internal subrequest, not a redirect: the visitor keeps the address they typed and the
// answer carries a 404, with this page's body. It renders signed out, and shares the one error page
// in src/errors.php, so it needs neither the database nor a session to answer.
require __DIR__ . '/../src/bootstrap.php';

slate_error_page(404, 'That page does not exist.');
