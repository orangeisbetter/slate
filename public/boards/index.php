<?php
// Every board, with counts. Admins can create, rename and delete here; members can only
// open a board. Deleting a board leaves its projects in place, just on no board.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$admin = is_admin($me);
$boards = boards();
$error = $_GET['error'] ?? null;
$unassigned = (int)q('SELECT COUNT(*) FROM projects p
                     WHERE NOT EXISTS (SELECT 1 FROM project_boards pb WHERE pb.project_id=p.id)')
                     ->fetchColumn();

page_start('Boards');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <h1 class="h3 mb-1">Boards</h1>
    <p class="text-body-secondary mb-4">
        A project can sit on any number of boards.
        <?php if ($unassigned): ?>
            <a href="list/?board=0"><?= $unassigned ?> project<?= $unassigned === 1 ? ' is' : 's are' ?> on no board.</a>
        <?php endif ?>
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif ?>

    <?php if (!$boards): ?>
        <p class="text-body-secondary">No boards yet.</p>
    <?php endif ?>

    <div class="row row-cols-1 row-cols-md-2 g-3 mb-4">
        <?php foreach ($boards as $board): ?>
            <?php $id = (int)$board['id']; ?>
            <div class="col">
                <div class="card h-100" id="board-<?= $id ?>">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start gap-2">
                            <div>
                                <a class="h5 mb-0 text-decoration-none d-block" href="board/?id=<?= $id ?>">
                                    <?= h($board['name']) ?>
                                </a>
                                <?php if ($board['description']): ?>
                                    <div class="text-body-secondary small"><?= h($board['description']) ?></div>
                                <?php endif ?>
                            </div>
                            <span class="badge text-bg-secondary ms-auto">
                                <?= (int)$board['project_count'] ?>
                            </span>
                        </div>
                        <?php if ($admin): ?>
                            <form method="post" action="boards/save_board.php" class="row g-2 mt-3">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <div class="col-12">
                                    <label class="form-label small" for="bn<?= $id ?>">Name</label>
                                    <input class="form-control form-control-sm" id="bn<?= $id ?>" name="name"
                                           required maxlength="80" value="<?= h($board['name']) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small" for="bd<?= $id ?>">Description</label>
                                    <input class="form-control form-control-sm" id="bd<?= $id ?>" name="description"
                                           maxlength="200" value="<?= h($board['description']) ?>">
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-outline-primary btn-sm">Save</button>
                                </div>
                            </form>
                            <form method="post" action="boards/delete_board.php" class="mt-2"
                                  onsubmit="return confirm('Delete this board? Its projects stay, but on no board.')">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn btn-outline-danger btn-sm">Delete board</button>
                            </form>
                        <?php else: ?>
                            <div class="mt-3">
                                <a class="btn btn-outline-secondary btn-sm" href="board/?id=<?= $id ?>">Open board</a>
                            </div>
                        <?php endif ?>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    </div>

    <?php if ($admin): ?>
        <div class="card">
            <div class="card-body">
                <h2 class="h5">New board</h2>
                <form method="post" action="boards/save_board.php" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="new_bn">Name</label>
                        <input class="form-control" id="new_bn" name="name" required maxlength="80">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="new_bd">Description <span class="text-body-secondary">(optional)</span></label>
                        <input class="form-control" id="new_bd" name="description" maxlength="200">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100">Create</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif ?>
</div>
<?php
page_end();
