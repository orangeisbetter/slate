<?php
// Turns an uncaught error into a log line and a page somebody can quote in a bug report, instead
// of a blank 500 or a stack trace full of absolute paths. bootstrap.php loads this first, before
// the database is opened or anything else can fail, and it deliberately depends on nothing, so it
// still works when the rest of src/ is what is broken.
//
// The detail goes to the SAPI error log and to data/error.log. The first is where a developer
// expects it; the second is where an operator will actually find it, because the SAPI log is the
// terminal's stderr under the built-in server and a system file rotated by someone else's policy
// under Apache. Set SLATE_DEBUG=1 to render the detail as well: that is for a developer machine and
// nowhere else, because it prints file paths and query text.
//
// Warnings and notices become exceptions, so a mistake that would otherwise pass silently reaches
// this file instead of quietly writing something wrong.
//
// The whole response is buffered, and that is what makes the rest of this file possible. The status
// line goes out with the first flush of the body, so without a buffer a 200 is on the wire before a
// page has finished rendering and can never be taken back for a 500. That made the size of the page
// decide whether a crash was visible: identical code returned a clean 500 for a failure in the first
// few KB and a silently truncated 200 for the same failure further down. Buffering costs one page of
// memory per request, which at these page sizes is not a trade worth making.

error_reporting(E_ALL);
ini_set('display_errors', '0');        // rendered below instead, and never with paths in it
ini_set('log_errors', '1');
ob_start();

/** True when SLATE_DEBUG asks for the unfiltered detail on the page. */
function slate_debug(): bool {
    static $on = null;
    return $on ??= (bool)getenv('SLATE_DEBUG');
}

/**
 * Append to data/error.log, keeping one old generation.
 *
 * Written with LOCK_EX because two requests failing at once would otherwise interleave their
 * entries mid-line, and rotated by size rather than by date because the thing being protected here
 * is a disk on somebody's machine. Every failure here is silent by design, so this file is the only
 * durable record; it holds request paths and messages, never addresses or passwords.
 */
function slate_log_file(string $message): void {
    $file = dirname(__DIR__) . '/data/error.log';
    if (@file_put_contents($file, date('c') . "\n" . $message . "\n\n", FILE_APPEND | LOCK_EX) === false) {
        return;                          // an unwritable data/ is not worth failing over
    }
    clearstatcache(true, $file);
    if (@filesize($file) > 1048576) {
        @rename($file, $file . '.1');
    }
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
    $message = sprintf("slate error [%s] %s: %s\n  in %s:%d\n  %s\n  %s %s",
        $ref, $e::class, $e->getMessage(), $e->getFile(), $e->getLine(),
        str_replace("\n", "\n  ", $e->getTraceAsString()), $where, $uri);

    error_log($message);
    slate_log_file($message);

    // Throw away whatever the page had managed to emit. A half-built document is worse than a short
    // one: it closes its own tags, so it reads as a layout bug rather than the crash it is. Possible
    // only because the response is still buffered, which is also why the 500 below can still be set.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Only if something bypassed the buffer and flushed on its own, which nothing here does. The log
    // and the reference code are all that is left to offer, and the reference has to go in a header,
    // since no body can be sent.
    if (headers_sent()) {
        // Suppressed, because this branch only runs when headers are already gone and PHP would warn
        // about it — and a warning here would be escalated to an exception by the handler above, inside
        // an exception handler. A secondary fatal would bury the real one.
        @header('X-Slate-Error: ' . $ref);
        return;
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    // htmx does not swap a response that is not 2xx, so this body is never read by a fragment
    // request and cannot be how the reference reaches the browser. A header can: app.js shows it in a
    // toast, which is the only way a failure inside a fragment request becomes visible at all.
    header('X-Slate-Error: ' . $ref);

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

    // A complete document, closed, even though nothing else about it is: the suite holds every page
    // Slate emits to the same standard, and an error page that ends mid-tag would train everyone
    // to ignore that check exactly when it matters most.
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1">',
         '<title>Something went wrong</title>',
         '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem}',
         '.ref{margin-top:1.5rem;font-size:.9rem;color:#666}',
         'pre{white-space:pre-wrap;background:#f6f6f6;padding:1rem;border-radius:6px;font-size:.8rem}</style>',
         '</head><body>',
         '<h1>Something went wrong</h1><p>', slate_escape($say), '</p>', $ref_line, $detail,
         '</body></html>';
});

/**
 * Escapes for HTML without leaning on h() from lib.php: this file has to keep working when
 * lib.php is what failed to load, and that is exactly when it runs.
 */
function slate_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES);
}
