<?php
// Flat list of every project and subproject, sorted by time. Not grouped by status.
// ?board=N narrows to one board; ?board=0 means "on no board".
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$sort = ($_GET['sort'] ?? 'updated') === 'created' ? 'created' : 'updated';   // whitelisted

$filter = (int)($_GET['board'] ?? 0);       // 0 = all boards
if ($filter && !board($filter)) {
    go(base_url() . '/index.php');
}

if ($filter > 0) {
    $all = q("SELECT p.* FROM projects p
              JOIN project_boards pb ON pb.project_id = p.id AND pb.board_id = ?
              ORDER BY p.{$sort}_at DESC, p.id DESC", [$filter])->fetchAll();
} elseif ($filter === 0 && isset($_GET['board'])) {
    $all = q("SELECT p.* FROM projects p
              WHERE NOT EXISTS (SELECT 1 FROM project_boards pb WHERE pb.project_id = p.id)
              ORDER BY p.{$sort}_at DESC, p.id DESC")->fetchAll();
} else {
    $all = q("SELECT * FROM projects ORDER BY {$sort}_at DESC, id DESC")->fetchAll();
}

$assignees = [];            // project id => names
foreach (q('SELECT * FROM project_assignees') as $row) {
    $assignees[$row['project_id']][] = $USERS[$row['user_id']] ?? '?';
}

$parent_titles = array_column($all, 'title', 'id');
$boards_by_project = boards_by_project(array_column($all, 'id'));

page_start('All projects');
?>
<div class="container-fluid p-3">
    <div class="d-flex align-items-center mb-3">
        <h1 class="h4 mb-0">All projects</h1>
        <span class="ms-3 text-body-secondary small"><?= count($all) ?> total</span>
        <div class="btn-group btn-group-sm ms-auto">
            <a class="btn btn-outline-secondary<?= $sort === 'updated' ? ' active' : '' ?>"
               href="list/?sort=updated">Recently updated</a>
            <a class="btn btn-outline-secondary<?= $sort === 'created' ? ' active' : '' ?>"
               href="list/?sort=created">Newest</a>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 mb-3 small">
        <span class="text-body-secondary">Board:</span>
        <a class="btn btn-outline-secondary btn-sm<?= !isset($_GET['board']) ? ' active' : '' ?>" href="list/">All</a>
        <?php foreach (boards() as $board): ?>
            <a class="btn btn-outline-secondary btn-sm<?= $filter === (int)$board['id'] ? ' active' : '' ?>"
               href="list/?board=<?= (int)$board['id'] ?>"><?= h($board['name']) ?></a>
        <?php endforeach ?>
        <a class="btn btn-outline-secondary btn-sm<?= $filter === 0 && isset($_GET['board']) ? ' active' : '' ?>"
           href="list/?board=0">No board</a>
    </div>
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
                            <a class="fw-medium text-decoration-none" href="project/?id=<?= $project['id'] ?>"><?= h($project['title']) ?></a>
                            <?php if ($project['parent_id']): ?>
                                <div class="small text-body-secondary">in <?= h($parent_titles[$project['parent_id']] ?? '') ?></div>
                            <?php endif ?>
                        </td>
                        <td><?php type_badge($project['type']) ?></td>
                        <td>
                            <span class="badge text-bg-<?= TONE[$project['status']] ?>"><?= LABELS[$project['status']] ?></span>
                        </td>
                        <td class="small"><?php board_labels($boards_by_project[$project['id']] ?? []) ?></td>
                        <td class="small"><?= h(implode(', ', $assignees[$project['id']] ?? [])) ?></td>
                        <td class="small"><?= h($project['due_date'] ?? '') ?></td>
                        <td class="small text-nowrap"><?= dt($project['updated_at']) ?></td>
                        <td class="small text-nowrap"><?= dt($project['created_at']) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php
page_end();
