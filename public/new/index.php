<?php
// Create form. ?board=N preselects the board the user was browsing, so "New project here"
// from a board lands the project on it.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$blank = ['title' => '', 'description' => '', 'status' => 'todo', 'type' => 'task',
          'start_date' => '', 'due_date' => '', 'assignees' => []];

$board_id = (int)($_GET['board'] ?? 0);
$boards = ($board_id && board($board_id)) ? [$board_id] : [];

page_start('New project');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/index.php">Dashboard</a></li>
            <?php if ($board_id && board($board_id)): ?>
                <li class="breadcrumb-item"><a href="/board/?id=<?= $board_id ?>"><?= h(board($board_id)['name']) ?></a></li>
            <?php endif ?>
            <li class="breadcrumb-item active">New project</li>
        </ol>
    </nav>
    <h1 class="h3 mb-3">New project</h1>
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <?php project_form($blank, null, $boards, '/new/add.php') ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
page_end();
