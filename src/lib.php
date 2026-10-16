<?php
// Shared database and helpers. Lives outside the web root (public/) so it can't be
// requested directly. The schema lives in db/*.sql and is applied by bin/migrate.php,
// which this file deliberately does not do for itself.
// Every tone a status badge can wear, as Bootstrap's contextual classes. An admin picks one per
// status at /admin/statuses/; this is the list of what can be picked, so a class nothing knows how
// to draw cannot reach the database.
const STATUS_TONES = ['secondary', 'primary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
// How a board treats each of its panels. 'required' draws the column even when it is empty,
// 'optional' draws it only once something is in it, 'hidden' never draws it.
const PANEL_MODES = ['required' => 'Always', 'optional' => 'Only when it has projects', 'hidden' => 'Never'];
// What a board that has never been configured gets: the three columns most work actually moves
// through. Every other status is off until an admin asks for it, which is a display choice and not
// a change to any project — see board_panel_modes(). Which of these still exist is asked of the
// database, so a deleted status is not a column nothing can draw.
const DEFAULT_PANEL_SLUGS = ['todo', 'in_progress', 'done'];
const TYPES = ['task' => 'Task', 'bug' => 'Bug', 'feature' => 'Feature', 'epic' => 'Epic', 'research' => 'Research'];
// The site themes a signed-in user can pick, keyed by the slug stored in users.theme. 'mode' is the
// Bootstrap colour mode the <html> element is given: null keeps the light palette, 'dark' turns on
// Bootstrap's own dark theme — a block of variable overrides plus the handful of component rules
// (the select arrow, the close button) that go with it, which a stylesheet of our own could only
// approximate. 'css' says the theme also has a stylesheet under public/assets/themes/, named after
// the slug, layered over the palette for themes that want more than a colour. This is the whole
// list: a slug that is not a key here can never reach a row, so deleting a theme cannot leave a
// page naming one the app does not know how to draw.
const THEMES = [
    'default'   => ['label' => 'Default',   'mode' => null,   'css' => false],
    'dark'      => ['label' => 'Dark',      'mode' => 'dark', 'css' => false],
    'halloween' => ['label' => 'Halloween', 'mode' => 'dark', 'css' => true],
];

const MIN_PASSWORD = 10;              // characters
const SESSION_LIFETIME = 14 * 86400;  // two weeks
const LOGIN_LIMIT = 8;                // failed attempts allowed per window
const LOGIN_WINDOW = 900;             // ...within this many seconds

require_once __DIR__ . '/models.php';

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
                      'boards', 'project_boards', 'project_statuses'];
    return count(array_intersect($needed_tables, $tables)) === count($needed_tables)
        && count(array_intersect(['email', 'password_hash', 'active', 'theme'], $columns)) === 4;
}

