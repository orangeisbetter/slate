#!/usr/bin/php
<?php
// Checks that this PHP can run Slate, before you go looking for the problem in the app:
//   php bin/check.php
//
// Worth running on a new machine, or after changing PHP version, because the app is deliberately
// thin and a missing piece shows up as a page that stops rendering for no visible reason. That is
// not hypothetical: without mbstring this used to leave any page carrying a picker half-finished,
// with the real message sitting in the error log and nothing at all on the screen.
//
// Exits 0 when the app can run, 1 when it cannot. Optional extensions are reported, not enforced.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("check.php runs from the command line only.\n");
}

$root = dirname(__DIR__);
$fails = 0;

/** One line of the report. $bad true is what makes the exit code non-zero. */
function report(string $level, string $state, string $label, string $note, bool $bad = false): void {
    global $fails;
    $fails += $bad ? 1 : 0;
    printf("  %-9s %-8s %-24s %s\n", $state, $level, $label, $note);
}

echo "PHP\n";
report('required', PHP_VERSION_ID >= 80100 ? 'ok' : 'too old', 'version',
       PHP_VERSION . (PHP_VERSION_ID >= 80100 ? '' : '  — needs 8.1 or newer'),
       PHP_VERSION_ID < 80100);
report('optional', ini_get('display_errors') ? 'on' : 'off', 'display_errors',
       ini_get('display_errors')
           ? 'src/errors.php turns this off itself; harmless'
           : 'as the app wants it');
// Reported without writing anything, since a test line in someone's error log helps nobody.
report('optional', ini_get('log_errors') ? 'on' : 'off', 'error logging',
       ini_get('log_errors')
           ? 'src/errors.php logs there; that is where a crash explains itself'
           : 'off — a crash will have no message to offer');

echo "\nDependencies\n";
// Parsedown renders markdown in comments and descriptions. Composer installs it into vendor/,
// which is gitignored, so a fresh checkout that skipped `composer install` fails here rather than
// on the first page carrying a comment.
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
$have_parsedown = class_exists('Parsedown');
report('required', $have_parsedown ? 'ok' : 'missing', 'parsedown',
       $have_parsedown ? 'markdown in comments and descriptions' : 'run composer install', !$have_parsedown);

echo "\nRequired extensions\n";
foreach ([
    'pdo_sqlite' => 'the database',
    'json'       => 'board drag and drop, and the JSON API',
    'session'    => 'sign in',
    'hash'       => 'password storage',
    'filter'     => 'address validation',
] as $ext => $why) {
    $have = extension_loaded($ext);
    report('required', $have ? 'ok' : 'missing', $ext, $have ? $why : "needed for $why", !$have);
}

echo "\nOptional extensions\n";
// What the app actually calls, not what is loaded: a php.ini can disable the functions and leave the
// extension listed, and it is the missing function that used to be fatal. Without it, search folds
// ASCII case only and the board name cap counts bytes.
if (function_exists('mb_strtolower') && function_exists('mb_strlen')) {
    report('optional', 'ok', 'mbstring', 'Unicode-aware search and name lengths');
} elseif (extension_loaded('mbstring')) {
    report('optional', 'disabled', 'mbstring', 'loaded, but its functions are off in php.ini');
} else {
    report('optional', 'missing', 'mbstring', 'ASCII-only search folding; name cap counts bytes');
}

echo "\nDatabase\n";
$db_file = $root . '/data/board.db';
if (!file_exists($db_file)) {
    printf("  %-9s %-8s %-24s %s\n", 'absent', '-', 'data/board.db', 'not created yet — run php bin/migrate.php');
} else {
    $rw = is_readable($db_file) && is_writable($db_file);
    report('required', $rw ? 'ok' : 'unwritable', 'data/board.db',
           $rw ? 'readable and writable' : 'the app cannot save anything', !$rw);
    try {
        $db = new PDO('sqlite:' . $db_file);
        $applied = array_column(
            $db->query('SELECT filename FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC),
            'filename');
        $on_disk = array_map('basename', glob($root . '/db/*.sql'));
        $missing = array_diff($on_disk, $applied);
        $unknown = array_diff($applied, $on_disk);
        if ($missing) {
            report('required', 'behind', 'migrations',
                   'not applied: ' . implode(', ', $missing) . ' — run php bin/migrate.php', true);
        } elseif ($unknown) {
            report('optional', 'extra', 'migrations',
                   'recorded but not on disk: ' . implode(', ', $unknown));
        } else {
            report('required', 'current', 'migrations', count($on_disk) . ' applied');
        }
    } catch (Throwable $e) {
        report('required', 'unreadable', 'data/board.db', $e->getMessage(), true);
    }
}

echo "\n", $fails === 0
    ? "Nothing required is missing. php -S localhost:8000 -t public will serve the app.\n"
    : "$fails problem(s) above. The app will not run correctly until they are fixed.\n";

exit($fails === 0 ? 0 : 1);
