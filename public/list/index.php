<?php
// Flat list of every project and subproject, sorted by time. Not grouped by status.
// ?board=N narrows to one board; ?board=0 means "on no board".
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$sort = ($_GET['sort'] ?? 'updated') === 'created' ? 'created' : 'updated';   // whitelisted

function parseIntArray(mixed $value): ?array {
    if (!is_array($value) || !array_is_list($value)) {
        return null;
    }

    $result = [];

    foreach ($value as $item) {
        if (!is_string($item) || filter_var($item, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $result[] = (int) $item;
    }

    return $result;
}

function parseStringArray(mixed $value): ?array {
    if (!is_array($value) || !array_is_list($value)) {
        return null;
    }

    $result = [];

    foreach ($value as $item) {
        if (!is_string($item)) {
            return null;
        }

        $result[] = $item;
    }

    return $result;
}

/** @var ?int[] */
$boards = parseIntArray($_GET["board"] ?? []);
if (!is_array($boards)) {
    go("/index.php");
}

/** @var ?string[] */
// array_values because array_diff keeps the keys of what it did not remove, and a gap where a
// finished status sits in the middle of the order is not a list — which parseStringArray then
// refuses, taking the page to /index.php for no reason anyone could see.
$statuses = parseStringArray($_GET["status"] ?? array_values(array_diff(panel_order(), finished_slugs())));
if (!is_array($statuses)) {
    go("/index.php");
}

// ============
// Build filter
// ============

// Archived projects leave the list unless this view is asked for them, which is how they stay out
// of every default view without becoming unreachable. The archived view is where a retired project
// is read afterwards, since there is no way back; it is opt-in rather than the default because a
// retired project in the working list is the clutter archiving was meant to remove.
$show_archived = !empty($_GET['archived']);

$real_boards = array_filter($boards, fn($board) => $board !== 0);
$has_no_board = in_array(0, $boards, true);

$board_conditions = [];
$params = [];

if (count($real_boards) > 0) {
    $placeholders = implode(",", array_fill(0, count($real_boards), "?"));
    $board_conditions[] = "pb.board_id IN ($placeholders)";
    $params = array_merge($params, $real_boards);
}

if ($has_no_board) {
    $board_conditions[] = "pb.project_id IS NULL";
}

// Every condition is ANDed together. The board rule is one group of ORs inside brackets, since a
// project matches it through any of its boards; the rest are single tests.
$conditions = [];
if (count($board_conditions) > 0) {
    $conditions[] = "(" . implode(" OR ", $board_conditions) . ")";
}

if (count($statuses) > 0) {
    $placeholders = implode(",", array_fill(0, count($statuses), "?"));
    $conditions[] = "p.status IN ($placeholders)";
    $params = array_merge($params, $statuses);
}

if (!$show_archived) {
    $conditions[] = "p.archived = 0";
}

$where_sql = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

$sql = <<<SQL
    SELECT DISTINCT p.*
    FROM projects p
    LEFT JOIN project_boards pb ON pb.project_id = p.id
    $where_sql
    ORDER BY p.{$sort}_at DESC, p.id DESC
SQL;

// The count comes first so the page number can be clamped to what exists, and the same WHERE and
// arguments drive both queries: a count that disagreed with the rows would put the last page out of
// reach or leave it empty.
$total = (int)q("SELECT COUNT(DISTINCT p.id) FROM projects p
                 LEFT JOIN project_boards pb ON pb.project_id = p.id
                 $where_sql", $params)->fetchColumn();
$page = page_number($total);
$all = q($sql . ' LIMIT ? OFFSET ?', array_merge($params, [PAGE_SIZE, page_offset($page)]))
    ->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Project::class);

// Only the rows on this page are asked for, so the lookups below narrow to them rather than reading
// the whole tables the way they did when every project was on the page.
$page_ids = array_map(fn($p) => (int)$p->id, $all);
$assignees = [];            // project id => user ids, so each name can be linked to its user
if ($page_ids) {
    $in = implode(',', array_fill(0, count($page_ids), '?'));
    foreach (q("SELECT * FROM project_assignees WHERE project_id IN ($in)", $page_ids) as $row) {
        $assignees[$row['project_id']][] = (int)$row['user_id'];
    }
}

// Parent titles are looked up by the ids the page actually names, not read off the page's own rows:
// a subproject whose parent sits on another page still has to say what it is inside.
$parent_titles = [];
$parent_ids = array_values(array_unique(array_filter(array_map(fn($p) => (int)($p->parent_id ?? 0), $all))));
if ($parent_ids) {
    $in = implode(',', array_fill(0, count($parent_ids), '?'));
    $parent_titles = q("SELECT id, title FROM projects WHERE id IN ($in)", $parent_ids)->fetchAll(PDO::FETCH_KEY_PAIR);
}

$boards_by_project = boards_by_project($page_ids);

page_start('All projects');
?>
<div class="container-fluid p-3">
    <div class="d-flex align-items-center mb-3">
        <h1 class="h4 mb-0">All projects</h1>
        <span class="ms-3 text-body-secondary small"><?= $total ?> total</span>
        <div class="btn-group btn-group-sm ms-auto">
            <a class="btn btn-outline-secondary<?= $sort === 'updated' ? ' active' : '' ?>"
               href="/list/?sort=updated">Recently updated</a>
            <a class="btn btn-outline-secondary<?= $sort === 'created' ? ' active' : '' ?>"
               href="/list/?sort=created">Newest</a>
        </div>
    </div>
    <form class="d-flex align-items-center gap-3 mb-3" method="GET">
        <div class="d-flex align-items-center gap-2">
            <span class="text-body-secondary">Status:</span>
            <multi-select name="status[]">
                <?php foreach (panel_order() as $status): ?>
                    <option
                        value="<?= h($status) ?>"
                        <?= (empty($statuses) && !in_array($status, finished_slugs(), true) || in_array($status, $statuses)) ? "selected" : "" ?>
                        data-default="<?= !in_array($status, finished_slugs(), true) ? "true" : "false" ?>"
                    ><?= status_label($status) ?></option>
                <?php endforeach ?>
            </multi-select>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-body-secondary">Board:</span>
            <multi-select name="board[]">
                <option value="0" <?= (empty($boards) || in_array(0, $boards)) ? "selected" : "" ?>>No board</option>
                <?php foreach (boards() as $board): ?>
                    <option value="<?= (int)$board->id ?>" <?= (empty($boards) || in_array($board->id, $boards)) ? "selected" : "" ?>><?= h($board->name) ?></option>
                <?php endforeach ?>
            </multi-select>
        </div>
        <?php // Archived projects are here only when asked for, which is how they stay out of the
              // default list without becoming unreachable. There is no Restore -- archiving is
              // one-way -- so this view is where a retired project is read, not where it is undone. ?>
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="archived" value="1"
                   id="show-archived" <?= $show_archived ? 'checked' : '' ?>>
            <label class="form-check-label text-body-secondary" for="show-archived">Include archived</label>
        </div>
        <button class="btn btn-primary">Apply filters</button>
    </form>
    <div class="table-responsive card">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Boards</th>
                    <th>Assignees</th>
                    <th>Due</th>
                    <th>Updated</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$all): ?>
                    <tr><td colspan="8" class="text-center text-body-secondary py-4">No projects here.</td></tr>
                <?php endif ?>
                <?php foreach ($all as $project): ?>
                    <tr>
                        <td>
<a class="fw-medium text-decoration-none" href="/project/?id=<?= $project->id ?>"><?php ref_badge((int)$project->id) ?><?= h($project->title) ?></a>
                              <?php if ($project->archived): ?>
                                  <span class="badge text-bg-secondary">Archived</span>
                                  <?php if ($project->archived_at): ?>
                                      <span class="small text-body-secondary"><?= dt($project->archived_at) ?></span>
                                  <?php endif ?>
                              <?php endif ?>
                              <?php if ($project->parent_id): ?>
                                  <div class="small text-body-secondary">in <?php ref_badge((int)$project->parent_id) ?><?= h($parent_titles[$project->parent_id] ?? '') ?></div>
                            <?php endif ?>
                        </td>
                        <td><?php type_badge($project->type) ?></td>
                        <td>
                            <span class="badge text-bg-<?= status_tone($project->status) ?>"><?= status_label($project->status) ?></span>
                        </td>
                        <td class="small"><?php board_labels($boards_by_project[$project->id] ?? []) ?></td>
                        <td class="small">
                            <?php foreach ($assignees[$project->id] ?? [] as $i => $au): ?>
                                <?= $i ? ', ' : '' ?><?php user_link($au, $USERS[$au] ?? '?') ?>
                            <?php endforeach ?>
                        </td>
                        <td class="small"><?= h($project->due_date ?? '') ?></td>
                        <td class="small text-nowrap"><?= dt($project->updated_at) ?></td>
                        <td class="small text-nowrap"><?= dt($project->created_at) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php pagination($total) ?>
</div>
<?php
page_end();
