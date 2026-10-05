<?php
// The kanban for one board: /board/?id=N
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$b = board($id);

if (!$b) {
    http_response_code(404);
    page_start('Not found');
    ?>
    <div class="page-wide mx-auto p-4">
        <p>Board not found. <a href="boards/">Back to boards</a></p>
    </div>
    <?php
    page_end();
    exit;
}

// data-board lets app.js tell move.php which board to re-render after a drag.
$on_board = array_map('intval', q('SELECT project_id FROM project_boards WHERE board_id=?', [$id])
                      ->fetchAll(PDO::FETCH_COLUMN));

page_start($b['name']);
// render_board() draws nothing at all when every panel is hidden, which is a board an admin can
// reach by configuration and would otherwise find broken. An optional panel that is merely empty is
// not this case: it arrives on its own as soon as something lands in it, which is what it is for.
// Resolved once here rather than inside the loop, so this costs the one query it needs.
$modes = board_panel_modes($id);
$all_hidden = !array_filter($modes, fn($mode) => $mode !== 'hidden');
?>
<div class="container-fluid p-3">
    <div class="d-flex align-items-start gap-3 mb-3 px-1">
        <div>
            <h1 class="h4 mb-0"><?= h($b['name']) ?></h1>
            <?php if ($b['description']): ?>
                <div class="text-body-secondary small"><?= h($b['description']) ?></div>
            <?php endif ?>
        </div>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="text-body-secondary small"><?= count($on_board) ?> project<?= count($on_board) === 1 ? '' : 's' ?></span>
            <a class="btn btn-outline-secondary" href="new/?board=<?= $id ?>">New project here</a>
            <?php if (is_admin($me)): ?>
                <a class="btn btn-outline-secondary" href="boards/#board-<?= $id ?>">Edit</a>
            <?php endif ?>
        </div>
    </div>
    <main id="board" data-board="<?= $id ?>">
        <?php if ($all_hidden): ?>
            <p class="text-body-secondary mb-0">
                Every panel on this board is set to never show, so there is nothing to draw.
                <?php if (is_admin($me)): ?>
                    <a href="boards/#board-<?= $id ?>">Choose which panels it has</a>.
                <?php endif ?>
            </p>
        <?php else: ?>
            <?php render_board($id); ?>
        <?php endif ?>
    </main>
</div>
<?php
page_end();
