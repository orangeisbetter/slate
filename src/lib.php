<?php
// Shared database and helpers. Lives outside the web root (public/) so it can't be
// requested directly. The schema lives in db/*.sql and is applied by bin/migrate.php,
// which this file deliberately does not do for itself.
const LABELS = ['draft' => 'Draft', 'backlog' => 'Backlog', 'todo' => 'To do', 'in_progress' => 'In progress',
                'blocked' => 'Blocked', 'review' => 'In review', 'done' => 'Done', 'cancelled' => 'Cancelled', 'review_ready' => 'Ready for review'];
const BOARD_STATUSES = ['todo', 'in_progress', 'review_ready', 'review', 'done'];   // board columns; other statuses only appear in the list view
const TYPES = ['task' => 'Task', 'bug' => 'Bug', 'feature' => 'Feature', 'epic' => 'Epic', 'research' => 'Research'];

const MIN_PASSWORD = 10;              // characters
const SESSION_LIFETIME = 14 * 86400;  // two weeks
const LOGIN_LIMIT = 8;                // failed attempts allowed per window
const LOGIN_WINDOW = 900;             // ...within this many seconds

@mkdir(__DIR__ . '/../data', 0775, true);
$db = new PDO('sqlite:' . __DIR__ . '/../data/board.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// WAL lets readers and the writer work at once, but it still allows only one writer, so a second
// save arriving mid-write gets SQLITE_BUSY. Without a busy timeout that surfaces instantly as an
// exception; waiting up to five seconds for the lock turns it into a slightly slower save instead.
// synchronous=NORMAL is the companion setting: in WAL mode the log is synced at checkpoints rather
// than on every commit, which skips an fsync per write. That costs the last few transactions if the
// machine loses power, and cannot corrupt the file, which WAL guarantees either way.
$db->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA synchronous=NORMAL;');

/** True when every table and column the app relies on is present. */
function db_is_current(): bool {
    global $db;
    $tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
    $columns = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    $needed_tables = ['users', 'projects', 'comments', 'status_log', 'roles', 'user_roles', 'invites',
                      'boards', 'project_boards'];
    return count(array_intersect($needed_tables, $tables)) === count($needed_tables)
        && count(array_intersect(['email', 'password_hash'], $columns)) === 2;
}

function q(string $sql, array $args = []): PDOStatement {
    global $db;
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return $statement;
}
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

/**
 * Lowercase, for the text the search boxes match against. mbstring when it is loaded, ASCII
 * otherwise.
 *
 * Both of these exist because this app used to call mb_strtolower() and mb_strlen() directly, and a
 * PHP without mbstring made that a fatal error on any page carrying a picker — the project page
 * stopped dead halfway down, with no message, because src/errors.php cannot send an error page once
 * output has started. A missing optional extension should cost a little Unicode folding, not the
 * page. `php bin/check.php` reports whether it is there.
 *
 * What is lost without mbstring: ASCII-only case folding, so a search for "GRÜN" no longer matches
 * "grün". Latin-1 case pairs are the ones that actually come up in practice.
 */
function lower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}

/**
 * Characters, not bytes, for the length caps on names. Without mbstring this falls back to strlen,
 * which counts bytes and so caps accented text earlier than plain ASCII — a stricter limit rather
 * than a wrong one, and the only place in the app where it shows.
 */
