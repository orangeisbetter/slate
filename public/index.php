<?php
// The landing page: how much there is, and what the signed-in user is on. The boards
// themselves live at /board/?id=N, listed at /boards/.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../src/bootstrap.php';

$counts = [];                  // status => number of projects
foreach (q('SELECT status, COUNT(*) AS n FROM projects GROUP BY status') as $row) {
    $counts[$row['status']] = (int)$row['n'];
}
$total = array_sum($counts);
$open = $total - ($counts['done'] ?? 0) - ($counts['cancelled'] ?? 0);

// Assigned to you, minus anything finished. A done project asks for nothing, and a list that keeps
// showing them is a list that pushes the work still outstanding further down. 'cancelled' stays: it
// was never going to happen, which is a different thing from being finished with.
$mine = q('SELECT p.* FROM projects p
           JOIN project_assignees pa ON pa.project_id = p.id
           WHERE pa.user_id = ? AND p.status != ?
           ORDER BY p.status, p.due_date IS NULL, p.due_date, p.title', [$me, 'done'])->fetchAll();
// Only ever read as true or false. The exact count is on the Done tile above, and a sentence that has
// to agree with the number is a sentence with a wrong number in it the first time it is wrong.
$finished = (int)q('SELECT COUNT(*) FROM projects p
                    JOIN project_assignees pa ON pa.project_id = p.id
                    WHERE pa.user_id = ? AND p.status = ?', [$me, 'done'])->fetchColumn();

page_start('Dashboard');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <h1 class="h3 mb-1">Dashboard</h1>
    <p class="text-body-secondary mb-4">
        <?= h($USERS[$me] ?? '') ?>, here is where things stand.
    </p>

    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
        <?php foreach ([['All projects', $total, 'secondary'], ['Open', $open, 'primary'],
                        ['In review', $counts['review'] ?? 0, 'warning'], ['Done', $counts['done'] ?? 0, 'success']] as [$label, $n, $tone]): ?>
            <div class="col">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small"><?= h($label) ?></div>
                        <div class="fs-3 lh-1 mt-1"><?= $n ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    </div>

    <h2 class="h5 mb-3">Assigned to you</h2>
    <?php if (!$mine): ?>
        <p class="text-body-secondary">
            <?php if ($finished): ?>
                Nothing here needs you right now: everything assigned to you is finished.
            <?php else: ?>
                Nothing is assigned to you yet. Admins can assign work from any project page.
            <?php endif ?>
        </p>
    <?php else: ?>
        <div class="table-responsive card">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Boards</th>
                        <th>Due</th>
                    </tr>
                </thead>
                <tbody>
                <?php $by_project = boards_by_project(array_column($mine, 'id')); ?>
                <?php foreach ($mine as $project): ?>
                    <tr>
                        <td>
<a class="fw-medium text-decoration-none" href="project/?id=<?= (int)$project['id'] ?>">
                                  <?php ref_badge((int)$project['id']) ?><?= h($project['title']) ?>
                              </a>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= TONE[$project['status']] ?>">
                                <?= LABELS[$project['status']] ?>
                            </span>
                        </td>
                        <td><?php type_badge($project['type']) ?></td>
                        <td class="small text-body-secondary"><?php board_labels($by_project[$project['id']] ?? []) ?></td>
                        <td class="small">
                            <?php if ($project['due_date']): ?>
                                <span class="<?= $project['due_date'] < gmdate('Y-m-d') && $project['status'] !== 'done' ? 'text-danger fw-semibold' : '' ?>">
                                    <?= h($project['due_date']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
<?php
page_end();
