<?php
// Invite acceptance: /invite/?token=... Turns an invite into an account and signs
// the new user in. Reachable while signed out, so it asks for no SLATE_REQUIRE_LOGIN.
require __DIR__ . '/../../src/bootstrap.php';

start_session();

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$invite = invite_by_token($token);
// Only worth working out when there is an invite: an unknown token renders the refusal below and
// never reaches the role, and asking a null array for a key is a warning that used to go unnoticed.
$role = $invite ? (all_roles()[$invite['role_id']] ?? 'Member') : null;
$error = $_GET['error'] ?? null;

page_start('Accept invitation');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <div class="row justify-content-center">
        <div class="col-sm-9 col-md-7 col-lg-5">
            <?php if (!$invite): ?>
                <h1 class="h4 mb-3">Invitation not valid</h1>
                <p class="text-body-secondary">
                    This link has already been used, has expired, or does not exist.
                    Ask an administrator for a new one.
                </p>
                <a class="btn btn-outline-secondary" href="login/">Back to sign in</a>
            <?php else: ?>
                <h1 class="h4 mb-1">Join as <?= h($role) ?></h1>
                <p class="text-body-secondary small">
                    Invited by <?= h($invite['created_by_name'] ?? 'an administrator') ?>
                    for <?= h($invite['email']) ?>.
                    Expires <?= dt($invite['expires_at']) ?>.
                </p>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= h($error) ?></div>
                <?php endif ?>
                <form method="post" action="invite/accept_invite.php">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" id="name" name="name" required autofocus
                               autocomplete="name" value="<?= h($invite['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" name="password" type="password" required
                               minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password">
                        <div class="form-text">At least <?= MIN_PASSWORD ?> characters.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password2">Confirm password</label>
                        <input class="form-control" id="password2" name="password2" type="password" required
                               minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary">Create account</button>
                </form>
            <?php endif ?>
        </div>
    </div>
</div>
<?php
page_end();