function chars(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

function dt(string $s): string {
    return gmdate('M j, Y H:i', strtotime($s)) . ' UTC';
}
function ini(string $n): string {
    return implode('', array_map(fn($w) => $w[0] ?? '', explode(' ', $n)));
}
function go(string $url): never {
    header("Location: $url");
    exit;
}
function bad(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $msg;
    exit;
}
function validDate($d): ?string {
    $d = (string)$d;
    if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        bad('Dates must be YYYY-MM-DD');
    }
    return $d ?: null;
}
function project(int $id): ?array {
    $p = q('SELECT * FROM projects WHERE id=?', [$id])->fetch();
    if (!$p) {
        return null;
    }
    $p['assignees'] = array_map('intval', q('SELECT user_id FROM project_assignees WHERE project_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    return $p;
}
function setAssignees(int $id, array $ids): void {
    q('DELETE FROM project_assignees WHERE project_id=?', [$id]);
    foreach (array_unique(array_map('intval', $ids)) as $u) {
        q('INSERT OR IGNORE INTO project_assignees VALUES(?,?)', [$id, $u]);
    }
}
function logStatus(int $id, ?string $from, string $to): void {
    q('INSERT INTO status_log(project_id,from_status,to_status,changed_at) VALUES(?,?,?,?)', [$id, $from, $to, gmdate('c')]);
}

/**
 * Creates a project, or a subproject when parent_id is posted. Reading the POST stays with the
 * endpoint; this is the part new/add.php and project/add.php share, because adding a project and
 * adding one underneath another differ only in that one field.
 */
function create_project_from_post(int $creator): never {
    global $db;
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        bad('Title is required');
    }
    $st = $_POST['status'] ?? 'todo';
    if (!isset(LABELS[$st])) {
        bad('Invalid status');
    }
    $type = $_POST['type'] ?? 'task';
    if (!isset(TYPES[$type])) {
        bad('Invalid type');
    }
    $start = validDate($_POST['start_date'] ?? '');
    $due = validDate($_POST['due_date'] ?? '');
    $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
    if ($parent && !project($parent)) {
        bad('Parent project not found', 404);
    }
    $now = gmdate('c');
    q('INSERT INTO projects(title,description,status,type,creator_id,parent_id,start_date,due_date,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
      [$title, $_POST['description'] ?? '', $st, $type, $creator, $parent, $start, $due, $now, $now]);
    $id = (int)$db->lastInsertId();
    setAssignees($id, (array)($_POST['as'] ?? []));
    set_project_boards($id, (array)($_POST['boards'] ?? []), $creator);
    logStatus($id, null, $st);
    if ($parent) {                             // adding underneath a project counts as touching it
        q('UPDATE projects SET updated_at=? WHERE id=?', [$now, $parent]);
    }
    go(base_url() . '/project/?id=' . ($parent ?: $id));   // a subproject returns to its parent
}

/**
 * Updates an existing project and nothing else: adding a project is create_project_from_post().
 * A posted status is honoured even though the edit form has no field for one, so an update posted
 * by something other than that form can still move a project and record it in the history.
 */
function update_project_from_post(): never {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        bad('Title is required');
    }
    $id = (int)($_POST['id'] ?? 0);
    $existing = project($id);
    if (!$existing) {
        bad('Project not found', 404);
    }
    $st = $_POST['status'] ?? $existing['status'];
    if (!isset(LABELS[$st])) {
        bad('Invalid status');
    }
    $type = $_POST['type'] ?? $existing['type'];
    if (!isset(TYPES[$type])) {
        bad('Invalid type');
    }
    $start = validDate($_POST['start_date'] ?? '');
    $due = validDate($_POST['due_date'] ?? '');
    $now = gmdate('c');
    q('UPDATE projects SET title=?,description=?,status=?,type=?,start_date=?,due_date=?,updated_at=? WHERE id=?',
      [$title, $_POST['description'] ?? '', $st, $type, $start, $due, $now, $id]);
    if ($st !== $existing['status']) {
        logStatus($id, $existing['status'], $st);
    }
    go(base_url() . "/project/?id=$id");
}

/**
 * URL prefix the app is served under, so every internal URL is built from one known place:
 * '' at the web root, '/projects' when it sits in a subdirectory. It is the mount point of the
 * document root, not the directory of the running script, which is what a page in its own
 * directory (/board/, /login/) or an action (/project/update.php) would otherwise report.
 */
function base_url(): string {
    // The app's own public directory, on disk: lib.php lives in src/, one level up from it.
    $public = realpath(dirname(__DIR__) . '/public') ?: '';
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $name = $_SERVER['SCRIPT_NAME'] ?? '';
    // Take the script's path inside public/ off the front of its URL: '/projects/board/index.php'
    // minus 'board/index.php' leaves '/projects', the directory public/ itself is served from, and
    // '' when that is the web root. Anchoring on the document root instead would be wrong, because
    // the URL of a subdirectory install leads with a directory that is not in public/ at all.
    // Anything unrecognised (the command line, an unusual SAPI) falls back to the web root.
    if ($public !== '' && $script !== '' && str_starts_with($script, $public . '/')) {
        $rel = substr(str_replace('\\', '/', $script), strlen($public) + 1);
        if (str_ends_with($name, '/' . $rel)) {
            return rtrim(substr($name, 0, strlen($name) - strlen($rel)), '/');
        }
    }
    return '';
}

/** Keeps redirects on this site: only root-relative paths, never protocol-relative ones. */
function safe_next(string $next): string {
    return preg_match('#^/(?!/)#', $next) ? $next : base_url() . '/index.php';
}

// ---- users, roles, sessions ----

function user_exists(int $id): bool {
    global $db;
    return (bool)$db->query('SELECT 1 FROM users WHERE id=' . (int)$id)->fetchColumn();
}

