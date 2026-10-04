<?php
// Ends the session. POST only so a stray link or prefetch cannot sign someone out.
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}

logout_user();
go(base_url() . '/login/');
