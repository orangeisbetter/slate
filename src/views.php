<?php
// HTML building blocks shared by the pages (Bootstrap 5.3 markup).
require_once __DIR__ . '/lib.php';

// Parsedown, the markdown renderer (card #61). Composer's tree is gitignored, so a checkout needs
// `composer install` once; bin/check.php reports it if that has not happened.
require_once __DIR__ . '/../vendor/autoload.php';

const TYPE_TONE = ['task' => 'secondary', 'bug' => 'danger', 'feature' => 'success', 'epic' => 'primary', 'research' => 'info'];

// How many matches the header menu offers before it defers to the results page. Enough to cover a
// short title, few enough that the menu stays a shortcut rather than a second copy of the list view.
const SEARCH_MENU_LIMIT = 8;

/**
 * The header's project search, and the menu of matches that appears under it.
 *
 * The input is deliberately outside the region htmx swaps: replacing an element a person is typing
 * into takes the focus with it, so a box that refreshed its own results after every keystroke would
 * accept exactly one letter. Only the menu comes back from the server.
 *
 * The form is a real GET to search.php, so with JS unavailable the same box submits and lands on a
 * full page of matches. Nothing here depends on the menu existing.
 */
function search_box(string $q = ''): void {
    ?>
    <div class="search-wrap">
        <form method="get" action="/search.php" role="search">
            <input type="search" name="q" id="site-search" value="<?= h($q) ?>" class="form-control" autocomplete="off"
                   placeholder="Search projects" aria-label="Search projects" aria-keyshortcuts="/"
                   hx-get="/search.php" hx-target="#search-menu" hx-swap="innerHTML"
                   hx-trigger="input changed delay:250ms">
        </form>
        <div id="search-menu"></div>
    </div>
    <?php
}

/**
   * Just the menu, which is what search.php returns to htmx; the box's first paint is an empty
   * #search-menu slot those responses slide into. Visible or not is the server's call: an empty
   * query renders the menu hidden rather than leaving an empty panel hanging under the navbar.
   */
  function search_menu(string $q, array $hits): void {
      ?>
      <div class="dropdown-menu search-menu<?= $q === '' ? ' d-none' : '' ?>">
        <?php if ($q === ''): ?>
        <?php elseif (!$hits): ?>
            <div class="small text-body-secondary px-3 py-2">Nothing matches &ldquo;<?= h($q) ?>&rdquo;.</div>
        <?php else: ?>
            <?php foreach ($hits as $p): ?>
                <a class="dropdown-item d-flex align-items-center gap-2" href="/project/?id=<?= (int)$p->id ?>">
                    <?php ref_badge((int)$p->id) ?>
                    <span class="text-truncate"><?= h($p->title) ?></span>
                    <?php type_badge($p->type) ?>
                </a>
            <?php endforeach ?>
            <?php if (count($hits) === SEARCH_MENU_LIMIT): ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item small text-body-secondary" href="/search.php?q=<?= h(rawurlencode($q)) ?>">
                    See all matches
                </a>
            <?php endif ?>
        <?php endif ?>
    </div>
    <?php
}

