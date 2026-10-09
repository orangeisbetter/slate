<?php
// The user list, for admins. Everyone who can be invited or named is here, and only admins can
// reach the page: it carries the invite controls, the role editor and the deactivate buttons, and
// a page that showed the list without them would be a second, worse version of nothing. A person's
// name elsewhere in the app links here for an admin and is plain text for anyone else — see
// user_link() in src/views.php.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../../src/bootstrap.php';

require_admin();

/** @var User[] */
$users = users_list();
$labels_by_slug = q('SELECT slug, label FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
$invites = invites_list();
$new_token = $_GET['invite'] ?? null;

page_start('Users');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <div class="d-flex align-items-start gap-3 mb-3">
        <h1 class="h3 mb-0">Users</h1>
        <!-- An anchor rather than a button, for the same reason as on /boards/: a button with only
             an hx-get is nothing at all without JavaScript. The href gets a page of its own; the
             hx-get fills the dialog. -->
        <a class="btn btn-primary ms-auto" href="/admin/users/invite-form.php"
           data-bs-toggle="modal" data-bs-target="#invite-modal"
           hx-get="/admin/users/invite-dialog.php" hx-target="#invite-content" hx-swap="outerHTML">
            Invite someone
        </a>
    </div>

    <?php if ($new_token): ?>
        <?php $link = '/invite/?token=' . h($new_token); ?>
        <div class="alert alert-success">
            <div>Invitation created. Send this link to the new user:</div>
            <div class="input-group mt-2">
                <input class="form-control font-monospace" id="invite-link" readonly
                       value="<?= $link ?>" onclick="this.select()">
                <button class="btn btn-outline-success" type="button"
                        onclick="navigator.clipboard.writeText(document.getElementById('invite-link').value)">
                    Copy
                </button>
            </div>
        </div>
    <?php endif ?>

    <div class="card mb-4">
        <div class="card-body">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Roles</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($users as $u): ?>
                      <tr id="user-<?= (int)$u->id ?>">
                        <td>
                            <?= h($u->name) ?>
                            <?php if ((int)$u->id === $me): ?>
                                <span class="text-body-secondary small">(you)</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php if ($u->can_login): ?>
                                <?= h($u->email) ?>
                            <?php else: ?>
                                <span class="text-body-secondary">
                                    <?= h($u->email ?? 'no account') ?>
                                    <?php if (!$u->email): ?>
                                        &mdash; invited again to sign in
                                    <?php endif ?>
                                </span>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php if ($u->active): ?>
                                <span class="badge text-bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Deactivated</span>
                            <?php endif ?>
                            <?php // The state is posted rather than inferred from what it is now, so
                                  // resubmitting this form cannot flip it back. See set_active.php.
                                  // Your own row gets a button too: switching yourself off is how
                                  // the last admin hands over, once someone else can sign in. ?>
                            <form class="d-inline" method="post" action="/admin/users/set_active.php"
                                  onsubmit="return confirm('<?= (int)$u->id === $me
                                      ? 'Deactivate your own account? You will be signed out, and another admin will have to switch it back on.'
                                      : ($u->active
                                          ? 'Deactivate this account? They will be signed out and will not be able to sign in again.'
                                          : 'Let this account sign in again?') ?>')">

                                <input type="hidden" name="user_id" value="<?= (int)$u->id ?>">
                                <input type="hidden" name="active" value="<?= $u->active ? 0 : 1 ?>">
                                <button class="btn btn-sm btn-outline-<?= $u->active ? 'danger' : 'secondary' ?>">
                                    <?= $u->active ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <?php $slugs = $u->roleSlugs(); ?>
                            <?php if (!$slugs): ?>
                                <span class="text-body-secondary">none</span>
                            <?php else: ?>
                                <?php foreach ($slugs as $slug): ?>
                                    <span class="badge text-bg-<?= $slug === Role::Admin->value ? 'primary' : 'secondary' ?> me-1">
                                        <?= h($slug) ?>
                                    </span>
                                <?php endforeach ?>
                            <?php endif ?>
                        </td>
                        <td class="text-end">
                            <?php if ((int)$u->id === $me): ?>
                                <span class="text-body-secondary small">
                                    your own roles
                                </span>
                            <?php else: ?>
                                <a class="btn btn-outline-secondary btn-sm"
                                   href="/admin/users/roles-edit.php?id=<?= (int)$u->id ?>"
                                   data-bs-toggle="modal" data-bs-target="#roles-modal"
                                   hx-get="/admin/users/roles-dialog.php?id=<?= (int)$u->id ?>"
                                   hx-target="#roles-content" hx-swap="outerHTML">Edit</a>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="h5">Pending invitations</h2>
    <?php if (!$invites): ?>
        <p class="text-body-secondary">None.</p>
    <?php else: ?>
        <table class="table align-middle">
            <thead>
                <tr><th>Email</th><th>Role</th><th>Created</th><th>Expires</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($invites as $inv): ?>
                <tr>
                    <td><?= h($inv->email) ?></td>
                    <td>
                        <?php $inv_slugs = $inv->roles; ?>
                        <?php if (!$inv_slugs): ?>
                            <span class="text-body-secondary">none</span>
                        <?php else: ?>
                            <?php foreach ($inv_slugs as $slug): ?>
                                <span class="badge text-bg-secondary me-1"><?= h($labels_by_slug[$slug] ?? $slug) ?></span>
                            <?php endforeach ?>
                        <?php endif ?>
                    </td>
                    <td class="text-body-secondary small">
                        <?= dt($inv->created_at) ?>
                        by <?= h($inv->created_by_name ?? '?') ?>
                    </td>
                    <td class="text-body-secondary small"><?= dt($inv->expires_at) ?></td>
                    <td class="text-end">
                        <form class="d-inline" method="post" action="/admin/users/revoke_invite.php">
                            <input type="hidden" name="token" value="<?= h($inv->token) ?>">
                            <button class="btn btn-outline-danger btn-sm">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>

    <?php /* The content that fills each dialog arrives over htmx; the shells stay here empty. One of
             each for the whole page rather than one per row. */ ?>
    <div class="modal fade" id="invite-modal" tabindex="-1" aria-labelledby="invite-title" aria-hidden="true">
        <div class="modal-dialog">
            <div id="invite-content"></div>
        </div>
    </div>
    <div class="modal fade" id="roles-modal" tabindex="-1" aria-labelledby="roles-title" aria-hidden="true">
        <div class="modal-dialog">
            <div id="roles-content"></div>
        </div>
    </div>
</div>
<?php
page_end();
