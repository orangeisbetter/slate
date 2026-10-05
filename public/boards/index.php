<?php
// Every board, with counts. Admins can create, rename and delete here; members can only
// open a board. Deleting a board leaves its projects in place, just on no board.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$admin = is_admin($me);
$boards = boards();
// Panel settings are per board, but the page shows every board at once, so the counts that explain
// them are fetched once here rather than per card.
$counts_by_board = board_status_counts();
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
                                    <input class="form-control" id="bn<?= $id ?>" name="name"
                                           required maxlength="80" value="<?= h($board['name']) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small" for="bd<?= $id ?>">Description</label>
                                    <input class="form-control" id="bd<?= $id ?>" name="description"
                                           maxlength="200" value="<?= h($board['description']) ?>">
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-outline-primary">Save</button>
                                </div>
                            </form>
                            <?php
                            // The columns this board has right now, as badges rather than a form: the
                            // answer to "what does this board look like" belongs on the card, but nine
                            // controls per board does not. The dialog is where a column gets changed.
                            $modes = board_panel_modes($id);
                            $here = $counts_by_board[$id] ?? [];
                            $drawn = array_filter($modes, fn($mode) => $mode !== 'hidden');
                            $stranded = 0;
                            foreach ($modes as $status => $mode) {
                                if ($mode === 'hidden') {
                                    $stranded += $here[$status] ?? 0;
                                }
                            }
                            ?>
                            <div class="d-flex align-items-center gap-2 mt-3">
                                <span class="small text-body-secondary">Columns</span>
                                <?php foreach ($drawn as $status => $mode): ?>
                                    <span class="badge rounded-pill text-bg-<?= TONE[$status] ?><?= $mode === 'optional' ? ' border border-2' : '' ?>"
                                          title="<?= $mode === 'optional' ? 'Shown only once something is in it' : 'Always shown' ?>">
                                        <?= LABELS[$status] ?>
                                    </span>
                                <?php endforeach ?>
                                <?php if (!$drawn): ?>
                                    <span class="small text-body-secondary">none</span>
                                <?php endif ?>
                                <?php if ($stranded): ?>
                                    <span class="small text-danger" title="Still on the board with their status untouched, and still in the list view.">
                                        <?= $stranded ?> in hidden columns
                                    </span>
                                <?php endif ?>
                            </div>
                            <div class="d-flex gap-2 mt-2">
                                <!-- A link that htmx upgrades: without JavaScript this navigates to
                                     panels.php, which renders the same form as a page of its own. The
                                     dialog's title is set from the button by app.js. -->
                                <a class="btn btn-outline-secondary" href="boards/panels.php?id=<?= $id ?>"
                                   data-panel-open data-board="<?= $id ?>" data-board-name="<?= h($board['name']) ?>"
                                   data-bs-toggle="modal" data-bs-target="#panels-modal"
                                   hx-get="boards/panels.php?id=<?= $id ?>" hx-target="#panels-body"
                                   hx-swap="innerHTML">Panels</a>
                                <form method="post" action="boards/delete_board.php" class="m-0"
                                      onsubmit="return confirm('Delete this board? Its projects stay, but on no board.')">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="btn btn-outline-danger">Delete board</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="mt-3">
                                <a class="btn btn-outline-secondary" href="board/?id=<?= $id ?>">Open board</a>
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

    <?php /* One dialog for every board, rather than one per card. The body arrives from panels.php
             over htmx and names the board it belongs to, so a single shell is enough however many
             boards there are. app.js puts the board's name in the title. */ ?>
    <div class="modal fade" id="panels-modal" tabindex="-1" aria-labelledby="panels-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title fs-5" id="panels-title">Panels</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="panels-body"></div>
            </div>
        </div>
    </div>
</div>
<?php
page_end();
