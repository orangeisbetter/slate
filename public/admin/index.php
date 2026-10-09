<?php
// The admin area: the core settings an admin is here to change. Two things, each with a page of
// its own rather than both crammed onto this one — the user table is wide, and statuses need a row
// of actions each — so this is a pair of links and nothing else. Admins only, like everything
// under it.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

require_admin();

page_start('Admin');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <h1 class="h3 mb-3">Admin</h1>
    <div class="row row-cols-1 row-cols-md-2 g-3">
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5"><a class="text-decoration-none" href="/admin/statuses/">Project statuses</a></h2>
                    <p class="text-body-secondary mb-0">
                        Create and delete the statuses projects move through, and say which of them
                        count as finished.
                    </p>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5"><a class="text-decoration-none" href="/admin/users/">Users</a></h2>
                    <p class="text-body-secondary mb-0">
                        Invite people, set roles, and switch accounts on and off.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
page_end();