function q(string $sql, array $args = []): PDOStatement {
    global $db;
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return $statement;
}
function h(string $s): string {
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

// ---- project statuses ----

/**
 * Every status, in the order work moves through them: a board draws one column per status it has
 * a panel for, so this is the list of columns a board can possibly have. Loaded once per request
 * from project_statuses — the LABELS, PANEL_ORDER and LABELS_FINISHED constants this used to be
 * are the seed of that table, not a second source of truth.
 */
function &status_cache(): ?array {
    static $rows = null;
    return $rows;
}
function statuses(): array {
    $rows = &status_cache();
    if ($rows === null) {
        $rows = q('SELECT * FROM project_statuses ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
}
/** Called by the endpoints that write statuses, so a request that changes one sees the change. */
function status_cache_clear(): void {
    $rows = &status_cache();
    $rows = null;
}
/** One status by its slug, or null when there is no such row. */
function status_row(string $slug): ?array {
    foreach (statuses() as $row) {
        if ($row['slug'] === $slug) {
            return $row;
        }
    }
    return null;
}
/**
 * Whether a status with this slug exists at all. A project's status always has a row behind it:
 * deleting a status is refused while any project is on it, so this is the whole of the check the
 * endpoints that set a status need to make.
 */
function status_exists(string $slug): bool {
    return status_row($slug) !== null;
}
/** Every slug, in the order the columns are drawn — deliberately not label order. */
function panel_order(): array {
    return array_column(statuses(), 'slug');
}
/** What a status is called. Falls back to the slug, which only a status_log entry can outlive. */
function status_label(string $slug): string {
    return status_row($slug)['label'] ?? $slug;
}
/** The tone its badge wears, falling back to the plain one for the same reason. */
function status_tone(string $slug): string {
    return status_row($slug)['tone'] ?? 'secondary';
}
/** The statuses that count as finished: excluded from open work and the list's default filter. */
function finished_slugs(): array {
    return array_column(array_filter(statuses(), fn($row) => $row['is_finished']), 'slug');
}
function status_is_finished(string $slug): bool {
    return (bool)(status_row($slug)['is_finished'] ?? false);
}
/**
 * What an unconfigured board gets, minus anything since deleted: a default naming a status that
 * is gone is a default with a hole in it, not a column nothing can draw.
 */
function default_board_panels(): array {
    return array_values(array_intersect(DEFAULT_PANEL_SLUGS, panel_order()));
}
/** The slug a new status is stored as: lowercase, runs of anything else folded to one underscore. */
function status_slug_from_label(string $label): string {
    $slug = preg_replace('/[^a-z0-9]+/i', '_', trim($label));
    return strtolower(trim((string)$slug, '_'));
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
// Refuses a request with a status code and a sentence. An htmx fragment gets the sentence as plain
// text, which is what the toast path has always read; anything else gets the shared error page. The
// branch is the HX-Request header rather than anything the browser sends, so the two are told apart
// by the same signal htmx itself uses (see the 401 in app.php).
function bad(string $msg, int $code = 400): never {
    if (isset($_SERVER['HTTP_HX_REQUEST'])) {
        http_response_code($code);
        header('Content-Type: text/plain');
        echo $msg;
        exit;
    }
    slate_error_page($code, $msg);
}
function validDate(mixed $d): ?string {
    $d = (string)$d;
    if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        bad('Dates must be YYYY-MM-DD');
    }
    return $d ?: null;
}
function project(int $id): ?Project {
    $stmt = q('SELECT * FROM projects WHERE id=?', [$id]);
    $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Project::class);
    $p = $stmt->fetch();
    if (!$p) {
        return null;
    }
    $p->assignees = array_map('intval', q('SELECT user_id FROM project_assignees WHERE project_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    return $p;
}

/** Whether a project has been archived. An archived project is retired and read-only. */
function project_is_archived(int $id): bool {
    return (bool)q('SELECT archived FROM projects WHERE id=?', [$id])->fetchColumn();
}

/**
 * Loads a project and refuses to go on if it has been archived.
 *
 * Archiving only hides a project -- the row and everything attached to it stay put -- so hiding is
 * all the flag does on its own. Nothing else would stop a crafted request from changing a project
 * the UI no longer offers to change, so every endpoint that mutates a project loads it through
 * here rather than through project(). archive.php is the one exception: archiving is the change.
 */
function editable_project(int $id): Project {
    $p = project($id);
    if (!$p) {
        bad('Project not found', 404);
    }
    if ($p->archived) {
        bad('This project is archived and cannot be changed', 409);
    }
    return $p;
}
function setAssignees(int $id, array $ids): void {
    q('DELETE FROM project_assignees WHERE project_id=?', [$id]);
    foreach (array_unique(array_map('intval', $ids)) as $u) {
        q('INSERT OR IGNORE INTO project_assignees VALUES(?,?)', [$id, $u]);
    }
}
function logStatus(int $id, ?string $from, string $to, ?int $by = null): void {
    q('INSERT INTO status_log(project_id,from_status,to_status,changed_at,changed_by) VALUES(?,?,?,?,?)', [$id, $from, $to, gmdate('c'), $by]);
}

/**
 * Retires a project: it leaves every default view and board, now and for good.
 *
 * The flag is the whole of archiving: the row and everything attached to it stay where they are,
 * which is why there is nothing to rebuild and nothing to undo. Archiving is one-way, so there is
 * no counterpart function -- the value is only ever set to 1. It is written only while archived=0,
 * so a resubmitted form cannot roll the date forward on a project that has already gone.
 *
 * Callers hold the guards: only an admin may archive, which the endpoint enforces because only the
 * request knows who is asking.
 */
function archive_project(int $id): void {
    $now = gmdate('c');
    q('UPDATE projects SET archived=1, archived_at=?, updated_at=? WHERE id=? AND archived=0',
      [$now, $now, $id]);
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
    if (!status_exists($st)) {
        bad('Invalid status');
    }
    $type = $_POST['type'] ?? 'task';
    if (!isset(TYPES[$type])) {
        bad('Invalid type');
    }
    $start = validDate($_POST['start_date'] ?? '');
    $due = validDate($_POST['due_date'] ?? '');
    $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
    if ($parent) {
        $parent_project = project($parent);
        if (!$parent_project) {
            bad('Parent project not found', 404);
        }
        if ($parent_project->archived) {
            bad('This project is archived and cannot be changed', 409);   // no new subprojects
        }
    }
    $now = gmdate('c');
    q('INSERT INTO projects(title,description,status,type,creator_id,parent_id,start_date,due_date,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
      [$title, $_POST['description'] ?? '', $st, $type, $creator, $parent, $start, $due, $now, $now]);
    $id = (int)$db->lastInsertId();
    setAssignees($id, (array)($_POST['as'] ?? []));
    set_project_boards($id, (array)($_POST['boards'] ?? []), $creator);
    logStatus($id, null, $st, $creator);
    if ($parent) {                             // adding underneath a project counts as touching it
        q('UPDATE projects SET updated_at=? WHERE id=?', [$now, $parent]);
    }
    go('/project/?id=' . ($parent ?: $id));   // a subproject returns to its parent
}

/**
 * Updates an existing project and nothing else: adding a project is create_project_from_post().
 * A posted status is honoured even though the edit form has no field for one, so an update posted
 * by something other than that form can still move a project and record it in the history.
 */
function update_project_from_post(): never {
    global $me;
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        bad('Title is required');
    }
    $id = (int)($_POST['id'] ?? 0);
    $existing = editable_project($id);
    $st = $_POST['status'] ?? $existing->status;
    if (!status_exists($st)) {
        bad('Invalid status');
    }
    $type = $_POST['type'] ?? $existing->type;
    if (!isset(TYPES[$type])) {
        bad('Invalid type');
    }
    $start = validDate($_POST['start_date'] ?? '');
    $due = validDate($_POST['due_date'] ?? '');
    $now = gmdate('c');
    // The project page edits the description through its own flip card, so the field is absent
    // there: an absent field keeps what is stored, or every title edit would blank the text.
    $desc = array_key_exists('description', $_POST) ? ($_POST['description'] ?? '') : $existing->description;
    q('UPDATE projects SET title=?,description=?,status=?,type=?,start_date=?,due_date=?,updated_at=? WHERE id=?',
      [$title, $desc, $st, $type, $start, $due, $now, $id]);
    if ($st !== $existing->status) {
        logStatus($id, $existing->status, $st, $me ?? null);
    }
    go("/project/?id=$id");
}

/** Keeps redirects on this site: only root-relative paths, never protocol-relative ones. */
function safe_next(string $next): string {
    return preg_match('#^/(?!/)#', $next) ? $next : '/index.php';
}

// ---- users, roles, sessions ----

function user_exists(int $id): bool {
    global $db;
    return (bool)$db->query('SELECT 1 FROM users WHERE id=' . (int)$id)->fetchColumn();
}

function user(int $id): ?User {
    $stmt = q('SELECT * FROM users WHERE id=?', [$id]);
    $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, User::class);
    return $stmt->fetch() ?: null;
}

/** The theme behind a slug, falling back to the default for a slug that no longer names one. */
function theme_row(string $slug): array {
    return THEMES[$slug] ?? THEMES['default'];
}

/** Whether a theme slug names a theme the app knows how to draw. */
function theme_exists(string $slug): bool {
    return isset(THEMES[$slug]);
}

/** The theme a user has chosen, or 'default' when the slug stored on their row is gone. */
function user_theme(int $id): string {
    global $db;
    $slug = (string)$db->query('SELECT theme FROM users WHERE id=' . (int)$id)->fetchColumn();
    return theme_exists($slug) ? $slug : 'default';
}

function user_by_email(string $email): ?User {
    $stmt = q('SELECT * FROM users WHERE email=?', [strtolower(trim($email))]);
    $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, User::class);
    return $stmt->fetch() ?: null;
}

function user_by_slack_id(string $slack_id): ?User {
    $stmt = q('SELECT * FROM users WHERE slack_id=?', [trim($slack_id)]);
    $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, User::class);
    return $stmt->fetch() ?: null;
}

