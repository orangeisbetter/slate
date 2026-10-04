#!/usr/bin/php
<?php
// Applies db/*.sql once each, in filename order. Run it after cloning, after pulling,
// and whenever you add a migration file:  php bin/migrate.php
//
// It keeps its own ledger in schema_migrations, so re-running is safe and each file is
// applied exactly once. Each file runs inside a transaction, so a failure leaves the
// database untouched. Statements are executed one at a time purely so that a failure
// can name the offending statement by number, which matters in a multi-statement file.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php runs from the command line only.\n");
}

$root = dirname(__DIR__);
@mkdir("$root/data", 0775, true);

$db = new PDO("sqlite:$root/data/board.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');

// The ledger is created here rather than in a migration, since it has to exist before
// any migration can be recorded.
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations(
  filename TEXT PRIMARY KEY,
  checksum TEXT NOT NULL,
  applied_at TEXT NOT NULL)');

// Splits a migration into statements. Deliberately simple, so migration files must use
// full-line -- comments and must not put a semicolon inside a string literal. Nothing
// in db/ needs more than that, and anything richer fails loudly rather than silently.
function sql_statements(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', (string)$sql))));
}

$files = glob("$root/db/*.sql") ?: [];

// strnatcmp so 4-thing.sql sorts before 13-other.sql; a plain sort() would not.
usort($files, 'strnatcmp');

$applied = [];
foreach ($db->query('SELECT filename, checksum FROM schema_migrations') as $row) {
    $applied[$row['filename']] = $row['checksum'];
}

if (!$files) {
    exit("No migration files in db/.\n");
}

echo "Database: $root/data/board.db\n\n";
$changed = 0;

foreach ($files as $file) {
    $name = basename($file);
    $checksum = (string)hash_file('sha256', $file);

    if (isset($applied[$name])) {
        if ($applied[$name] !== $checksum) {
            printf("  ~  %-24s already applied, but the file has changed since\n", $name);
        } else {
            printf("  -  %-24s already applied\n", $name);
        }
        continue;
    }

    $statements = sql_statements((string)file_get_contents($file));
    $i = 0;
    $db->beginTransaction();
    try {
        foreach ($statements as $i => $statement) {
            try {
                $db->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException(sprintf(
                    'statement %d of %d failed: %s', $i + 1, count($statements), $e->getMessage()));
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        echo "\nFAILED in $name, nothing was applied.\n  " . $e->getMessage() . "\n"
            . '  ' . preg_replace('/\s+/', ' ', substr($statements[$i] ?? '', 0, 90)) . "\n";
        exit(1);
    }

$ledger = $db->prepare('INSERT INTO schema_migrations(filename,checksum,applied_at) VALUES(?,?,?)');
    $ledger->execute([$name, $checksum, gmdate('c')]);
    printf("  +  %-24s applied (%d statements)\n", $name, count($statements));
    $changed++;
}

echo $changed ? "\n$changed migration(s) applied.\n" : "\nNothing to do.\n";
