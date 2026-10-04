#!/usr/bin/php
<?php
// Copies the database to a consistent snapshot:  php bin/backup.php [target]
//
// Run it on a schedule. It uses VACUUM INTO rather than copying the file, because the app runs in
// WAL mode and a plain copy of board.db can silently miss every write still sitting in the
// board.db-wal file beside it. VACUUM INTO asks SQLite itself for a snapshot, taken at a moment
// when the two agree.
//
// The default name carries a timestamp so a second run in the same minute will not refuse:
//   php bin/backup.php                  ->  data/backup-20261003-214500.db
//   php bin/backup.php /mnt/usb/slate.db  ->  that exact path
//
// To restore: stop the app, copy the snapshot over data/board.db, and delete any board.db-wal and
// board.db-shm left beside it, because those belong to the database being replaced. Then start the
// app again. Keep snapshots somewhere other than the machine running the app.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("backup.php runs from the command line only.\n");
}

$root = dirname(__DIR__);
$db_file = "$root/data/board.db";
if (!is_file($db_file)) {
    exit("No database at $db_file. Run: php bin/migrate.php\n");
}

$target = $argv[1] ?? "$root/data/backup-" . gmdate('Ymd-His') . '.db';
if (!str_ends_with($target, '.db')) {
    exit("Refusing $target: a backup has to end in .db, so it cannot be mistaken for anything else.\n");
}
if (file_exists($target)) {
    exit("Refusing to overwrite $target. Pick another name, or move that one aside first.\n");
}
$dir = dirname($target);
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    exit("Cannot create $dir.\n");
}

$db = new PDO("sqlite:$db_file");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// So a backup taken while somebody is saving waits for the write lock instead of failing.
$db->exec('PRAGMA busy_timeout=5000;');

try {
    $db->exec("VACUUM INTO " . $db->quote($target));
} catch (Throwable $e) {
    // A failed VACUUM INTO leaves a partial file behind, which would look like a usable backup.
    @unlink($target);
    exit('Backup failed: ' . $e->getMessage() . "\n");
}

$kb = (int)round(filesize($target) / 1024);
echo "wrote $target ($kb KB)\n";