// $nav false leaves the navbar off entirely, for the two pages a signed-out visitor is only ever
// shown: sign in and first-run setup. With no navigation to offer, a header is just something to
// centre a form underneath, so those pages give the whole window to the form instead.
function page_start(string $title, bool $nav = true): void {
    global $USERS, $me, $SLATE_NAV;
    $me = $me ?? null;   // sign-in and setup pages render without a signed-in user
    // page_end() is called by every page and knows nothing about this one, so whether the navbar is
    // there gets recorded rather than asked about again at the other end.
    $SLATE_NAV = $nav;
    // The reader's theme decides the Bootstrap colour mode on <html> and, when it has one, an extra
    // stylesheet after style.css. Signed out there is nobody who has chosen, so it is the default.
    $theme_slug = $me ? user_theme((int)$me) : 'default';
    $theme = theme_row($theme_slug);
    ?>
    <!doctype html>
    <html lang="en"<?= $theme['mode'] ? ' data-bs-theme="' . h($theme['mode']) . '"' : '' ?>>
    <head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="/assets/style.css">
        <?php if ($theme['css']): ?>
            <link rel="stylesheet" href="/assets/themes/<?= h($theme_slug) ?>.css">
        <?php endif ?>
        <script src="https://cdn.jsdelivr.net/npm/htmx.org@2.0.4/dist/htmx.min.js"></script>
        <!-- For the Boards dropdown and the picker's add dialog; the rest is CSS and htmx. -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
        <!-- defer: the picker and the board need the markup above them, and htmx above that. -->
        <script src="/assets/app.js" defer></script>
    </head>
    <body class="bg-body-tertiary">
        <?php if (!$nav) return; ?>
        <nav class="navbar navbar-expand-lg bg-body border-bottom">
            <div class="container-fluid gap-3">
                <a class="navbar-brand fw-semibold" href="/index.php">Slate</a>
                <?php if ($me): ?>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                            data-bs-target="#navbar" aria-controls="navbar" aria-expanded="false"
                            aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                <?php endif ?>
                <!-- `show` keeps the links visible for a signed-out visitor, who has no toggler
                     to reveal them and whose only real destination is the sign-in page. -->
                <div class="collapse navbar-collapse<?= $me ? '' : ' show' ?>" id="navbar">
                    <ul class="navbar-nav">
                        <li><a class="nav-link" href="/index.php">Dashboard</a></li>
                        <li class="nav-item dropdown">
                            <!-- href is the real destination, so the menu still works without JS. -->
                            <a class="nav-link dropdown-toggle" href="/boards/" role="button"
                               data-bs-toggle="dropdown" aria-expanded="false">Boards</a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="/boards/">All boards</a></li>
                                <?php foreach (boards() as $board): ?>
                                    <li>
                                        <a class="dropdown-item" href="/board/?id=<?= (int)$board->id ?>">
                                            <?= h($board->name) ?>
                                            <span class="badge text-bg-secondary ms-1"><?= (int)$board->project_count ?></span>
                                        </a>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        </li>
                        <li><a class="nav-link" href="/list/">List</a></li>
                        <li><a class="nav-link" href="/activity/">Activity</a></li>
                        <?php if ($me && has_role($me, Role::Admin)): ?>
                            <li><a class="nav-link" href="/admin/">Admin</a></li>
                        <?php endif ?>
                    </ul>
                    <?php if ($me): ?>
                        <ul class="navbar-nav ms-auto me-3">
                            <li class="nav-item dropdown">
                                <!-- href is the page itself, so the menu still works without JS. -->
                                <a class="nav-link dropdown-toggle" href="/account/" role="button"
                                   data-bs-toggle="dropdown" aria-expanded="false">Account</a>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <span class="dropdown-item-text">
                                            <?= h($USERS[$me] ?? 'Unknown') ?><?= has_role($me, Role::Admin) ? ' (admin)' : '' ?>
                                        </span>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item" href="/account/">Your account</a>
                                    </li>
                                    <?php if (has_role($me, Role::Admin)): ?>
                                        <li>
                                            <a class="dropdown-item" href="/admin/">Admin</a>
                                        </li>
                                    <?php endif ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="post" action="/logout.php">
                                            <button class="dropdown-item" type="submit">Sign out</button>
                                        </form>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                          <?php search_box(); ?>
                          <a class="btn btn-primary" href="/new/">New project</a>
                    <?php else: ?>
                        <a class="btn btn-outline-primary ms-auto" href="/login/">Sign in</a>
                    <?php endif ?>
                </div>
            </div>
        </nav>
    <?php
}
function page_end(): void {
    global $SLATE_NAV;
    ?>
    <?php if (!empty($SLATE_NAV)): ?>
        <!-- Only where the navbar is. Those two pages hand the whole window to a form, and a footer
             under a lone sign-in box would undo that. Written with utilities alone, like everything
             else here: page-wide is the one local class, so the footer lines up with the content
             above it without a rule of its own.

             mt-auto rather than a margin of its own, because body is a flex column at least as tall
             as the window and this collects the leftover height above itself. On a page too full to
             have any, it collapses to nothing and the gap above the rule is the content's own bottom
             padding, which every page already has. -->
        <footer class="border-top mt-auto">
            <div class="page-wide mx-auto px-3 px-md-4 py-3 d-flex flex-wrap align-items-center gap-3 small text-body-secondary">
                <!-- The footer names the repository and nowhere else to ask. With no mail transport
                     and no contact address, its issues page is the app's only contact point, and a
                     privacy policy would be a claim about one install rather than about this code. -->
                <a class="link-quiet" href="https://github.com/orangeisbetter/slate">Slate on GitHub</a>
                <a class="link-quiet" href="https://github.com/orangeisbetter/slate/issues">Report an issue or request a feature</a>
            </div>
        </footer>
    <?php endif ?>
    </body>
    </html>
    <?php
}

function type_badge(string $type): void {
    $tone = TYPE_TONE[$type] ?? 'secondary';
    ?>
    <span class="badge bg-<?= $tone ?>-subtle text-<?= $tone ?>-emphasis"><?= h(TYPES[$type] ?? $type) ?></span>
    <?php
}

// The project's own number, shown wherever a project is named so one can be picked out of a
// list or referred to out loud, then typed back into project/?id=. The number is the row id, so
// nothing is allocated and nothing can drift: ?id=4 already is the identifier. There is no word
// in front of it either, because a deployment on its own subdomain keeps its own ids and the
// subdomain is what says which install a number belongs to.
function ref_badge(int $id): void {
    ?>
    <span class="ref">#<?= $id ?></span>
    <?php
}

// A reference to a person: the admin's user list, anchored on their row. That list is admin-only,
// so only an admin gets the link — for anyone else the name is text rather than a href that would
// land on a 403, and when a real profile page replaces the list, the href here is the only thing
// that changes.
function user_link(int $id, string $name): void {
    global $me;
    if (has_role($me, Role::Admin)) {
        ?><a class="link-quiet" href="/admin/users/#user-<?= $id ?>"><?= h($name) ?></a><?php
    } else {
        ?><?= h($name) ?><?php
    }
}

