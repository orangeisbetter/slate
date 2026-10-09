<?php
// One user's roles as a page of its own, for the browser that is not running htmx. The Edit button
// in each row on /admin/users/ is a real href aimed here, and its hx-get points at roles-dialog.php
// instead, so this is where they land when the upgrade does not happen. Setting roles must not
// depend on a dialog being openable.
//
// Its twin, admin/users/roles-dialog.php, answers the same user with the dialog's contents. They are
// separate scripts rather than one branching on the HX-Request header because each of them is then
// one thing: this one is a page, that one is a fragment, and neither has to decide while it is
// halfway through drawing. The fields themselves are drawn by roles_form_fields() in views.php, so
// which roles there are to pick cannot differ between the two. It posts to roles.php, the endpoint
// the inline form used, so there is no saving of its own to drift out of step with it.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
$target = $id ? user($id) : null;
if (!$target) {
    bad('No such user', 404);
}

page_start('Roles');
?>
<div class="page-center p-3 p-md-4">
    <div class="row justify-content-center w-100">
        <div class="col-sm-9 col-md-7 col-lg-5">
            <h1 class="h4">Roles &mdash; <?= h($target->name) ?></h1>
            <form method="post" action="/admin/users/roles.php">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <?php roles_form_fields($id); ?>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a class="btn btn-outline-secondary" href="/admin/users/">Cancel</a>
                    <button class="btn btn-primary">Save roles</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
page_end();