function user_by_email(string $email): ?array {
    $stmt = q('SELECT * FROM users WHERE email=?', [strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}

/** Accounts that can actually sign in. Users seeded before auth existed cannot. */
function credentials_count(): int {
    global $db;
    return (int)$db->query('SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL')->fetchColumn();
}

function hash_password(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

function check_password(string $password, ?string $hash): bool {
    return is_string($hash) && $hash !== '' && password_verify($password, $hash);
}

function create_user(string $name, string $email, string $password, array $role_slugs = ['member']): int {
    global $db;
    $stmt = $db->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');
    $stmt->execute([$name, strtolower(trim($email)), hash_password($password)]);
    $id = (int)$db->lastInsertId();
    set_roles($id, $role_slugs);
    return $id;
}

function all_roles(): array {
    return q('SELECT id,label FROM roles ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function set_roles(int $user_id, array $slugs): void {
    q('DELETE FROM user_roles WHERE user_id=?', [$user_id]);
    $stmt = q('INSERT OR IGNORE INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE slug=?');
    foreach ($slugs as $slug) {
        $stmt->execute([$user_id, $slug]);
    }
}

function roles(int $user_id): array {
    return q('SELECT r.slug FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=? ORDER BY r.id',
             [$user_id])->fetchAll(PDO::FETCH_COLUMN);
}

function is_admin(?int $user_id): bool {
    return $user_id ? in_array('admin', roles((int)$user_id), true) : false;
}

/** Every user with their roles, for the users page. can_login is false for name-only accounts. */
function users_list(): array {
    return q('SELECT u.id, u.name, u.email, u.password_hash IS NOT NULL AS can_login, GROUP_CONCAT(r.slug) AS roles
              FROM users u
              LEFT JOIN user_roles ur ON ur.user_id = u.id
              LEFT JOIN roles r ON r.id = ur.role_id
              GROUP BY u.id
              ORDER BY u.name')->fetchAll();
}

// ---- invites ----

/** Returns the token so the caller can show the link. Valid for two weeks, single use. */
function new_invite(string $email, ?string $name, ?int $role_id, int $created_by): string {
    global $db;
    $token = bin2hex(random_bytes(24));
    $stmt = $db->prepare('INSERT INTO invites(token,email,name,role_id,created_by,created_at,expires_at)
                          VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$token, strtolower(trim($email)), $name, $role_id, $created_by,
                    gmdate('c'), gmdate('c', time() + SESSION_LIFETIME)]);
    return $token;
}

/** The invite behind a token, or null if it is unknown, already used or expired. */
function invite_by_token(string $token): ?array {
    $invite = q('SELECT * FROM invites WHERE token=?', [$token])->fetch();
    if (!$invite || $invite['accepted_at']) {
        return null;
    }
    return strtotime($invite['expires_at']) < time() ? null : $invite;
}

function invites_list(): array {
    return q('SELECT i.*, u.name AS created_by_name FROM invites i
              LEFT JOIN users u ON u.id = i.created_by
              WHERE i.accepted_at IS NULL
              ORDER BY i.created_at DESC')->fetchAll();
}

function revoke_invite(string $token): void {
    q('DELETE FROM invites WHERE token=? AND accepted_at IS NULL', [$token]);
}

// ---- boards ----

/** Every board, oldest first, with how many projects sit on it. */
function boards(): array {
    return q('SELECT b.id, b.name, b.description, b.created_at,
                     (SELECT COUNT(*) FROM project_boards pb WHERE pb.board_id=b.id) AS project_count
              FROM boards b ORDER BY b.id')->fetchAll();
}

function board(int $id): ?array {
    return q('SELECT * FROM boards WHERE id=?', [$id])->fetch() ?: null;
}

/**
 * Projects whose title matches $q, subprojects included, most recently updated first.
 *
 * $limit of 0 means every match, which is what the results page wants; the header box asks for a
 * handful instead. It has to be a missing clause rather than LIMIT 0, which asks for no rows at all
 * and would leave the page empty while the menu beside it worked. An empty query returns nothing
 * either way, so a cleared box goes quiet instead of listing the whole install.
 *
 * A bare number, or one with a leading #, also finds that project by id, which is what makes the
 * number beside a title worth reading: it can be typed straight back in. That is a second clause
 * rather than a separate lookup, so a number still matches a title that happens to contain it.
 *
 * SQLite's LIKE folds ASCII case but not the rest, so a title with an accented letter matches only
 * on its exact case.
 *
 * % and _ are escaped, so searching for them looks for them. Left alone they are wildcards, and a
 * query of "%" would come back with every project in it.
 */
function search_projects(string $q, int $limit = 0): array {
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $by_id = preg_match('/^#?(\d+)$/', $q) ? (int)ltrim($q, '#') : null;
    $cap = $limit > 0 ? ' LIMIT ?' : '';
    $args = $by_id === null ? [$like] : [$like, $by_id];
    if ($cap !== '') {
        $args[] = $limit;
    }
    return q("SELECT * FROM projects
              WHERE title LIKE ? ESCAPE '\\'" . ($by_id === null ? '' : ' OR id = ?') . "
              ORDER BY updated_at DESC, id DESC" . $cap, $args)->fetchAll();
}

/** Board ids for one project. Membership is independent per project, subprojects included. */
function project_board_ids(int $project_id): array {
    return array_map('intval', q('SELECT board_id FROM project_boards WHERE project_id=? ORDER BY board_id',
                                 [$project_id])->fetchAll(PDO::FETCH_COLUMN));
}

/** Board ids for many projects at once: project id => board ids. Avoids a query per row. */
function boards_by_project(array $project_ids): array {
    if (!$project_ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($project_ids), '?'));
    $rows = q("SELECT project_id, board_id FROM project_boards WHERE project_id IN ($in) ORDER BY board_id",
              $project_ids)->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[$row['project_id']][] = (int)$row['board_id'];
    }
    return $map;
}

/** Replaces a project's board membership. An empty list is allowed: a project may sit on no board. */
function set_project_boards(int $project_id, array $board_ids, ?int $added_by = null): void {
    q('DELETE FROM project_boards WHERE project_id=?', [$project_id]);
    $insert = q('INSERT OR IGNORE INTO project_boards(project_id,board_id,added_by,added_at) VALUES(?,?,?,?)');
    foreach (array_unique(array_map('intval', $board_ids)) as $board_id) {
        if (board($board_id)) {
            $insert->execute([$project_id, $board_id, $added_by, gmdate('c')]);
        }
    }
}

// ---- cross-site requests ----

/**
 * Refuses a state-changing request that some other site caused the browser to send.
 *
 * Every page and every endpoint loads bootstrap.php, so this is called from one place and there is
 * nothing to add to an endpoint, a form or an htmx attribute. It reads the headers the browser
 * cannot be tricked into forging, rather than a token in the page:
 *
 *   Sec-Fetch-Site  present in every major browser since March 2023 and the strongest signal
 *                   available, because only the browser sets it. Anything but same-origin means
 *                   the request did not come from this site, and same-site counts: a subdomain
 *                   taken over elsewhere on the same domain is still not this application.
 *   Origin          the fallback for a browser without Fetch Metadata. Sent on every non-GET
 *                   request, so comparing it to our own origin is enough on its own.
 *   Referer         used when Origin is missing, which happens for the first navigation of some
 *                   privacy settings.
 *
 * A request with none of the three is not a browser form post at all, but curl, a health check or
 * a script, so it is logged and allowed. Set SLATE_CSRF_STRICT=1 to refuse those too.
 *
 * This sits alongside the SameSite=Lax session cookie rather than replacing it. Neither can prove
 * intent on its own, and this deliberately treats a sibling subdomain as hostile.
 */
function require_same_origin(): void {
    if (in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '') {
        if ($site === 'same-origin') {
            return;
        }
        bad('Cross-site request refused', 403);
    }

    $source = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '') {
        error_log('csrf: no Origin, Referer or Sec-Fetch-Site on '
            . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        if (getenv('SLATE_CSRF_STRICT')) {
            bad('Cross-site request refused', 403);
        }
        return;
    }
    // Compared through a trailing slash so example.org.attacker.com cannot pass as example.org.
    if (!str_starts_with(rtrim($source, '/') . '/', request_origin() . '/')) {
        bad('Cross-site request refused', 403);
    }
}

/** Where this request says it is going: the origin the browser had in its address bar. */
function request_origin(): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https://' : 'http://') . (string)($_SERVER['HTTP_HOST'] ?? '');
}

// ---- failed sign-ins ----

function login_throttled(string $email): bool {
    global $db;
    q('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('c', time() - LOGIN_WINDOW)]);
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=?');
    $stmt->execute([strtolower(trim($email))]);
    return (int)$stmt->fetchColumn() >= LOGIN_LIMIT;
}

function record_login_failure(string $email): void {
    q('INSERT INTO login_attempts(email,attempted_at) VALUES(?,?)', [strtolower(trim($email)), gmdate('c')]);
}

function clear_login_failures(string $email): void {
    q('DELETE FROM login_attempts WHERE email=?', [strtolower(trim($email))]);
}

// ---- sessions ----

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('slate');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        // The mount point, not always the web root: a subdirectory install should not hand its
        // cookie to everything else on the host. base_url() is '' at the root, which gives '/'.
        'path' => base_url() . '/',
        'httponly' => true,
        // Lax rather than Strict: invite links are followed from email, and Strict would
        // drop the cookie on that first navigation. Lax still withholds it on cross-site
        // POSTs, which is what the form endpoints need.
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function login_user(int $user_id): void {
    start_session();
    session_regenerate_id(true);      // new session id on privilege change
    $_SESSION['uid'] = $user_id;
}

function logout_user(): void {
    start_session();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    session_destroy();
}

// Checked before any query below: on an unmigrated database those would fail with a
// bare "no such table" instead of saying what to do about it.
if (!db_is_current()) {
    bad('This database has not been migrated yet. Run: php bin/migrate.php', 500);
}

$USERS = q('SELECT id, name FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
