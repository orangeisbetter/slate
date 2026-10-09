<?php
// Seeds a scratch database for tests/flows.sh: the same four names the app used to fake,
// with credentials on the first so the test can sign in.
//   php tests/seed.php <path-to-db> <email> <password>
// Run after bin/migrate.php.
if ($argc < 4) {
    fwrite(STDERR, "usage: php tests/seed.php <path-to-db> <email> <password>\n");
    exit(1);
}

$db = new PDO('sqlite:' . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$names = ['Ada Lovelace', 'Grace Hopper', 'Alan Turing', 'Edsger Dijkstra'];
$insert = $db->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');

$ids = [];
foreach ($names as $i => $name) {
    // Only the first account can sign in; the rest are name-only, which is how the app
    // treats anyone who predates authentication.
    $isFirst = $i === 0;
    $insert->execute([$name, $isFirst ? $argv[2] : null, $isFirst ? password_hash($argv[3], PASSWORD_DEFAULT) : null]);
    $ids[$name] = (int)$db->lastInsertId();
}

$admin = $db->query("SELECT id FROM roles WHERE slug='admin'")->fetchColumn();
$link = $db->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)');
$link->execute([$ids[$names[0]], $admin]);

// A little history and a couple of assignees, so the board and list have something to show.
// The epic goes on General (migration 003 puts it there) plus a second board, and a subproject
// is left on no board at all, which is the case most likely to hide a bug.
$project = $db->prepare("INSERT INTO projects(title,description,status,type,creator_id,created_at,updated_at)
                         VALUES('Motor Interface','Hardware and software for the board','in_progress','epic',?,'2026-10-01T00:00:00+00:00','2026-10-01T00:00:00+00:00')");
$project->execute([$ids['Ada Lovelace']]);
$epic = (int)$db->lastInsertId();
$db->prepare("INSERT INTO status_log(project_id,from_status,to_status,changed_at) VALUES(?,NULL,'in_progress','2026-10-01T00:00:00+00:00')")
   ->execute([$epic]);
$db->prepare('INSERT INTO project_assignees(project_id,user_id) VALUES(?,?)')->execute([$epic, $ids['Ada Lovelace']]);
$db->prepare('INSERT INTO project_assignees(project_id,user_id) VALUES(?,?)')->execute([$epic, $ids['Grace Hopper']]);

// On both boards. General is where migration 003 would have put it, but that runs before this
// script seeds anything, so the membership is added here as well.
$general = (int)$db->query("SELECT id FROM boards WHERE name='General'")->fetchColumn();

$second = $db->prepare('INSERT INTO boards(name,description,created_by,created_at) VALUES(?,?,?,?)');
$second->execute(['Lab Hardware', 'Benches, instruments and rigs', $ids['Ada Lovelace'], '2026-10-01T00:00:00+00:00']);
$board = (int)$db->lastInsertId();
$membership = $db->prepare('INSERT INTO project_boards(project_id,board_id,added_by,added_at) VALUES(?,?,?,?)');
$membership->execute([$epic, $general, $ids['Ada Lovelace'], '2026-10-01T00:00:00+00:00']);
$membership->execute([$epic, $board, $ids['Ada Lovelace'], '2026-10-01T00:00:00+00:00']);

// On the second board only, so ?board=N filtering has something to tell apart.
$in_progress = $db->prepare("INSERT INTO projects(title,description,status,type,creator_id,created_at,updated_at)
                              VALUES('Bench Power Supply','Replace the failing unit','in_progress','task',?,'2026-10-02T00:00:00+00:00','2026-10-02T00:00:00+00:00')");
$in_progress->execute([$ids['Grace Hopper']]);
$supply = (int)$db->lastInsertId();
$db->prepare("INSERT INTO status_log(project_id,from_status,to_status,changed_at) VALUES(?,NULL,'in_progress','2026-10-02T00:00:00+00:00')")
   ->execute([$supply]);
$db->prepare('INSERT INTO project_boards(project_id,board_id,added_by,added_at) VALUES(?,?,?,?)')
   ->execute([$supply, $board, $ids['Grace Hopper'], '2026-10-02T00:00:00+00:00']);

$sub = $db->prepare("INSERT INTO projects(title,description,status,type,parent_id,creator_id,created_at,updated_at)
                     VALUES('Firmware Bring-up','Flash and smoke test',?,'task',?,?,'2026-10-02T00:00:00+00:00','2026-10-02T00:00:00+00:00')");
$sub->execute(['review', $epic, $ids['Ada Lovelace']]);
$subproject = (int)$db->lastInsertId();
$db->prepare("INSERT INTO status_log(project_id,from_status,to_status,changed_at) VALUES(?,NULL,'review','2026-10-02T00:00:00+00:00')")
   ->execute([$subproject]);

$boards = (int)$db->query('SELECT COUNT(*) FROM boards')->fetchColumn();
$links = (int)$db->query('SELECT COUNT(*) FROM project_boards')->fetchColumn();
echo "seeded " . count($ids) . " users, projects $epic/$supply/$subproject, $boards boards, $links memberships\n";