// Board names for a set of board ids, each linking to the board. Projects on no board are a normal
// state, so that case is spelled out rather than left blank.
function board_labels(array $board_ids): void {
    $names = array_column(boards(), 'name', 'id');
    if (!$board_ids) {
        ?>
        <span class="text-body-secondary">no board</span>
        <?php
        return;
    }
    foreach ($board_ids as $id) {
        ?>
        <a class="badge text-bg-light border text-body me-1" href="/board/?id=<?= $id ?>"><?= h($names[$id] ?? 'Unknown') ?></a>
        <?php
    }
}

// ---- picker options ----
// The data behind the two pickers, and the only part of them that stays in this file: it is
// matching, not markup. A picker's markup is written out where it is used, because the endpoint
// that answers a pick has to render the same rows and cannot reach back into a page.
//
// $q narrows the list to what the dialog's search box matched. Boards match on name alone, since
// a description is noise when you are looking for one by name; people match on name and email,
// because an address is how you look a person up.

/** Board choices. The project count is context for the row, not a keyword. */
function board_picker_options(?string $q = null): array {
    $out = [];
    foreach (boards() as $board) {
        $n = (int)$board->project_count;
        $out[(int)$board->id] = ['label' => $board->name, 'sub' => "$n project" . ($n === 1 ? '' : 's'),
                                   'search' => lower($board->name)];
    }
    return filter_options($out, $q);
}

/** People choices, showing the email for the same reason it is searched on. */
function user_picker_options(?string $q = null): array {
    global $USERS, $db;
    $out = [];
    foreach ($db->query('SELECT id, email FROM users')->fetchAll() as $row) {
        $id = (int)$row['id'];
        $name = $USERS[$id] ?? 'Unknown';
        // An invited account has a name but no address yet, so trim rather than leave a gap.
        $out[$id] = ['label' => $name, 'sub' => $row['email'],
                     'search' => lower(trim("$name {$row['email']}"))];
    }
    return filter_options($out, $q);
}

/** Narrows picker options to those whose 'search' text contains $q. Null or '' means all of them. */
function filter_options(array $options, ?string $q): array {
    $q = lower(trim((string)$q));
    if ($q === '') {
        return $options;
    }
    return array_filter($options, fn($o) => str_contains($o['search'], $q));
}

// One project card. The link is the card, so all of it is clickable, and it is the one drag handle.
// A subproject is the same card with its parent named on it — there is no wrapper and no second card,
// which is what lets the parent be named here without the card needing a link inside a link.
// $ctx carries the lookups render_board built once for the whole page, so this stays pure formatting.
function board_card(Project $project, string $status, array $ctx): void {
    $id = (int)$project->id;
    $today = gmdate('Y-m-d');
    $comments = $ctx['comments'][$id] ?? 0;
    $overdue = $project->due_date && $project->due_date < $today && !status_is_finished($status);
    $parent_id = (int)($project->parent_id ?? 0);
    ?>
    <a class="card<?= $parent_id ? ' subproject' : '' ?> t-<?= h($project->type) ?> text-body text-decoration-none shadow-sm"
       href="/project/?id=<?= $id ?>" draggable="true" data-id="<?= $id ?>">
        <div class="card-body p-2">
            <?php if ($parent_id): ?>
                <?php // Named, not linked. This was a link once, and it made the card two targets that
                      // each covered part of it — while the part covering the badges looked nothing
                      // like a link, which was the confusing half. One target per card, and it is
                      // whichever project the card is. ?>
                <div class="small text-body-secondary mb-1">Subproject of&nbsp;<?php ref_badge($parent_id) ?><?= h($ctx['titles'][$parent_id] ?? '') ?></div>
            <?php endif ?>
            <div class="fw-medium"><?php ref_badge($id) ?><?= h($project->title) ?></div>
            <div class="d-flex align-items-center gap-2 small text-body-secondary mt-1">
                <?php type_badge($project->type); ?>
                <?php if ($project->due_date): ?>
                    <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>">Due <?= h($project->due_date) ?></span>
                <?php endif ?>
                <?php if (isset($ctx['sub_counts'][$id])): ?>
                    <span><?= $ctx['subs_done'][$id] ?? 0 ?>/<?= $ctx['sub_counts'][$id] ?> subprojects done</span>
                <?php endif ?>
                <?php if ($comments): ?>
                    <span><?= $comments ?> comment<?= $comments > 1 ? 's' : '' ?></span>
                <?php endif ?>
                <span class="ms-auto d-flex ps-1">
                    <?php foreach ($ctx['assignees'][$id] ?? [] as $user):
                        $name = $ctx['users'][$user] ?? '';
                        ?>
                        <span class="av rounded-circle text-bg-dark d-inline-flex align-items-center justify-content-center"
                              title="<?= h($name) ?>"><?= h(ini($name ?: '?')) ?></span>
                    <?php endforeach ?>
                </span>
            </div>
        </div>
    </a>
    <?php
}

