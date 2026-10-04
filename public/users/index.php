<?php
// The user list. Anyone signed in can see it; only admins get the invite controls and
// the role editor. Reached via src/app.php, so $me is already resolved.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$admin = is_admin($me);
$users = users_list();
$role_options = all_roles();                                   // id => label
$role_map = q('SELECT id, slug FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);   // id => slug
$invites = $admin ? invites_list() : [];
$new_token = $_GET['invite'] ?? null;

page_start('Users');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <h1 class="h3 mb-3">Users</h1>

    <?php if ($admin && $new_token): ?>
        <?php $link = base_url() . '/invite/?token=' . h($new_token); ?>
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
                        <th>Roles</th>
                        <?php if ($admin): ?><th class="text-end">Change roles</th><?php endif ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <?= h($u['name']) ?>
                            <?php if ((int)$u['id'] === $me): ?>
                                <span class="text-body-secondary small">(you)</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php if ($u['can_login']): ?>
                                <?= h($u['email']) ?>
                            <?php else: ?>
                                <span class="text-body-secondary">
                                    <?= h($u['email'] ?? 'no account') ?>
                                    <?php if (!$u['email']): ?>
                                        &mdash; invited again to sign in
                                    <?php endif ?>
                                </span>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php $slugs = array_filter(explode(',', (string)$u['roles'])); ?>
                            <?php if (!$slugs): ?>
                                <span class="text-body-secondary">none</span>
                            <?php else: ?>
                                <?php foreach ($slugs as $slug): ?>
                                    <span class="badge text-bg-<?= $slug === 'admin' ? 'primary' : 'secondary' ?> me-1">
                                        <?= h($slug) ?>
                                    </span>
                                <?php endforeach ?>
                            <?php endif ?>
                        </td>
                        <?php if ($admin): ?>
                        <td class="text-end">
                            <?php if ((int)$u['id'] === $me): ?>
                                <span class="text-body-secondary small">
                                    your own roles
                                </span>
                            <?php else: ?>
                                <form class="d-inline-flex align-items-center gap-2" method="post"
                                      action="users/roles.php">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <?php foreach ($role_options as $rid => $label): ?>
                                        <div class="form-check form-check-inline m-0">
                                            <input class="form-check-input" type="checkbox" name="roles[]"
                                                   value="<?= (int)$rid ?>"
                                                   id="role<?= (int)$rid ?>-<?= (int)$u['id'] ?>"
                                                   <?= in_array($role_map[$rid], $slugs, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label small"
                                                   for="role<?= (int)$rid ?>-<?= (int)$u['id'] ?>"><?= h($label) ?></label>
                                        </div>
                                    <?php endforeach ?>
                                    <button class="btn btn-outline-secondary btn-sm">Save</button>
                                </form>
                            <?php endif ?>
                        </td>
                        <?php endif ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($admin): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5">Invite someone</h2>
                <form method="post" action="users/invite.php" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="invite_email">Email</label>
                        <input class="form-control" id="invite_email" name="email" type="email" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="invite_name">Name <span class="text-body-secondary">(optional)</span></label>
                        <input class="form-control" id="invite_name" name="name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="invite_role">Role</label>
                        <select class="form-select" id="invite_role" name="role_id">
                            <?php foreach ($role_options as $rid => $label): ?>
                                <option value="<?= (int)$rid ?>"><?= h($label) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100">Create link</button>
                    </div>
                </form>
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
                        <td><?= h($inv['email']) ?></td>
                        <td><?= h($inv['role_id'] ? $role_options[$inv['role_id']] : 'Member') ?></td>
                        <td class="text-body-secondary small">
                            <?= dt($inv['created_at']) ?>
                            by <?= h($inv['created_by_name'] ?? '?') ?>
                        </td>
                        <td class="text-body-secondary small"><?= dt($inv['expires_at']) ?></td>
                        <td class="text-end">
                            <form class="d-inline" method="post" action="users/revoke_invite.php">
                                <input type="hidden" name="token" value="<?= h($inv['token']) ?>">
                                <button class="btn btn-outline-danger btn-sm">Revoke</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    <?php endif ?>
</div>
<?php
page_end();
