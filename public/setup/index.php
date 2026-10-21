<?php
// First-run setup: creates the first account, which gets the admin role. Anyone can
// reach this page only while no account can sign in, or by supplying SLATE_SETUP_KEY.
require __DIR__ . '/../../src/bootstrap.php';

start_session();

$uid = session_user_id();
if ($uid) {
    go('/index.php');
}

// Once an admin exists this page would be an unauthenticated way to mint new admins,
// so it closes unless the operator set a key in the environment.
$setup_key = (string)(getenv('SLATE_SETUP_KEY') ?: '');
if (credentials_count() && $setup_key === '') {
    go('/login/');
}

$error = $_GET['error'] ?? null;

page_start('Set up', nav: false);
?>
<div class="page-center p-3 p-md-4">
    <div class="row justify-content-center w-100">
        <div class="col-sm-9 col-md-7 col-lg-5">
            <h1 class="h4 mb-1">Create the first account</h1>
            <p class="text-body-secondary small">
                This account becomes the administrator who can invite everyone else.
            </p>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif ?>
            <form method="post" action="/setup/accept_setup.php">
                <?php if ($setup_key !== ''): ?>
                    <div class="mb-3">
                        <label class="form-label" for="setup_key">Setup key</label>
                        <input class="form-control" id="setup_key" name="setup_key" type="password"
                               required autocomplete="off">
                    </div>
                <?php endif ?>
                <div class="mb-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" required autofocus
                           autocomplete="name">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" id="email" name="email" type="email" required
                           autocomplete="username">
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
        </div>
    </div>
</div>
<?php
page_end();
