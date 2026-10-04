<?php
// Turns an uncaught error into a log line and a page somebody can quote in a bug report, instead
// of a blank 500 or a stack trace full of absolute paths. bootstrap.php loads this first, before
// the database is opened or anything else can fail, and it deliberately depends on nothing, so it
// still works when the rest of src/ is what is broken.
//
// The detail always goes to the SAPI error log, which is the Apache or php-fpm error log and needs
// no configuration here. Set SLATE_DEBUG=1 to render the detail as well: that is for a developer
// machine and nowhere else, because it prints file paths and query text.
//
// Warnings and notices become exceptions, so a mistake that would otherwise pass silently reaches
// this file instead of quietly writing something wrong.

error_reporting(E_ALL);
ini_set('display_errors', '0');        // rendered below instead, and never with paths in it
ini_set('log_errors', '1');

/** True when SLATE_DEBUG asks for the unfiltered detail on the page. */
function slate_debug(): bool {
    static $on = null;
    return $on ??= (bool)getenv('SLATE_DEBUG');
}

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    // @ sets a reduced error_reporting, and so does a caller who has narrowed it. Both mean
    // "not interested in this one", and returning false lets PHP's own handler have it.
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    $where = $_SERVER['REQUEST_METHOD'] ?? '-';
    $uri = $_SERVER['REQUEST_URI'] ?? '-';
    error_log(sprintf("slate error [%s] %s: %s\n  in %s:%d\n  %s\n  %s %s",
        $ref, $e::class, $e->getMessage(), $e->getFile(), $e->getLine(),
        str_replace("\n", "\n  ", $e->getTraceAsString()), $where, $uri));

    // Something already sent, so a full page would only produce a half one. The log has it.
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    // A locked database is the one failure a person can usefully retry, and with several people
    // saving at once it is the most likely one, so it gets a sentence instead of a reference.
    $busy = stripos($e->getMessage(), 'database is locked') !== false;
    $say = $busy
        ? 'The database was busy. Nothing was saved, so it is safe to try again.'
        : 'Something went wrong. Nothing was saved.';
    $ref_line = $busy ? '' : sprintf('<p class="ref">Quote this reference: <code>%s</code></p>', h($ref));
    $detail = slate_debug()
        ? sprintf('<pre>%s</pre>', slate_escape($e->getMessage() . "\n\n" . $e->getTraceAsString()))
        : '';

    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>',
         '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem}',
         '.ref{margin-top:1.5rem;font-size:.9rem;color:#666}',
         'pre{white-space:pre-wrap;background:#f6f6f6;padding:1rem;border-radius:6px;font-size:.8rem}</style>',
         '<h1>Something went wrong</h1><p>', slate_escape($say), '</p>', $ref_line, $detail;
});

/**
 * Escapes for HTML without leaning on h() from lib.php: this file has to keep working when
 * lib.php is what failed to load, and that is exactly when it runs.
 */
function slate_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES);
}