/** Link a Slack account to an existing user by id. */
function link_slack(int $user_id, string $slack_id): void {
    q('UPDATE users SET slack_id=? WHERE id=?', [trim($slack_id), $user_id]);
}

// The workspace a Slack OIDC response belongs to, or '' when it names none. Slack carries it
// as the https://slack.com/team_id claim on userInfo; older shapes use team_id or a team
// object, and the token response can carry one too. First hit wins, so a response holding
// several that disagree still resolves to one — the userInfo claim, which is the documented one.
function slack_team_id(array $userinfo, array $token = []): string {
    $team = $userinfo['team'] ?? null;
    if (is_array($team) && isset($team['id'])) {
        $userinfo['team_id'] = $team['id'];
    }
    $team = $token['team'] ?? null;
    if (is_array($team) && isset($team['id'])) {
        $token['team_id'] = $team['id'];
    }
    foreach ([$userinfo['https://slack.com/team_id'] ?? null, $userinfo['team_id'] ?? null,
              $token['team_id'] ?? null] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }
    return '';
}

/** Take the Slack match back off an account. Callers hold the guard: see unlink_slack.php. */
function unlink_slack(int $user_id): void {
    q('UPDATE users SET slack_id=NULL WHERE id=?', [$user_id]);
}


/** False for an account that has been deactivated. Such an account keeps its name on old records. */
function user_is_active(int $id): bool {
    global $db;
    return (bool)$db->query('SELECT active FROM users WHERE id=' . (int)$id)->fetchColumn();
}

