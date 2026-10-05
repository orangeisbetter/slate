<?php
// Checks the schema the app relies on against the migrated database.
//   php tests/schema.php [path-to-db]
// Exits non-zero on the first problem, so it can gate a deploy.
$path = $argv[1] ?? __DIR__ . '/../data/board.db';
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// SQLite defaults this off per connection, and the ON DELETE CASCADE clauses are inert without
// it. The app turns it on in src/lib.php; do the same here so these checks test what runs.
$db->exec('PRAGMA foreign_keys=ON');
echo "database: $path\n";

$failures = 0;
function ok(bool $condition, string $what): void {
    global $failures;
    printf("  %s %s\n", $condition ? 'ok  ' : 'FAIL', $what);
    $failures += $condition ? 0 : 1;
}

$tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
echo "tables\n";
foreach (['users', 'projects', 'comments', 'status_log', 'project_assignees', 'roles', 'user_roles',
          'invites', 'login_attempts', 'schema_migrations', 'boards', 'project_boards',
          'board_panels'] as $table) {
    ok(in_array($table, $tables, true), $table);
}

$columns = fn(string $table) => array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');

// Everything below assumes the auth tables exist, so stop here rather than throwing.
if (!in_array('roles', $tables, true) || !in_array('email', $columns('users'), true)) {
    echo "  FAIL  data/board.db has not been migrated\n";
    echo "        run: php bin/migrate.php\n\n1 check(s) failed\n";
    exit(1);
}

echo "users columns\n";
foreach (['id', 'name', 'email', 'password_hash'] as $column) {
    ok(in_array($column, $columns('users'), true), "users.$column");
}

echo "seeded roles\n";
$roles = $db->query('SELECT slug FROM roles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
ok($roles === ['admin', 'member'], 'roles are admin, member in that order (got: ' . implode(', ', $roles) . ')');

// Everything from here to the ledger writes probe rows into the real database, so it all runs
// inside one transaction that is rolled back at the end. A failed run leaves nothing behind.
$db->beginTransaction();

echo "constraints\n";
$indexes = $db->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='users'")->fetchAll();
$unique_email = false;
foreach ($indexes as $index) {
    if (str_contains((string)$index['sql'], 'UNIQUE') && str_contains((string)$index['sql'], 'email')) {
        $unique_email = true;
    }
}
ok($unique_email, 'users.email has a unique index');

// A seeded user with no email must not collide with another, so the index has to allow NULLs.
$db->exec("INSERT INTO users(name) VALUES('Null probe')");
$db->exec("INSERT INTO users(name) VALUES('Null probe 2')");
ok((int)$db->query("SELECT COUNT(*) FROM users WHERE name LIKE 'Null probe%'")->fetchColumn() === 2,
   'two users with NULL email can coexist');

echo "boards\n";
foreach (['id', 'name', 'description', 'created_by'] as $column) {
    ok(in_array($column, $columns('boards'), true), "boards.$column");
}
foreach (['project_id', 'board_id', 'added_by'] as $column) {
    ok(in_array($column, $columns('project_boards'), true), "project_boards.$column");
}
foreach (['board_id', 'status', 'mode'] as $column) {
    ok(in_array($column, $columns('board_panels'), true), "board_panels.$column");
}
// The mode is the whole of what a panel can be, so it is constrained in the schema rather than
// trusted from the form: a row saying something else would be a panel nothing knows how to draw.
$ddl = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='board_panels'")->fetchColumn();
ok(str_contains($ddl, "'required'") && str_contains($ddl, "'optional'") && str_contains($ddl, "'hidden'"),
   'board_panels constrains its mode');
ok(str_contains($ddl, 'PRIMARY KEY(board_id, status)'),
   'board_panels is keyed on the pair');
// The join table is what lets a project sit on several boards, so it is keyed on both sides:
// without that, a second click would file the same project twice.
$ddl = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='project_boards'")->fetchColumn();
ok((bool)preg_match('/PRIMARY KEY\s*\(\s*project_id\s*,\s*board_id\s*\)/i', $ddl),
   'project_boards is keyed on the pair');
$db->exec("INSERT INTO boards(name,description,created_by,created_at)
           VALUES('Schema probe','',NULL,'2026-01-01T00:00:00+00:00')");
$probe = (int)$db->lastInsertId();
$db->exec("INSERT INTO boards(name,description,created_by,created_at)
           VALUES('Schema probe 2','',NULL,'2026-01-01T00:00:00+00:00')");
$probe2 = (int)$db->lastInsertId();
// On a real project if there is one, so the cascade is exercised rather than assumed.
$project = (int)$db->query('SELECT id FROM projects LIMIT 1')->fetchColumn();
if ($project) {
    $db->exec("INSERT INTO projects(title,description,status,type,creator_id,created_at,updated_at)
               VALUES('Schema probe project','','todo','task',NULL,'2026-01-01T00:00:00+00:00','2026-01-01T00:00:00+00:00')");
    $probe_project = (int)$db->lastInsertId();
    foreach ([$probe, $probe2] as $board) {
        $db->exec("INSERT INTO project_boards(project_id,board_id,added_by,added_at)
                   VALUES($probe_project,$board,NULL,'2026-01-01T00:00:00+00:00')");
    }
    ok((int)$db->query("SELECT COUNT(*) FROM project_boards WHERE project_id=$probe_project")->fetchColumn() === 2,
       'one project can sit on two boards');
    // Deleting a board must take its memberships with it and leave the project itself alone.
    $db->exec("DELETE FROM boards WHERE id=$probe2");
    ok((int)$db->query("SELECT COUNT(*) FROM project_boards WHERE board_id=$probe2")->fetchColumn() === 0,
       'deleting a board removes its memberships');
    ok((int)$db->query("SELECT COUNT(*) FROM projects WHERE id=$probe_project")->fetchColumn() === 1,
       '  ...without touching the project');
} else {
    echo "  --   skipped the cascade checks: there is no project to file yet\n";
}
$db->rollBack();
ok((int)$db->query("SELECT COUNT(*) FROM boards WHERE name LIKE 'Schema probe%'")->fetchColumn() === 0,
   'probe rows rolled back');
ok((int)$db->query("SELECT COUNT(*) FROM users WHERE name LIKE 'Null probe%'")->fetchColumn() === 0,
   'user probes rolled back');

echo "ledger\n";
$migrations = $db->query('SELECT filename FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN);
ok($migrations !== [], 'at least one migration recorded');
$applied = [];
foreach ($db->query('SELECT filename, checksum FROM schema_migrations')->fetchAll() as $row) {
    $path = __DIR__ . '/../db/' . $row['filename'];
    if (!is_file($path)) {
        ok(false, "recorded migration {$row['filename']} still exists in db/");
        continue;
    }
    ok(hash_file('sha256', $path) === $row['checksum'],
       "{$row['filename']} has not changed since it was applied");
}

echo "\n" . ($failures ? "$failures check(s) failed\n" : "all checks passed\n");
exit($failures ? 1 : 0);