// Renders the status columns for one board, or every project when $board_id is null.
// It must emit nothing but the columns: htmx swaps this straight into #board's innerHTML
// after a drag, so any heading or filter placed inside would be wiped on the first drop.
function render_board(?int $board_id = null): void {
    global $USERS;
    $all = q('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Project::class);

    $assignees = [];             // project id => user ids
    foreach (q('SELECT * FROM project_assignees') as $row) {
        $assignees[$row['project_id']][] = $row['user_id'];
    }

    $comment_counts = q('SELECT project_id, COUNT(*) FROM comments GROUP BY project_id')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    $titles = [];                // project id => title, to name the parent on a subproject
    $sub_counts = [];            // project id => number of subprojects
    $subs_done = [];             // project id => how many of them are done
    // Counted over every project, not just the ones on this board: a subproject sitting on
    // another board, or on none at all, is still part of its parent. Archived projects are
    // named but not counted: their title is still what a card points at, while their own work
    // has left the view like everything else that is archived.
    foreach ($all as $project) {
        $titles[$project->id] = $project->title;
        if ($project->archived || !$project->parent_id) {
            continue;
        }
        $sub_counts[$project->parent_id] = ($sub_counts[$project->parent_id] ?? 0) + 1;
        $subs_done[$project->parent_id] = ($subs_done[$project->parent_id] ?? 0) + (status_is_finished($project->status) ? 1 : 0);
    }

    // The flag takes a project off every board without touching it, the way it left the dashboard:
    // hidden panel or not, the card is simply not drawn.
    $all = array_values(array_filter($all, fn($project) => !$project->archived));

    if ($board_id !== null) {
        $on_board = array_flip(array_map('intval',
            q('SELECT project_id FROM project_boards WHERE board_id=?', [$board_id])->fetchAll(PDO::FETCH_COLUMN)));
        $all = array_values(array_filter($all, fn($project) => isset($on_board[$project->id])));
    }

    $ctx = ['assignees' => $assignees, 'comments' => $comment_counts, 'titles' => $titles,
            'sub_counts' => $sub_counts, 'subs_done' => $subs_done, 'users' => $USERS];

    // Which panels this board actually draws, and which of those wait until there is something in
    // them. Hidden panels are skipped outright, so a project sitting in one is on the board without
    // being on the screen: the boards page says how many that would hide before it is saved, and
    // the list view is where those projects are still reachable.
    $modes = board_panel_modes($board_id);
    foreach (panel_order() as $status) {
        $items = array_filter($all, fn($project) => $project->status === $status);
        if ($modes[$status] === 'hidden') {
            continue;
        }
        // An optional panel with nothing in it is not drawn at all, which also means it is not a
        // drop target: a card cannot be dragged somewhere that is not on the page.
        if ($modes[$status] === 'optional' && !$items) {
            continue;
        }
        ?>
        <section data-status="<?= $status ?>">
            <div class="card board-col h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <?= status_label($status) ?>
                    <span class="badge rounded-pill text-bg-<?= status_tone($status) ?>"><?= count($items) ?></span>
                </div>
                <div class="card-body d-flex flex-column gap-2">
                    <?php foreach ($items as $project):
                        // Same card either way, keeping its own column, status and drag behaviour. A
                        // subproject only adds a line naming its parent, which may well be filed on
                        // another board or left off this one, so board_card says so rather than
                        // needing a second kind of card to say it in.
                        board_card($project, $status, $ctx);
                    endforeach ?>
                    <?php if (!$items): ?>
                        <div class="text-center text-body-secondary small py-4">Drop a project here</div>
                    <?php endif ?>
                </div>
            </div>
        </section>
        <?php
    }
}


/**
 * The name and description of one board, as the two fields that set them.
 *
 * The board form is rendered in two places — as the fragment a dialog is filled with, and as the page
 * of its own that the same button's href points at — and the two must not be able to disagree about
 * the field names, the length limits or what an empty description means. Only the shell around them
 * differs; these do not.
 *
 * @param array|null $b The board, or null when there is not one yet and this is the create form.
 */
function board_form_fields(?Board $b): void {
    ?>
    <div class="mb-3">
        <label class="form-label" for="edit_bn">Name</label>
        <input class="form-control" id="edit_bn" name="name" required maxlength="80"
               value="<?= h($b?->name ?? '') ?>">
    </div>
    <div>
        <label class="form-label" for="edit_bd">
            Description <span class="text-body-secondary">(optional)</span>
        </label>
        <?php /* A textarea rather than an input: a board's description is a sentence or two, and one
              // line of a text box reads it sideways. The value goes inside the element rather than
              // in a value attribute, which is why the tags are on one line — a newline straight
              // after <textarea> would become part of the value — and it is escaped as text, the
              // same treatment a project's description already gets. */ ?>
        <textarea class="form-control" id="edit_bd" name="description" maxlength="200" rows="3"><?= h($b?->description ?? '') ?></textarea>
    </div>
    <?php
}


/**
 * The label, slug, tone and finished flag a status is created or edited with.
 *
 * Drawn in two places — the dialog's fragment and the page the button's href falls back to — so
 * the field names cannot drift apart, like board_form_fields() above.
 *
 * The slug is offered only when creating. It is what is stored in projects.status, status_log and
 * board_panels, so letting it change later would orphan every row that points at it; the edit form
 * shows it as text and says why it is not a field.
 *
 * @param ?array $s The status row, or null when there is not one yet.
 */
function status_form_fields(?array $s): void {
    ?>
    <div class="mb-3">
        <label class="form-label" for="status_label">Label</label>
        <input class="form-control" id="status_label" name="label" required maxlength="40"
               value="<?= h($s['label'] ?? '') ?>">
    </div>
    <?php if ($s === null): ?>
        <div class="mb-3">
            <label class="form-label" for="status_slug">
                Slug <span class="text-body-secondary">(optional — derived from the label)</span>
            </label>
            <input class="form-control" id="status_slug" name="slug" maxlength="40" pattern="[a-z][a-z0-9_]*">
            <div class="form-text">What is stored on every project with this status. It cannot be changed later.</div>
        </div>
    <?php else: ?>
        <div class="mb-3">
            <span class="form-label d-block">Slug</span>
            <div class="font-monospace"><?= h($s['slug']) ?></div>
            <div class="form-text">What is stored on every project with this status, so it cannot be changed.</div>
        </div>
    <?php endif ?>
    <div class="mb-3">
        <label class="form-label" for="status_tone">Badge colour</label>
        <select class="form-select" id="status_tone" name="tone">
            <?php foreach (STATUS_TONES as $tone): ?>
                <option value="<?= $tone ?>"<?= $tone === ($s['tone'] ?? 'secondary') ? ' selected' : '' ?>>
                    <?= ucfirst($tone) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="is_finished" value="1" id="status_finished"
            <?= !empty($s['is_finished']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="status_finished">Finished</label>
        <div class="form-text">Counts as finished: out of the open total, the assigned list and the list view's default filter.</div>
    </div>
    <?php
}


/**
 * The email, name and role an invite is minted for.
 *
 * Drawn in two places — the invite dialog's fragment and the invite page the button's href falls
 * back to — so the field names cannot drift apart.
 */
function invite_form_fields(): void {
    $role_options = all_roles();
    ?>
    <div class="mb-3">
        <label class="form-label" for="invite_email">Email</label>
        <input class="form-control" id="invite_email" name="email" type="email" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="invite_name">Name <span class="text-body-secondary">(optional)</span></label>
        <input class="form-control" id="invite_name" name="name">
    </div>
    <div>
        <span class="form-label d-block">Roles</span>
        <?php foreach ($role_options as $rid => $label): ?>
            <div class="form-check form-check-inline m-0">
                <input class="form-check-input" type="checkbox" name="roles[]"
                       value="<?= (int)$rid ?>" id="invite_role<?= (int)$rid ?>">
                <label class="form-check-label" for="invite_role<?= (int)$rid ?>"><?= h($label) ?></label>
            </div>
        <?php endforeach ?>
    </div>
    <?php
}


/**
 * The checkboxes that pick one user's roles.
 *
 * Drawn in two places — the roles dialog's fragment and the roles page the button's href falls
 * back to — so the two cannot disagree about which roles there are to pick.
 */
function roles_form_fields(int $user_id): void {
    $current = roles($user_id);
    $slug_map = q('SELECT id, slug FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);   // id => slug
    ?>
    <?php foreach (all_roles() as $rid => $label): ?>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="roles[]"
                   value="<?= (int)$rid ?>" id="role<?= (int)$rid ?>-<?= $user_id ?>"
                   <?= in_array($slug_map[$rid], $current, true) ? 'checked' : '' ?>>
            <label class="form-check-label" for="role<?= (int)$rid ?>-<?= $user_id ?>"><?= h($label) ?></label>
        </div>
    <?php endforeach ?>
    <?php
}


/**
 * Every status a board could have as a column, each beside its own answer.
 *
 * Shared for the same reason as board_form_fields(): a name, a count and a select per status, in the
 * one place. Which is why the count rides on the Never option rather than only beside the name — the
 * cost of hiding a column is the thing worth seeing at the moment it is chosen, and it cannot be seen
 * if it is drawn in a second copy that has drifted from this one.
 *
 * @param int   $id     The board being set up.
 * @param array $modes  Chosen mode per status, from board_panel_modes().
 * @param array $counts Projects per status on this board, keyed by status.
 */
function board_panel_rows(int $id, array $modes, array $counts): void {
    ?>
    <div class="picker-list">
        <?php foreach (panel_order() as $status): $n = $counts[$status] ?? 0; ?>
            <?php // Plain rows, no card around each one: a name and its answer, in a list. A border
                  // and a fill per row made nine little boxes out of what is really one list. The
                  // picker-list wrapper is what keeps the list from growing past the dialog. ?>
            <div class="d-flex align-items-center gap-2 mb-1">
                <label class="text-truncate" for="panel-select-<?= $id ?>-<?= $status ?>">
                    <?= status_label($status) ?>
                    <?php if ($n): ?>
                        <span class="small text-body-secondary"><?= $n ?></span>
                    <?php endif ?>
                </label>
                <?php // A select rather than a segmented control: three buttons wide did not fit the
                      // dialog, and the answers do not need to be on screen together when the count
                      // beside the name already says what choosing Never would cost. That cost rides
                      // on the option itself, so it is there at the moment it is picked. ?>
                <select class="form-select w-auto ms-auto"
                        id="panel-select-<?= $id ?>-<?= $status ?>" name="panels[<?= $status ?>]">
                    <?php foreach (PANEL_MODES as $mode => $label):
                        $cost = $mode === 'hidden' && $n ? ' (' . $n . ')' : ''; ?>
                        <option value="<?= $mode ?>"<?= $modes[$status] === $mode ? ' selected' : '' ?>>
                            <?= $label ?><?= $cost ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
        <?php endforeach ?>
    </div>
    <?php
}


function render_comments(int $project_id): void {
    $comments = Comment::getAllForProject($project_id);
    global $USERS, $me;

    if (!$comments) {
        ?>
        <p class="text-body-secondary small mb-0">No comments yet.</p>
        <?php
        return;
    }

    // An archived project is read-only, so no comment on it offers Edit or Delete, whoever wrote it.
    $archived = project_is_archived($project_id);

    // One existence lookup for every reference on the page, not one per comment: projects
    // hard-delete, so a link to an id that no longer exists stays plain text.
    $ids = [];
    foreach ($comments as $comment) {
        foreach (ref_ids_in_text($comment->body) as $id) {
            $ids[$id] = true;
        }
    }
    $exists = $ids ? array_flip(existing_project_ids(array_keys($ids))) : [];

    foreach ($comments as $comment) {
        $author = (int)$comment->author_id;
        $can_change = !$archived && can_edit_comment($me ?? null, $comment->author_id);
        ?>
        <div class="border rounded bg-body-tertiary p-2 mb-2" id="comment-<?= (int)$comment->id ?>">
            <div class="small text-body-secondary d-flex align-items-center gap-2">
                <span><?php user_link($author, $USERS[$author] ?? 'Unknown') ?>, <?= dt($comment->created_at) ?><?php if (!empty($comment->edited_at)): ?>, edited <?= dt($comment->edited_at) ?><?php endif ?></span>
                <?php if ($can_change): ?>
                    <span class="ms-auto d-flex gap-2">
                        <a href="/project/comment_edit.php?id=<?= (int)$comment->id ?>">Edit</a>
                        <form method="post" action="/project/comment_delete.php" class="d-inline"
                              onsubmit="return confirm('Delete this comment?')"
                              hx-post="/project/comment_delete.php" hx-target="#cms" hx-swap="innerHTML">
                            <input type="hidden" name="comment_id" value="<?= (int)$comment->id ?>">
                            <button type="submit" class="btn btn-link btn-sm p-0 align-baseline">Delete</button>
                        </form>
                    </span>
                <?php endif ?>
            </div>
        <div><?= rich_text($comment->body, $exists) ?></div>
        </div>
        <?php
    }
}

// Project references: writing "see #12" links #12 to /project/?id=12, the same way the header
// search already understands #12. One renderer for both surfaces it covers — comments and
// project descriptions — so the two agree.
//
// Markdown itself is Parsedown (card #61, replacing the hand-rolled engine of card #44). The
// app's additions are layered on a subclass rather than a second pass over the HTML: safe mode
// escapes any HTML in the source, single newlines stay line breaks, a bare #NN becomes a project
// link, and a link target is only trusted when md_url_allowed() accepts its scheme.
//
// A reference is a # preceded by start-of-text or a non-word character, so ABC#12 is not one.
// Inside backtick code (spans and fenced blocks) #12 is code and stays plain text: Parsedown
// consumes the code before the # is ever offered to the reference handler.

/** Every #NN id a text mentions, deduplicated. Existence is asked separately. */
function ref_ids_in_text(string $text): array {
    if (!str_contains($text, '#')) {
        return [];
    }
    preg_match_all('/(?:^|[^\w])#(\d+)/', $text, $m);
    $ids = array_unique(array_map('intval', $m[1]));
    sort($ids);
    return array_values($ids);
}

/** Which of those ids still name a project. One query for the lot. */
function existing_project_ids(array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return array_map('intval', q("SELECT id FROM projects WHERE id IN ($in)", $ids)->fetchAll(PDO::FETCH_COLUMN));
}

/** Whether a markdown link target may become a href. Relative only from the root. */
function md_url_allowed(string $url): bool {
    if (preg_match('#^https?://[^\s<>"\']+$#i', $url)) {
        return true;
    }
    return (bool)preg_match('#^/(?!/)[^\s<>"\']*$#', $url);
}

/**
 * Parsedown, configured for the app.
 *
 * Safe mode is what keeps a source's own HTML as text rather than markup, and it also runs every
 * link through a scheme check; inlineLink() below is the stricter gate the app wants. Breaks are
 * on so a single newline is still a line break, and a bare http:// or https:// URL becomes a link
 * on its own (card #62) — but only those two schemes, and only outside code, since that is all
 * Parsedown's URL handler recognises; a bare hostname is left as text. Strict mode is the
 * CommonMark rule that an ATX heading needs a space after its #, which is what lets a bare "#12"
 * reach the reference handler below instead of turning into an <h1>.
 */
class SlateParsedown extends Parsedown {
    /** id => true for the #NN ids known to name a project. */
    protected array $knownRefs = [];

    public function __construct() {
        // A '#' marker and its handler are the one thing the parser does not know about.
        $this->InlineTypes['#'] = ['ProjectRef'];
        $this->inlineMarkerList .= '#';

        $this->setSafeMode(true)
             ->setBreaksEnabled(true)
             ->setUrlsLinked(true)
             ->setStrictMode(true);
    }

    /** The ids whose #NN may become a link; called before text(). */
    public function setKnownRefs(array $known): static {
        $this->knownRefs = $known;
        return $this;
    }

    /** #NN, when the id names a project and the # is on a word boundary. */
    protected function inlineProjectRef($Excerpt) {
        if (!preg_match('/^#(\d+)/', $Excerpt['text'], $m)) {
            return null;
        }
        // The character before the marker: a word character (ABC#12) is not a reference.
        $position = strlen($Excerpt['context']) - strlen($Excerpt['text']);
        if ($position > 0 && preg_match('/\w/', $Excerpt['context'][$position - 1])) {
            return null;
        }
        $id = (int)$m[1];
        if (!isset($this->knownRefs[$id])) {
            return null;
        }
        return [
            'extent' => strlen($m[0]),
            'element' => [
                'name' => 'a',
                'text' => '#' . $id,
                'attributes' => ['href' => '/project/?id=' . $id],
            ],
        ];
    }

    /**
     * A [text](url) link, refused unless md_url_allowed() trusts the scheme. Returning null
     * makes Parsedown treat the whole `[text](url)` as text, which is what keeps a
     * javascript: link from becoming an anchor. The label may keep emphasis, but not a nested
     * #NN anchor: one target per link.
     */
    protected function inlineLink($Excerpt) {
        $Inline = parent::inlineLink($Excerpt);
        if (!isset($Inline['element']['attributes']['href'])) {
            return $Inline;
        }
        if (!md_url_allowed($Inline['element']['attributes']['href'])) {
            return null;
        }
        $Inline['element']['nonNestables'][] = 'ProjectRef';
        return $Inline;
    }

    /**
     * No images. An image in a comment would make every reader's browser fetch a remote URL the
     * author chose, which is a tracking pixel by another name; the app never offered images and
     * still does not. Refusing here leaves `![alt](url)` to fall through to an ordinary link,
     * which is exactly what the previous renderer did with it.
     */
    protected function inlineImage($Excerpt) {
        return null;
    }
}

/**
 * Parsedown's HTML for a text, with live #NN references and line breaks kept. $exists maps
 * id => anything for the ids known to name a project; left out, it is looked up.
 */
function rich_text(string $text, ?array $exists = null): string {
    if ($exists === null) {
        $ids = ref_ids_in_text($text);
        $exists = $ids ? array_flip(existing_project_ids($ids)) : [];
    }
    $parser = (new SlateParsedown())->setKnownRefs($exists);
    return $parser->text($text);
}

// The "Last updated" line under the project form. It carries an id so the htmx endpoints that
// touch a project (status change, comment) can refresh it out-of-band: both bump updated_at,
// and leaving the old line in place is the stale-page bug. The board drag is the reference
// pattern — swap what changed, not nothing.
function last_updated_line(Project $p): void {
    ?>
    <p class="text-body-secondary small mb-4" id="last-updated">Created <?= dt($p->created_at) ?>. Last updated <?= dt($p->updated_at) ?>.</p>
    <?php
}

// The status history panel's list. One helper because the project page and the status-change
// endpoint draw the same rows: two copies would drift, which is what left the panel stale.
function status_history_list(int $project_id): void {
    global $USERS;
    $history = q('SELECT * FROM status_log WHERE project_id=? ORDER BY id', [$project_id])->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, StatusLogEntry::class);
    ?>
    <ul class="list-group list-group-flush small" id="status-history-list">
        <?php foreach ($history as $entry): ?>
            <li class="list-group-item">
                <?= dt($entry->changed_at) ?>:
                <?= $entry->from_status ? status_label($entry->from_status) . ' to ' : 'Created in ' ?><?= status_label($entry->to_status) ?><?php
                $actor = $entry->changed_by ?? null;
                if ($actor !== null): ?> by <?php user_link((int)$actor, $USERS[(int)$actor] ?? 'Unknown') ?><?php endif ?>
            </li>
        <?php endforeach ?>
    </ul>
    <?php
}

// Create/edit form. $action is the script that receives it, named from the app root because every
// page lives at its own depth: '/new/add.php' from the create page, '/project/update.php' from the
// project page, which is what makes adding and updating two operations with two endpoints.
// Assignees are only chosen here when creating; on the project page they have their own panel.
// $boards only matters when creating: an existing project's boards are edited from its own
// page, in the Boards panel, so the edit form leaves them alone.
// $with_description is false on the project page, where the description has its own flip card
// next door: posting it from two forms would let one blank the other's save.
  function project_form(?Project $project, ?int $id, array $boards = [], string $action = '/new/add.php', bool $with_description = true): void {
      // The create form's two pickers. Nothing here is saved until the form is posted, so there is
    // no row for the server to send back: this is the one place a row is built in the browser.
    // They are marked data-draft-picker and the Create button submits them like any other field.
    // See app.js.
    $draft = [];
    if (!$id) {
        $draft = ['people' => ['field' => 'as[]', 'label' => 'Assignees', 'add' => 'Add assignee',
                               'title' => 'Add an assignee', 'search' => 'Search people',
                               'chosen' => [], 'options' => user_picker_options()],
                  'boards' => ['field' => 'boards[]', 'label' => 'Boards', 'add' => 'Add board',
                               'title' => 'Add a board', 'search' => 'Search boards',
                               'chosen' => $boards, 'options' => board_picker_options()]];
    }
    ?>
<form id="pf" method="post" action="<?= h($action) ?>" class="row g-3">
    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= $id ?>">
    <?php endif ?>
    <div class="col-12">
        <label class="form-label" for="title">Title</label>
        <input class="form-control" id="title" name="title" required value="<?= h($project?->title ?? '') ?>">
    </div>
    <?php if ($with_description): ?>
    <div class="col-12">
        <label class="form-label" for="desc">Description</label>
        <textarea class="form-control" id="desc" name="description" rows="6"><?= h($project?->description ?? '') ?></textarea>
    </div>
    <?php endif ?>
    <div class="col-6 col-md-4">
        <label class="form-label" for="type">Type</label>
        <select class="form-select" id="type" name="type">
            <?php foreach (TYPES as $type => $label): ?>
                <option value="<?= $type ?>"<?= $type === ( $project?->type ?? "task" ) ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label" for="start">Start date</label>
        <input type="date" class="form-control" id="start" name="start_date" value="<?= h($project?->start_date ?? '') ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label" for="due">Due date</label>
        <input type="date" class="form-control" id="due" name="due_date" value="<?= h($project?->due_date ?? '') ?>">
      </div>
  <?php foreach ($draft as $key => $d): $taken = array_map('intval', $d['chosen']); ?>
    <div class="col-12" data-draft-picker="<?= h($key) ?>" data-draft-field="<?= h($d['field']) ?>">
        <div class="form-label"><?= h($d['label']) ?></div>
        <div data-draft-rows>
            <?php foreach ($taken as $cid): $o = $d['options'][$cid] ?? null; ?>
                <div class="d-flex align-items-center gap-2 border rounded bg-body-tertiary px-2 py-1 mb-1"
                     data-draft-row data-value="<?= $cid ?>">
                    <input type="hidden" name="<?= h($d['field']) ?>" value="<?= $cid ?>">
                    <span class="text-truncate"><?= h($o['label'] ?? 'Unknown') ?></span>
                    <?php if (!empty($o['sub'])): ?>
                        <span class="small text-body-secondary text-truncate"><?= h($o['sub']) ?></span>
                    <?php endif ?>
                    <button type="button" class="btn-close ms-auto" data-draft-remove
                            aria-label="Remove <?= h($o['label'] ?? 'item') ?>"></button>
                </div>
            <?php endforeach ?>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal"
                data-bs-target="#draft-picker-<?= h($key) ?>-modal"><?= h($d['add']) ?></button>
    </div>
<?php endforeach ?>
    <div class="col-12">
        <button class="btn btn-primary"><?= $id ? 'Save changes' : 'Create project' ?></button>
    </div>
</form>
<?php
// A modal inside a form would be a form control, so the dialogs go after the closing tag.
foreach ($draft as $key => $d):
    $taken = array_map('intval', $d['chosen']); ?>
    <div class="modal fade" id="draft-picker-<?= h($key) ?>-modal" tabindex="-1"
         aria-labelledby="draft-picker-<?= h($key) ?>-title" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="draft-picker-<?= h($key) ?>-title"><?= h($d['title']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="search" class="form-control mb-2" data-draft-search autocomplete="off"
                           placeholder="<?= h($d['search']) ?>" aria-label="<?= h($d['search']) ?>">
                    <div class="picker-list" data-draft-options>
                        <?php foreach ($d['options'] as $oid => $o): ?>
                            <button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$oid, $taken, true) ? ' d-none' : '' ?>"
                                    data-draft-option data-value="<?= (int)$oid ?>"
                                    data-search="<?= h($o['search']) ?>"
                                    <?= in_array((int)$oid, $taken, true) ? 'data-draft-taken' : '' ?>>
                                <span class="text-truncate"><?= h($o['label']) ?></span>
                                <?php if (!empty($o['sub'])): ?>
                                    <span class="small text-body-secondary text-truncate"><?= h($o['sub']) ?></span>
                                <?php endif ?>
                            </button>
                        <?php endforeach ?>
                        <div class="small text-body-secondary d-none" data-draft-none>No matches.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach;
}
