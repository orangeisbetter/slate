<?php
// Quick project search from the header, and the page that same box falls back to.
//
// Over htmx this returns only the menu of matches, which the header swaps in under the input.
// Without htmx it renders a full page of them instead. Both read the same q, so the box behaves the
// same either way and the URL is worth keeping: /search.php?q=fix is a shareable list of everything
// matching "fix".
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    bad('GET only', 405);
}

$q = trim($_GET['q'] ?? '');


if (isset($_SERVER['HTTP_HX_REQUEST'])) {
    $hits = search_projects($q, SEARCH_MENU_LIMIT);
    search_menu($q, $hits);
    exit;
} else {
    // Everything, not the handful the menu offers, so the page is the honest answer to "what
    // matches" — cut into pages so a broad word does not put the whole install on one screen. The
    // match count drives the pages; the rows are the page asked for.
    $matches = search_count($q);
    $hits = search_projects($q, PAGE_SIZE, page_offset(page_number($matches)));
}

page_start($q === '' ? 'Search' : 'Search: ' . $q);
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <h1 class="h4 mb-3"><?= $q === '' ? 'Search projects' : 'Matches for &ldquo;' . h($q) . '&rdquo;' ?></h1>
    <?php if ($q === ''): ?>
        <div class="text-body-secondary">Type a title, description, comment or assignee name — or a project number such as <code>#1</code>,
            into the box in the header.</div>
    <?php elseif (!$hits): ?>
        <div class="text-body-secondary">Nothing matches.</div>
    <?php else: ?>
        <div class="text-body-secondary mb-2"><?= $matches ?> match<?= $matches === 1 ? '' : 'es' ?>.</div>
        <div class="table-responsive card">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>#</th><th>Project</th><th>Type</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($hits as $p): ?>
                    <tr>
                        <td><?php ref_badge((int)$p->id) ?></td>
                        <td><a href="/project/?id=<?= (int)$p->id ?>"><?= h($p->title) ?></a></td>
                        <td><?php type_badge($p->type) ?></td>
                        <td><span class="badge text-bg-<?= status_tone($p->status) ?>"><?= h(status_label($p->status)) ?></span></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php pagination($matches) ?>
    <?php endif ?>
</div>
<?php page_end(); ?>