/**
 * Turns sign-in on or off for an account. Callers are responsible for the guards: deactivating
 * yourself or the last admin is refused by the endpoint, not here, because only the caller knows
 * who is asking.
 */
function set_user_active(int $id, bool $active): void {
    q('UPDATE users SET active=? WHERE id=?', [$active ? 1 : 0, $id]);
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

function create_user(string $name, string $email, string $password, array $role_slugs = []): int {
    global $db;
    $stmt = $db->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');
    $stmt->execute([$name, strtolower(trim($email)), hash_password($password)]);
    $id = (int)$db->lastInsertId();
    set_roles($id, $role_slugs);
    return $id;
}
function create_user_from_slack(string $name, ?string $email, string $slack_id, array $role_slugs = []): int {
    global $db;
    $stmt = $db->prepare('INSERT INTO users(name,email,slack_id) VALUES(?,?,?)');
    $stmt->execute([$name, $email ? strtolower(trim($email)) : null, trim($slack_id)]);
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

/** Every role the app knows about, keyed to the slug stored in roles.slug. */
enum Role: string {
    case Admin = 'admin';
}

function has_role(?int $user_id, Role $role): bool {
    return $user_id !== null && in_array($role->value, roles($user_id), true);
}

// A comment can be changed by its author or by an admin, so a typo can be fixed
// and a wrong statement taken back without handing every reader an edit button.
function can_edit_comment(?int $user_id, ?int $author_id): bool {
    if ($user_id === null || $user_id === 0) {
        return false;
    }
    return $author_id === $user_id || has_role($user_id, Role::Admin);
}

/**
 * How many admins there are, optionally counting only those who could still sign in. Losing the last
 * one strands the install: nobody is left who can invite or promote anybody, and getting a
 * replacement needs SLATE_SETUP_KEY, which a plain install does not set.
 */
function admin_count(bool $active_only = false): int {
    $sql = 'SELECT COUNT(*) FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            JOIN users u ON u.id = ur.user_id
            WHERE r.slug=?';
    if ($active_only) {
        $sql .= ' AND u.active=1';
    }
    return (int)q($sql, [Role::Admin->value])->fetchColumn();
}

/** Every user with their roles, for the users page. can_login is false for name-only accounts. */
function users_list(): array {
    return q('SELECT u.id, u.name, u.email, u.active,
                     u.password_hash IS NOT NULL AS can_login, GROUP_CONCAT(r.slug) AS roles
              FROM users u
              LEFT JOIN user_roles ur ON ur.user_id = u.id
              LEFT JOIN roles r ON r.id = ur.role_id
              GROUP BY u.id
              ORDER BY u.active, u.name')->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, User::class);
}

// ---- invites ----

/** Returns the token so the caller can show the link. Valid for two weeks, single use. */
function new_invite(string $email, ?string $name, array $role_slugs, int $created_by): string {
    global $db;
    $token = bin2hex(random_bytes(24));
    // Comma-separated slugs: no separate invite_roles table for what is a handful of
    // strings — and only read whole when the invite is accepted or listed.
    $stmt = $db->prepare('INSERT INTO invites(token,email,name,roles,created_by,created_at,expires_at)
                          VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$token, strtolower(trim($email)), $name, implode(',', $role_slugs), $created_by,
                    gmdate('c'), gmdate('c', time() + SESSION_LIFETIME)]);
    return $token;
}

/** The invite behind a token, or null if it is unknown, already used or expired. */
function invite_by_token(string $token): ?Invite {
    $stmt = q('SELECT * FROM invites WHERE token=?', [$token]);
    $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Invite::class);
    $invite = $stmt->fetch();
    if (!$invite || $invite->accepted_at) {
        return null;
    }
    if (strtotime($invite->expires_at) < time()) {
        return null;
    }
    return $invite;
}

function invites_list(): array {
    return q('SELECT i.*, u.name AS created_by_name FROM invites i
              LEFT JOIN users u ON u.id = i.created_by
              WHERE i.accepted_at IS NULL
              ORDER BY i.created_at DESC')->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Invite::class);
}

function revoke_invite(string $token): void {
    q('DELETE FROM invites WHERE token=? AND accepted_at IS NULL', [$token]);
}

// ---- boards ----

/** Every board, oldest first, with how many projects sit on it. */
function boards(): array {
    return q('SELECT b.id, b.name, b.description, b.created_by, b.created_at,
                      (SELECT COUNT(*) FROM project_boards pb JOIN projects p ON p.id=pb.project_id
                        WHERE pb.board_id=b.id AND p.archived=0) AS project_count
               FROM boards b ORDER BY b.id')->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Board::class);
}

function board(int $id): ?Board {
    $stmt = q('SELECT * FROM boards WHERE id=?', [$id]);
    $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Board::class);
    return $stmt->fetch() ?: null;
}

/**
 * How a board treats each of its panels: every status mapped to one of PANEL_MODES.
 *
 * Absent rows mean the default rather than "never", so a board nobody has configured still gets the
 * three columns most work moves through. That is the whole reason for the default: without it, every
 * existing board would come up empty the first time this was deployed.
 *
 * A null board id means there is no board to have an opinion — the one caller that can pass one is
 * move.php after a drop with no board id, which re-renders everything — so it gets the default too.
 */
function board_panel_modes(?int $board_id): array {
    $modes = [];
    foreach (panel_order() as $status) {
        $modes[$status] = in_array($status, default_board_panels(), true) ? 'required' : 'hidden';
    }
    if ($board_id === null) {
        return $modes;
    }
    foreach (q('SELECT status, mode FROM board_panels WHERE board_id=?', [$board_id]) as $row) {
        // A status that is not a column any more is ignored rather than added: the columns are
        // panel_order(), so a row left behind by a delete must not bring an old column back.
        if (isset($modes[$row['status']]) && isset(PANEL_MODES[$row['mode']])) {
            $modes[$row['status']] = $row['mode'];
        }
    }
    return $modes;
}

/**
 * How many projects sit in each status on each board: [board_id => [status => n]].
 *
 * The boards page uses this to say what hiding a panel would cost before it is saved, since a panel
 * set to never shown takes its projects off the board without touching them. One query for the
 * whole page rather than one per board, because the page renders every board at once.
 */
function board_status_counts(): array {
    $out = [];
    foreach (q('SELECT pb.board_id, p.status, COUNT(*) AS n
                FROM project_boards pb JOIN projects p ON p.id = pb.project_id
                WHERE p.archived=0
                GROUP BY pb.board_id, p.status') as $row) {
        $out[(int)$row['board_id']][$row['status']] = (int)$row['n'];
    }
    return $out;
}

/**
 * Projects matching $q, subprojects included, most recently updated first.
 *
 * A project is found by its title, its description, its comments, or the names and
 * addresses of its assignees — whichever carries the words. The query is split into
 * keywords on whitespace, and every keyword has to match at least one of those places.
 * Order and the gap between words do not matter, so "power bench" and "bench supply" both
 * find "Bench Power Supply": a term only has to be somewhere in the project. That is
 * what "matches" means here, not rank: the rows stay in updated order, so two projects
 * matching the same words come back newest first rather than best first.
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
 * % and _ are escaped per keyword, so searching for them looks for them. Left alone they are
 * wildcards, and a query of "%" would come back with every project in it.
 */
function search_projects(string $q, int $limit = 0): array {
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $by_id = preg_match('/^#?(\d+)$/', $q) ? (int)ltrim($q, '#') : null;
    $groups = [];
    $args = [];
    foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) as $term) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $groups[] = "(title LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\'"
            . " OR id IN (SELECT project_id FROM comments WHERE body LIKE ? ESCAPE '\\')"
            . " OR id IN (SELECT pa.project_id FROM project_assignees pa JOIN users u ON u.id = pa.user_id"
            . " WHERE u.name LIKE ? ESCAPE '\\' OR u.email LIKE ? ESCAPE '\\'))";
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
    }
    if ($by_id !== null) {
        $args[] = $by_id;
    }
    $cap = $limit > 0 ? ' LIMIT ?' : '';
    if ($cap !== '') {
        $args[] = $limit;
    }
    $where = '(' . implode(' AND ', $groups) . ')';
    if ($by_id !== null) {
        // Bracketed so the archived condition out front applies to it too: without this the id
        // branch is ORed past `archived=0` and a number would still surface an archived project.
        $where = "($where OR id = ?)";
    }
    return q("SELECT * FROM projects
              WHERE archived=0 AND $where
              ORDER BY updated_at DESC, id DESC" . $cap, $args)->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Project::class);
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
    $rows = q("SELECT project_id, board_id FROM project_boards WHERE project_id IN ($in) ORDER BY board_id", $project_ids)->fetchAll();
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
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        // The app is served from the document root, so '/' is the whole path. Serving it from a
        // subdirectory is a rewrite at the web server, and the cookie path follows that config.
        'path' => '/',
        'httponly' => true,
        // Lax rather than Strict: invite links are followed from email, and Strict would
        // drop the cookie on that first navigation. Lax still withholds it on cross-site
        // POSTs, which is what the form endpoints need.
        'samesite' => 'Lax',
        'secure' => $secure,
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
