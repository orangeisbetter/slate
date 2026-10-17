<?php
// Admin only: creates a status, or edits one when an id arrives. The slug is set at creation and
// never changes after that — it is what is stored in projects.status, status_log and board_panels,
// so renaming it would orphan every row that points at it. The label is the thing that changes.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

// POST creates, PATCH edits an existing one when an id arrives; posting the edit works too.
require_method('PATCH');
$me = require_admin();

$in = request_data();
$id = (int)($in['id'] ?? 0);
$label = trim((string)($in['label'] ?? ''));
$tone = (string)($in['tone'] ?? 'secondary');
$is_finished = isset($in['is_finished']) ? 1 : 0;

/** Where the form comes back to, carrying why. */
function statuses_error(string $msg): never {
    go('/admin/statuses/?error=' . rawurlencode($msg));
}

if ($label === '') {
    statuses_error('A status needs a label');
}
if (chars($label) > 40) {
    statuses_error('Status labels are limited to 40 characters');
}
if (!in_array($tone, STATUS_TONES, true)) {
    statuses_error('Unknown badge colour: ' . $tone);
}

if ($id) {
    $row = null;
    foreach (statuses() as $s) {
        if ((int)$s['id'] === $id) {
            $row = $s;
        }
    }
    if (!$row) {
        bad('No such status', 404);
    }
    q('UPDATE project_statuses SET label=?, tone=?, is_finished=? WHERE id=?',
      [$label, $tone, $is_finished, $id]);
} else {
    $slug = (string)($in['slug'] ?? '');
    $slug = $slug === '' ? status_slug_from_label($label) : lower(trim($slug));
    if ($slug === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
        statuses_error('Slugs start with a letter and hold only letters, digits and underscores');
    }
    if (chars($slug) > 40) {
        statuses_error('Slugs are limited to 40 characters');
    }
    if (status_exists($slug)) {
        statuses_error('A status with that slug already exists');
    }
    $next = 1 + (int)q('SELECT COALESCE(MAX(sort_order), 0) FROM project_statuses')->fetchColumn();
    q('INSERT INTO project_statuses(slug,label,tone,sort_order,is_finished) VALUES(?,?,?,?,?)',
      [$slug, $label, $tone, $next, $is_finished]);
}

status_cache_clear();
go('/admin/statuses/');
