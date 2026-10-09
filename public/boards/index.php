<?php
// Every board, as a list. This page displays and does not administer: creating, renaming and
// deleting a board all happen in the one dialog edit.php draws, so a page of boards is a page of
// links rather than a page of forms. Deleting a board leaves its projects in place, just on no board.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$admin = has_role($me, Role::Admin);
$boards = boards();
// No panel settings are read here: what columns a board has is the Panels dialog's business, and
// this page has no need to know. That is one query the page used to make and no longer does.
$error = $_GET['error'] ?? null;

page_start('Boards');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <div class="d-flex align-items-start gap-3 mb-1">
        <div>
            <h1 class="h3 mb-0">Boards</h1>
        </div>
        <?php if ($admin): ?>
            <!-- Every button here is a pair: an href naming the page its dialog is drawn from, and an
                 hx-get naming the fragment the dialog is filled with. htmx follows the hx-get and
                 swaps; a browser without it follows the href and gets the form on a page of its own.
                 An anchor rather than a button, because a button with only an hx-get is nothing at
                 all without JavaScript. -->
            <a class="btn btn-primary ms-auto" href="/boards/edit.php"
               data-bs-toggle="modal" data-bs-target="#board-modal"
               hx-get="/boards/edit-dialog.php" hx-target="#board-content" hx-swap="outerHTML">
                New board
            </a>
        <?php endif ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif ?>

    <?php if (!$boards): ?>
        <p class="text-body-secondary">No boards yet.</p>
    <?php endif ?>

    <?php /* A table rather than a list of cards, because a board has three facts about it and a row
             of them is a row of columns. table-responsive because the Actions cell holds two buttons
             and this is the one page where a narrow window has something to scroll. The panels a
             board has are not here at all: that is what the Panels dialog is for, and a column of
             badges beside every row was a second, worse answer to the same question. */ ?>
    <div class="table-responsive">
        <table class="table align-middle mb-4">
            <thead>
                <tr>
                    <th scope="col">Board</th>
                    <th scope="col" class="text-end">Projects</th>
                    <?php if ($admin): ?>
                        <th scope="col" class="text-end">Actions</th>
                    <?php endif ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($boards as $board): ?>
                    <?php $id = (int)$board->id; ?>
                    <tr id="board-<?= $id ?>">
                        <td>
                            <a class="fw-semibold text-decoration-none d-block" href="/board/?id=<?= $id ?>">
                                <?= h($board->name) ?>
                            </a>
                            <?php if ($board->description): ?>
                                <span class="text-body-secondary small"><?= h($board->description) ?></span>
                            <?php endif ?>
                        </td>
                        <td class="text-end"><?= (int)$board->project_count ?></td>
                        <?php if ($admin): ?>
                            <td class="text-end text-nowrap">
                                <!-- Each is a pair: href for the page, hx-get for the fragment that
                                     fills the dialog. outerHTML because the dialog arrives whole —
                                     header, body and footer, the way Bootstrap's example puts
                                     them — which is why there is a separate endpoint for it. -->
                                <a class="btn btn-outline-secondary" href="/boards/panels.php?id=<?= $id ?>"
                                   data-bs-toggle="modal" data-bs-target="#panels-modal"
                                   hx-get="/boards/panels-dialog.php?id=<?= $id ?>" hx-target="#panels-content"
                                   hx-swap="outerHTML">Panels</a>
                                <a class="btn btn-outline-secondary" href="/boards/edit.php?id=<?= $id ?>"
                                   data-bs-toggle="modal" data-bs-target="#board-modal"
                                   hx-get="/boards/edit-dialog.php?id=<?= $id ?>" hx-target="#board-content"
                                   hx-swap="outerHTML">Edit</a>
                            </td>
                        <?php endif ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?php /* One dialog of each kind for the whole page, rather than one per board. The shell is
             only the modal and its dialog: the content that goes inside arrives over htmx from the
             script that has the board to talk about, and it brings its own header, because the
             buttons belong in a modal-footer beside the body rather than inside it, and the title
             belongs with them — the server knows the board's name, so the server writes it. The
             dialog element itself is not swapped, only the div inside it, because Bootstrap holds
             a reference to it for the backdrop and the focus trap. */
    if ($admin): ?>
        <div class="modal fade" id="panels-modal" tabindex="-1" aria-labelledby="panels-title" aria-hidden="true">
            <div class="modal-dialog">
                <div id="panels-content"></div>
            </div>
        </div>
        <div class="modal fade" id="board-modal" tabindex="-1" aria-labelledby="board-title" aria-hidden="true">
            <div class="modal-dialog">
                <div id="board-content"></div>
            </div>
        </div>
    <?php endif ?>
</div>
<?php
page_end();
