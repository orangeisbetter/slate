<?php
// Sign-in form. src/app.php sends anonymous visitors here, and login/login.php
// redirects back on failure with a message in $_SESSION['login_error'].
require __DIR__ . '/../../src/bootstrap.php';

start_session();

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid && user_exists($uid)) {
    go('../index.php');
}
if (!credentials_count()) {
    go(base_url() . '/setup/');   // no account can sign in until someone runs setup
}

$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

page_start('Sign in', nav: false);
?>
<div class="page-center p-3 p-md-4">
    <div class="row justify-content-center w-100">
        <div class="col-sm-9 col-md-7 col-lg-5">
            <h1 class="h4 mb-3">Sign in</h1>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif ?>
            <form method="post" action="login/login.php">
                <input type="hidden" name="next" value="<?= h($_GET['next'] ?? '') ?>">
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" id="email" name="email" type="email" required autofocus
                           autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password" required
                           autocomplete="current-password">
                </div>
                <button class="btn btn-primary">Sign in</button>
            </form>
            <p class="small text-body-secondary mt-3 mb-0">
                Accounts are created by invitation. Ask an administrator for a link.
            </p>
        </div>
    </div>
</div>
<?php
page_end();
