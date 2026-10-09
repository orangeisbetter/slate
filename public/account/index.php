<?php
// The signed-in user's own account, in two panes: what they are called, and how they sign in.
// The email address is shown read-only, because it is the identity the account signs in with and
// changing it needs a confirmation sent to the new address before it took effect -- which would
// mean an outbox this app does not have. Until that exists, read-only is the honest version rather
// than a missing feature. Roles are read-only here for the opposite reason: they are what an admin
// grants at /admin/users/, and a form that let an account hand itself a role would be a way around
// every check that role is subject to.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$me_row = user($me);
$error = $_GET['error'] ?? null;
$saved = $_GET['saved'] ?? null;
$slugs = roles($me);

// The panes are switched with :target and :has() in public/assets/style.css. Every pane is in the
// document and CSS shows one of them, so a tab is an ordinary fragment link and switching costs no
// request and no JavaScript. Bootstrap's tab component is deliberately not used: the stylesheet half
// of it hides every pane but the active one, so without JavaScript the other pane is unreachable,
// and the script half is what this app has been avoiding everywhere else. Nothing claims
// role="tablist" or aria-selected either, because those promise arrow-key movement between panes
// that JavaScript would have to provide, and claiming them without it tells a screen reader
// something untrue. The honest cost is that moving between panes does not shift focus or announce
// itself.
//
// Both form endpoints redirect back with a fragment -- #tab-profile, #tab-signin -- which the
// browser applies after it follows the redirect. So a password change lands on Sign-in without the
// server needing to be told which pane to render, and without the tab state being a second thing
// to keep consistent with the URL.
$tabs = [
    'profile' => 'Profile',
    'signin'  => 'Sign-in',
];

page_start('Your account');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <h1 class="h3 mb-3">Your account</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif ?>
    <?php if ($saved): ?>
        <div class="alert alert-success"><?= h($saved) ?></div>
    <?php endif ?>

    <?php // The tab bar is sticky, because jumping to a fragment scrolls the pane to the top of the
          // window and would otherwise take the bar off the top with it, leaving nothing to click
          // back with. The current tab is marked in CSS rather than markup, for the reason above.
          // The panes sit outside the nav landmark: they are the page's content, not navigation. ?>
    <div class="account-tabs">
        <nav aria-label="Account sections">
            <ul class="nav nav-tabs mb-3">
                <?php foreach ($tabs as $key => $label): ?>
                    <li class="nav-item">
                        <?php // A bare fragment is safe here, and only here: these links exist on no
                              // other page, and this one already serves both panes, so "#tab-profile"
                              // resolves against /account/ and lands back on this page. The redirects in
                              // the endpoints below still name /account/ because they arrive by POST
                              // from somewhere else. ?>
                        <a class="nav-link" href="#tab-<?= h($key) ?>"><?= h($label) ?></a>
                    </li>
                <?php endforeach ?>
            </ul>
        </nav>

        <div class="tab-content">
            <div class="tab-pane" id="tab-profile">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-body">
                                <h2 class="h5">Your name</h2>
                                <form method="post" action="/account/update_name.php"
                                      class="row g-2 align-items-end">
                                    <div class="col-sm-8">
                                        <label class="form-label" for="name">Name</label>
                                        <input class="form-control" id="name" name="name" required maxlength="100"
                                               value="<?= h($me_row->name) ?>">
                                    </div>
                                    <div class="col-sm-4">
                                        <button class="btn btn-primary w-100">Save</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card h-100">
                            <div class="card-body">
                                <h2 class="h5">Your roles</h2>
                                <?php if (!$slugs): ?>
                                    <p class="text-body-secondary mb-0">none</p>
                                <?php else: ?>
                                    <?php foreach ($slugs as $slug): ?>
                                        <?php // The slug, not the label: the users list renders
                                              // roles the same way, and one account should not
                                              // name its own roles differently from how it names
                                              // everybody else's. ?>
                                        <span class="badge text-bg-<?= $slug === Role::Admin->value ? 'primary' : 'secondary' ?> me-1">
                                            <?= h($slug) ?>
                                        </span>
                                    <?php endforeach ?>
                                <?php endif ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane" id="tab-signin">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-body">
                                <h2 class="h5">Change your password</h2>
                                <?php // autocomplete="current-password" on the first field and
                                      // "new-password" on the other two, so a password manager offers
                                      // the right thing in each and does not fill the new one with the
                                      // old. Same fields and names as the sign-in and invite forms, so
                                      // nothing about filling this in is new. ?>
                                <form method="post" action="/account/change_password.php"
                                      class="row g-2 align-items-end">
                                    <div class="col-12">
                                        <label class="form-label" for="current">Current password</label>
                                        <input class="form-control" id="current" name="current" type="password"
                                               required autocomplete="current-password">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="password">New password</label>
                                        <input class="form-control" id="password" name="password" type="password"
                                               required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="password2">Confirm new password</label>
                                        <input class="form-control" id="password2" name="password2" type="password"
                                               required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password">
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-primary">Change password</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card h-100">
                            <div class="card-body">
                                <h2 class="h5">Your email address</h2>
                                <?php if (!empty($me_row->password_hash)): ?>
                                    <input class="form-control mb-3" value="<?= h($me_row->email) ?>" readonly
                                           aria-label="Your email address">
                                <?php else: ?>
                                    <p class="text-body-secondary mb-3">no account</p>
                                <?php endif ?>

                                <h2 class="h5">Slack connection</h2>
                                <?php if (!empty($me_row->slack_id)): ?>
                                    <p class="text-success mb-2">Linked to Slack</p>
                                    <?php // Same pattern as the other destructive forms here and on
                                          // /admin/statuses/: the browser's confirm() before the POST,
                                          // since there is no undo dialog of our own to draw. ?>
                                    <form method="post" action="/account/unlink_slack.php" class="mb-0"
                                          onsubmit="return confirm('Unlink Slack from this account?')">
                                        <button class="btn btn-outline-danger">Unlink</button>
                                    </form>
                                <?php else: ?>
                                    <p class="text-body-secondary mb-1">Not linked</p>
                                    <a class="small" href="/login/slack.php?next=/account/#tab-signin">Link Slack account</a>
                                <?php endif ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
page_end();
