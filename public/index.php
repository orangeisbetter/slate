<?php
// The landing page: how much there is, and what the signed-in user is on. The boards
// themselves live at /board/?id=N, listed at /boards/.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../src/bootstrap.php';

// Archived projects are not counted anywhere on this page: they have left the working set, and a
// total that still includes them is a number nobody can act on. They are still reachable, and
// restorable, through the list's archived view.
$counts = [];                  // status => number of projects
foreach (q('SELECT status, COUNT(*) AS n FROM projects WHERE archived=0 GROUP BY status') as $row) {
    $counts[$row['status']] = (int)$row['n'];
}
$total = array_sum($counts);
$open = $total - array_sum(array_map(fn($slug) => $counts[$slug] ?? 0, finished_slugs()));

// Assigned to you, minus anything finished. A finished project asks for nothing, and a list that
// keeps showing them is a list that pushes the work still outstanding further down. Which statuses
// count as finished is per status, set at /admin/statuses/, and asked of the table rather than
// collected into the query so that deleting every finished status leaves this page working.
$mine = q('SELECT p.* FROM projects p
           JOIN project_assignees pa ON pa.project_id = p.id
           WHERE pa.user_id = ?
             AND p.archived=0
             AND NOT EXISTS (SELECT 1 FROM project_statuses s WHERE s.slug = p.status AND s.is_finished)
           ORDER BY p.status, p.due_date IS NULL, p.due_date, p.title', [$me])
    ->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Project::class);
// Only ever read as true or false. The exact count is on the Done tile above, and a sentence that has
// to agree with the number is a sentence with a wrong number in it the first time it is wrong.
$finished = (int)q('SELECT COUNT(*) FROM projects p
                    JOIN project_assignees pa ON pa.project_id = p.id
                    WHERE pa.user_id = ?
                      AND p.archived=0
                      AND EXISTS (SELECT 1 FROM project_statuses s WHERE s.slug = p.status AND s.is_finished)',
                   [$me])->fetchColumn();

page_start('Dashboard');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <h1 class="h3 mb-1">Dashboard</h1>
    <p class="text-body-secondary mb-4">
        <?= h($USERS[$me] ?? '') ?>, here is where things stand.
    </p>

    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
        <?php // Two tiles that are always there, one per finished status, and the review queue while
              // there is a review status to count. Built from the table so an admin adding a finished
              // status gets a tile without this page being edited. ?>
        <?php $tiles = [['All projects', $total, 'secondary'], ['Open', $open, 'primary']];
              if (status_exists('review')) {
                  $tiles[] = [status_label('review'), $counts['review'] ?? 0, status_tone('review')];
              }
              foreach (statuses() as $row) {
                  if ($row['is_finished']) {
                      $tiles[] = [$row['label'], $counts[$row['slug']] ?? 0, $row['tone']];
                  }
              } ?>
        <?php foreach ($tiles as [$label, $n, $tone]): ?>
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
                <?php $by_project = boards_by_project(array_map(fn($p) => $p->id, $mine)); ?>
                <?php foreach ($mine as $project): ?>
                    <tr>
                        <td>
<a class="fw-medium text-decoration-none" href="/project/?id=<?= (int)$project->id ?>">
                                  <?php ref_badge((int)$project->id) ?><?= h($project->title) ?>
                              </a>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= status_tone($project->status) ?>">
                                <?= status_label($project->status) ?>
                            </span>
                        </td>
                        <td><?php type_badge($project->type) ?></td>
                        <td class="small text-body-secondary"><?php board_labels($by_project[$project->id] ?? []) ?></td>
                        <td class="small">
                            <?php if ($project->due_date): ?>
                                <span class="<?= $project->due_date < gmdate('Y-m-d') && !status_is_finished($project->status) ? 'text-danger fw-semibold' : '' ?>">
                                    <?= h($project->due_date) ?>
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
