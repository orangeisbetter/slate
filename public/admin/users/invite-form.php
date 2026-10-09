<?php
// The invite form as a page of its own, for the browser that is not running htmx. The Invite someone
// button on /admin/users/ is a real href aimed here, and its hx-get points at invite-dialog.php instead,
// so this is where that lands when the upgrade does not happen. Inviting must not depend on a dialog
// being openable.
//
// Its twin, admin/users/invite-dialog.php, answers with the dialog's contents. The fields themselves are
// drawn by invite_form_fields() in views.php, so what they are called cannot differ between the two.
// It posts to invite.php, the endpoint the inline form used, so there is no saving of its own to
// drift out of step.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_admin();

page_start('Invite someone');
?>
<div class="page-center p-3 p-md-4">
    <div class="row justify-content-center w-100">
        <div class="col-sm-9 col-md-7 col-lg-5">
            <h1 class="h4">Invite someone</h1>
            <form method="post" action="/admin/users/invite.php">
                <?php invite_form_fields(); ?>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a class="btn btn-outline-secondary" href="/admin/users/">Cancel</a>
                    <button class="btn btn-primary">Create link</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
page_end();
