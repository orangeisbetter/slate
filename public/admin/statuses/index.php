<?php
// Every project status, in the order work moves through them, with the controls that set that
// order and the statuses themselves. This page administers: it is the one place a status is
// created, renamed, reordered, marked finished or deleted, just as /boards/ is the one place a
// board is.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_admin();

$rows = statuses();
$counts = [];
foreach (q('SELECT status, COUNT(*) AS n FROM projects GROUP BY status') as $row) {
    $counts[$row['status']] = (int)$row['n'];
}
$error = $_GET['error'] ?? null;
$last = count($rows) - 1;

page_start('Project statuses');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <div class="d-flex align-items-start gap-3 mb-1">
        <h1 class="h3 mb-0">Project statuses</h1>
        <!-- An anchor rather than a button, for the same reason as on /boards/: a button with only
             an hx-get is nothing at all without JavaScript. The href gets a page of its own; the
             hx-get fills the dialog. -->
        <a class="btn btn-primary ms-auto" href="/admin/statuses/form.php"
           data-bs-toggle="modal" data-bs-target="#status-modal"
           hx-get="/admin/statuses/form-dialog.php" hx-target="#status-content" hx-swap="outerHTML">
            New status
        </a>
    </div>
    <p class="text-body-secondary mb-4">
        The order here is the order work moves through: it is the order a board draws its columns in,
        and the order the status list on a project offers them. A status can be deleted once no
        projects are on it.
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif ?>

    <div class="table-responsive">
        <table class="table align-middle mb-4">
            <thead>
                <tr>
                    <th scope="col">Status</th>
                    <th scope="col">Slug</th>
                    <th scope="col">Projects</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $s): $id = (int)$s['id']; ?>
                    <tr id="status-<?= $id ?>">
                        <td>
                            <span class="badge text-bg-<?= h($s['tone']) ?>"><?= h($s['label']) ?></span>
                            <?php if ($s['is_finished']): ?>
                                <span class="small text-body-secondary">finished</span>
                            <?php endif ?>
                        </td>
                        <td class="font-monospace small"><?= h($s['slug']) ?></td>
                        <td><?= (int)($counts[$s['slug']] ?? 0) ?></td>
                        <td class="text-end text-nowrap">
                            <form class="d-inline" method="post" action="/admin/statuses/move.php">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="dir" value="up">
                                <button class="btn btn-outline-secondary"<?= $i === 0 ? ' disabled' : '' ?>
                                        aria-label="Move <?= h($s['label']) ?> up">&uarr;</button>
                            </form>
                            <form class="d-inline" method="post" action="/admin/statuses/move.php">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="dir" value="down">
                                <button class="btn btn-outline-secondary"<?= $i === $last ? ' disabled' : '' ?>
                                        aria-label="Move <?= h($s['label']) ?> down">&darr;</button>
                            </form>
                            <a class="btn btn-outline-secondary" href="/admin/statuses/form.php?id=<?= $id ?>"
                               data-bs-toggle="modal" data-bs-target="#status-modal"
                               hx-get="/admin/statuses/form-dialog.php?id=<?= $id ?>"
                               hx-target="#status-content" hx-swap="outerHTML">Edit</a>
                            <form class="d-inline" method="post" action="/admin/statuses/delete.php"
                                  onsubmit="return confirm('Delete &ldquo;<?= h($s['label']) ?>&rdquo;? It can only be deleted once no projects are on it.')">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn btn-outline-danger" formnovalidate>Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* One dialog shell for the whole page, like /boards/: the content that fills it arrives over
         htmx from the script that has the status to talk about, and the dialog element itself is
         not swapped, because Bootstrap holds a reference to it for the backdrop and the focus
         trap. */ ?>
<div class="modal fade" id="status-modal" tabindex="-1" aria-labelledby="status-title" aria-hidden="true">
    <div class="modal-dialog">
        <div id="status-content"></div>
    </div>
</div>
<?php
page_end();
