<?php
// The signed-in user's own account, in three panes: what they are called, what they sign in with, and
// what the account may do. The email address is shown read-only, because it is the identity the
// account signs in with and changing it needs a confirmation sent to the new address before it took
// effect -- which would mean an outbox this app does not have. Until that exists, read-only is the
// honest version rather than a missing feature.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$me_row = user($me);
$error = $_GET['error'] ?? null;
$saved = $_GET['saved'] ?? null;
$slugs = roles($me);

// The panes are switched with :target and :has() in public/assets/style.css. Every pane is in the
// document and CSS shows one of them, so a tab is an ordinary fragment link and switching costs no
// request and no JavaScript. Bootstrap's tab component is deliberately not used: the stylesheet half
// of it hides every pane but the active one, so without JavaScript the other two are unreachable, and
// the script half is what this app has been avoiding everywhere else. Nothing claims role="tablist" or
// aria-selected either, because those promise arrow-key movement between panes that JavaScript would
// have to provide, and claiming them without it tells a screen reader something untrue. The honest
// cost is that moving between panes does not shift focus or announce itself.
//
// Both form endpoints redirect back with a fragment -- #profile, #password -- which the browser
// applies after it follows the redirect. So a password change lands on Password without the server
// needing to be told which pane to render, and without the tab state being a second thing to keep
// consistent with the URL.
$tabs = [
    'profile'  => 'Profile',
    'password' => 'Password',
    'access'   => 'Access',
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
                              // other page, and this one already serves all three panes, so "#tab-profile"
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
                                               value="<?= h($me_row['name']) ?>">
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
                                <h2 class="h5">Your email address</h2>
                                <?php if (!empty($me_row['password_hash'])): ?>
                                    <input class="form-control mb-3" value="<?= h($me_row['email']) ?>" readonly
                                           aria-label="Your email address">
                                <?php else: ?>
                                    <p class="text-body-secondary mb-3">no account</p>
                                <?php endif ?>
                                <h2 class="h5">Slack connection</h2>
                                <?php if (!empty($me_row['slack_id'])): ?>
                                    <p class="text-success mb-0">Linked to Slack</p>
                                <?php else: ?>
                                    <p class="text-body-secondary mb-0">Not linked</p>
                                    <a class="small" href="/login/slack.php?next=/account/#tab-profile">Link Slack account</a>
                                <?php endif ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane" id="tab-password">
                <div class="row">
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
                </div>
            </div>

            <div class="tab-pane" id="tab-access">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-body">
                                <h2 class="h5">What this account may do</h2>
                                <dl class="row mb-0">
                                    <dt class="col-sm-4 fw-medium">Roles</dt>
                                    <dd class="col-sm-8">
                                        <?php if (!$slugs): ?>
                                            <span class="text-body-secondary">none</span>
                                        <?php else: ?>
                                            <?php foreach ($slugs as $slug): ?>
                                                <?php // The slug, not the label: the users list renders
                                                      // roles the same way, and one account should not
                                                      // name its own roles differently from how it names
                                                      // everybody else's. ?>
                                                <span class="badge text-bg-<?= $slug === 'admin' ? 'primary' : 'secondary' ?> me-1">
                                                    <?= h($slug) ?>
                                                </span>
                                            <?php endforeach ?>
                                        <?php endif ?>
                                    </dd>

                                    <dt class="col-sm-4 fw-medium">Status</dt>
                                    <dd class="col-sm-8 mb-0">
                                        <?php if ($me_row['active']): ?>
                                            <span class="badge text-bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Deactivated</span>
                                        <?php endif ?>
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>

                    <?php if (is_admin($me)): ?>
                        <div class="col-lg-5">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h2 class="h5">Other accounts</h2>
                                    <a class="btn btn-outline-primary" href="/users/">Manage users</a>
                                </div>
                            </div>
                        </div>
                    <?php endif ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
page_end();
