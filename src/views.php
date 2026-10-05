<?php
// HTML building blocks shared by the pages (Bootstrap 5.3 markup).
require_once __DIR__ . '/lib.php';

const TONE = ['draft' => 'light', 'backlog' => 'info', 'todo' => 'secondary', 'in_progress' => 'primary',
              'blocked' => 'danger', 'review_ready' => 'info', 'review' => 'warning', 'done' => 'success',
              'cancelled' => 'dark'];
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
        <form method="get" action="search.php" role="search">
            <input type="search" name="q" value="<?= h($q) ?>" class="form-control" autocomplete="off"
                   placeholder="Search projects" aria-label="Search projects"
                   hx-get="search.php" hx-target="#search-menu" hx-swap="outerHTML"
                   hx-trigger="input changed delay:250ms">
        </form>
        <?php search_menu($q); ?>
    </div>
    <?php
}

/**
 * Just the menu, which is what search.php returns to htmx and what search_box embeds on a first
 * paint. Visible or not is the server's call: an empty query renders the menu hidden rather than
 * leaving an empty panel hanging under the navbar.
 */
function search_menu(string $q): void {
    $q = trim($q);
    $hits = search_projects($q, SEARCH_MENU_LIMIT);
    ?>
    <div id="search-menu" class="dropdown-menu search-menu<?= $q === '' ? ' d-none' : '' ?>">
        <?php if ($q === ''): ?>
        <?php elseif (!$hits): ?>
            <div class="small text-body-secondary px-3 py-2">Nothing matches &ldquo;<?= h($q) ?>&rdquo;.</div>
        <?php else: ?>
            <?php foreach ($hits as $p): ?>
                <a class="dropdown-item d-flex align-items-center gap-2" href="project/?id=<?= (int)$p['id'] ?>">
                    <?php ref_badge((int)$p['id']) ?>
                    <span class="text-truncate"><?= h($p['title']) ?></span>
                    <?php type_badge($p['type']) ?>
                </a>
            <?php endforeach ?>
            <?php if (count($hits) === SEARCH_MENU_LIMIT): ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item small text-body-secondary" href="search.php?q=<?= h(rawurlencode($q)) ?>">
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
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?></title>
        <!-- One line decides what every relative URL below is relative to: the app's root. A page
             lives in its own directory (/board/, /login/) or at the root, and neither the stylesheet,
             the script, a form's action nor an hx-post has to climb back out with ../ to match.
             The one href="#" below leans on this too: it now means the app root, not a bare page. -->
        <base href="<?= h(base_url()) ?>/">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/style.css">
        <script src="https://cdn.jsdelivr.net/npm/htmx.org@2.0.4/dist/htmx.min.js"></script>
        <!-- For the Boards dropdown and the picker's add dialog; the rest is CSS and htmx. -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
        <!-- defer: the picker and the board need the markup above them, and htmx above that. -->
        <script src="assets/app.js" defer></script>
    </head>
    <body class="bg-body-tertiary">
        <?php if (!$nav) return; ?>
        <nav class="navbar navbar-expand-lg bg-body border-bottom">
            <div class="container-fluid gap-3">
                <a class="navbar-brand fw-semibold" href="index.php">Slate</a>
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
                        <li><a class="nav-link" href="index.php">Dashboard</a></li>
                        <li class="nav-item dropdown">
                            <!-- href is the real destination, so the menu still works without JS. -->
                            <a class="nav-link dropdown-toggle" href="boards/" role="button"
                               data-bs-toggle="dropdown" aria-expanded="false">Boards</a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="boards/">All boards</a></li>
                                <?php foreach (boards() as $board): ?>
                                    <li>
                                        <a class="dropdown-item" href="board/?id=<?= (int)$board['id'] ?>">
                                            <?= h($board['name']) ?>
                                            <span class="badge text-bg-secondary ms-1"><?= (int)$board['project_count'] ?></span>
                                        </a>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        </li>
                        <li><a class="nav-link" href="list/">List</a></li>
                        <?php if ($me): ?>
                            <li><a class="nav-link" href="users/">Users</a></li>
                        <?php endif ?>
                    </ul>
                    <?php if ($me): ?>
                        <ul class="navbar-nav ms-auto me-3">
                            <li class="nav-item dropdown">
                                <!-- No page of its own, so without JS it lands on the dashboard
                                     rather than a bare "#" that <base> would resolve to the app root. -->
                                <a class="nav-link dropdown-toggle" href="./" role="button"
                                   data-bs-toggle="dropdown" aria-expanded="false">Account</a>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <span class="dropdown-item-text">
                                            <?= h($USERS[$me] ?? 'Unknown') ?><?= is_admin($me) ? ' (admin)' : '' ?>
                                        </span>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="post" action="logout.php">
                                            <button class="dropdown-item" type="submit">Sign out</button>
                                        </form>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                          <?php search_box(); ?>
                          <a class="btn btn-primary" href="new/">New project</a>
                    <?php else: ?>
                        <a class="btn btn-outline-primary ms-auto" href="login/">Sign in</a>
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
                <span class="fw-medium text-body">Slate</span>
                <span>a small project tracker</span>
                <!-- The whole of the privacy story, and it is short: nothing is collected or sent
                     anywhere by the app, and the only outside party involved is the CDN the assets
                     come from, which sees the request for them like any other. -->
                <span class="ms-auto">No tracking. Assets load from a CDN.</span>
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

// A reference to a person: the user list, anchored on their row. That list is open to any signed-in
// user, so this needs no page of its own and no role check; when a real profile page replaces it,
// the href here is the only thing that changes.
function user_link(int $id, string $name): void {
    ?>
    <a class="link-quiet" href="users/#user-<?= $id ?>"><?= h($name) ?></a>
    <?php
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
        <a class="badge text-bg-light border text-body me-1" href="board/?id=<?= $id ?>"><?= h($names[$id] ?? 'Unknown') ?></a>
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
        $n = (int)$board['project_count'];
        $out[(int)$board['id']] = ['label' => $board['name'], 'sub' => "$n project" . ($n === 1 ? '' : 's'),
                                   'search' => lower($board['name'])];
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

// One project card. $ctx carries the lookups render_board built once for the whole page, so
// this stays pure formatting. $top_level is false when the card sits inside a subproject
// wrapper, because there the wrapper is the single drag handle for the whole group.
function board_card(array $project, string $status, array $ctx, bool $top_level = true): void {
    $id = (int)$project['id'];
    $today = gmdate('Y-m-d');
    $comments = $ctx['comments'][$id] ?? 0;
    $overdue = $project['due_date'] && $project['due_date'] < $today && $status !== 'done';
    // draggable="false" matters on the nested card: an anchor with an href is draggable by
    // default, and a link drag would carry the URL instead of the project id.
    $handle = $top_level ? ' draggable="true" data-id="' . $id . '"' : ' draggable="false"';
    ?>
    <a class="card t-<?= h($project['type']) ?> text-body text-decoration-none <?= $top_level ? 'shadow-sm' : '' ?>"
       href="project/?id=<?= $id ?>"<?= $handle ?>>
        <div class="card-body p-2">
            <div class="fw-medium"><?php ref_badge($id) ?><?= h($project['title']) ?></div>
            <div class="d-flex align-items-center gap-2 small text-body-secondary mt-1">
                <?php type_badge($project['type']); ?>
                <?php if ($project['due_date']): ?>
                    <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>">Due <?= h($project['due_date']) ?></span>
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

// A subproject, contained in a card naming its parent. The wrapper rather than the inner card
// carries data-id and draggable, so the whole thing picks up and moves as one object.
function board_card_wrapper(array $project, string $status, array $ctx): void {
    $id = (int)$project['id'];
    $parent_id = (int)$project['parent_id'];
    $parent = $ctx['titles'][$parent_id] ?? '';
    ?>
    <div class="card subproject shadow-sm" draggable="true" data-id="<?= $id ?>">
        <div class="card-header py-1 px-2 small fw-normal text-body-secondary">
            Subproject of&nbsp;<a href="project/?id=<?= $parent_id ?>"><?php ref_badge($parent_id) ?><?= h($parent) ?></a>
        </div>
        <div class="card-body p-1">
            <?php board_card($project, $status, $ctx, false) ?>
        </div>
    </div>
    <?php
}

// Renders the status columns for one board, or every project when $board_id is null.
// It must emit nothing but the columns: htmx swaps this straight into #board's innerHTML
// after a drag, so any heading or filter placed inside would be wiped on the first drop.
function render_board(?int $board_id = null): void {
    global $USERS;
    $all = q('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll();

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
    // another board, or on none at all, is still part of its parent.
    foreach ($all as $project) {
        $titles[$project['id']] = $project['title'];
        if (!$project['parent_id']) {
            continue;
        }
        $sub_counts[$project['parent_id']] = ($sub_counts[$project['parent_id']] ?? 0) + 1;
        $subs_done[$project['parent_id']] = ($subs_done[$project['parent_id']] ?? 0) + ($project['status'] === 'done' ? 1 : 0);
    }

    if ($board_id !== null) {
        $on_board = array_flip(array_map('intval',
            q('SELECT project_id FROM project_boards WHERE board_id=?', [$board_id])->fetchAll(PDO::FETCH_COLUMN)));
        $all = array_values(array_filter($all, fn($project) => isset($on_board[$project['id']])));
    }

    $ctx = ['assignees' => $assignees, 'comments' => $comment_counts, 'titles' => $titles,
            'sub_counts' => $sub_counts, 'subs_done' => $subs_done, 'users' => $USERS];

    // Which panels this board actually draws, and which of those wait until there is something in
    // them. Hidden panels are skipped outright, so a project sitting in one is on the board without
    // being on the screen: the boards page says how many that would hide before it is saved, and
    // the list view is where those projects are still reachable.
    $modes = board_panel_modes($board_id);
    foreach (PANEL_ORDER as $status) {
        $items = array_filter($all, fn($project) => $project['status'] === $status);
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
                    <?= LABELS[$status] ?>
                    <span class="badge rounded-pill text-bg-<?= TONE[$status] ?>"><?= count($items) ?></span>
                </div>
                <div class="card-body d-flex flex-column gap-2">
                    <?php foreach ($items as $project):
                        // A subproject keeps its own column, status and drag behaviour. It is only
                        // wrapped so the reader can see at a glance which project it belongs to,
                        // which may well be a parent filed on another board or left off this one.
                        if ($project['parent_id']) {
                            board_card_wrapper($project, $status, $ctx);
                        } else {
                            board_card($project, $status, $ctx);
                        }
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
function board_form_fields(?array $b): void {
    ?>
    <div class="mb-3">
        <label class="form-label" for="edit_bn">Name</label>
        <input class="form-control" id="edit_bn" name="name" required maxlength="80"
               value="<?= h($b['name'] ?? '') ?>">
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
        <textarea class="form-control" id="edit_bd" name="description" maxlength="200" rows="3"><?= h($b['description'] ?? '') ?></textarea>
    </div>
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
        <?php foreach (PANEL_ORDER as $status): $n = $counts[$status] ?? 0; ?>
            <?php // Plain rows, no card around each one: a name and its answer, in a list. A border
                  // and a fill per row made nine little boxes out of what is really one list. The
                  // picker-list wrapper is what keeps the list from growing past the dialog. ?>
            <div class="d-flex align-items-center gap-2 mb-1">
                <label class="text-truncate" for="panel-select-<?= $id ?>-<?= $status ?>">
                    <?= LABELS[$status] ?>
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
    global $USERS;
    $comments = q('SELECT * FROM comments WHERE project_id=? ORDER BY id', [$project_id])->fetchAll();

    if (!$comments) {
        ?>
        <p class="text-body-secondary small mb-0">No comments yet.</p>
        <?php
        return;
    }

foreach ($comments as $comment) {
          $author = (int)$comment['author_id'];
          ?>
          <div class="border rounded bg-body-tertiary p-2 mb-2">
              <div class="small text-body-secondary">
                  <?php user_link($author, $USERS[$author] ?? 'Unknown') ?>, <?= dt($comment['created_at']) ?>
              </div>
            <div><?= nl2br(h($comment['body'])) ?></div>
        </div>
        <?php
    }
}

// Create/edit form. $action is the script that receives it, named from the app root because of
// the <base> every page carries: 'new/add.php' from the create page, 'project/update.php' from the
// project page, which is what makes adding and updating two operations with two endpoints.
// Assignees are only chosen here when creating; on the project page they have their own panel.
// $boards only matters when creating: an existing project's boards are edited from its own
// page, in the Boards panel, so the edit form leaves them alone.
function project_form(array $project, ?int $id, array $boards = [], string $action = 'new/add.php'): void {
    $default_panels = h(implode(', ', array_map(fn($status) => LABELS[$status], DEFAULT_BOARD_PANELS)));
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
        <input class="form-control" id="title" name="title" required value="<?= h($project['title']) ?>">
    </div>
    <div class="col-12">
        <label class="form-label" for="desc">Description</label>
        <textarea class="form-control" id="desc" name="description" rows="6"><?= h($project['description']) ?></textarea>
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label" for="type">Type</label>
        <select class="form-select" id="type" name="type">
            <?php foreach (TYPES as $type => $label): ?>
                <option value="<?= $type ?>"<?= $type === $project['type'] ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label" for="start">Start date</label>
        <input type="date" class="form-control" id="start" name="start_date" value="<?= h($project['start_date']) ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label" for="due">Due date</label>
        <input type="date" class="form-control" id="due" name="due_date" value="<?= h($project['due_date']) ?>">
    </div>
    <div class="col-12 form-text mt-1">A board draws one column per status it has a panel for, and a board nobody has changed draws <?= $default_panels ?>. An admin sets the rest per board. Whatever a board does not draw is still in the list view.</div>
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
