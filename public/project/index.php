<?php
// One page per project or subproject: /project/?id=N
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$p = project($id);

if (!$p) {
	bad('Project not found', 404);
}

// Ancestors of this project, outermost first, for the breadcrumb.
$ancestors = [];
$current = $p;
while ($current->parent_id && ($current = project($current->parent_id))) {
	array_unshift($ancestors, $current);
}

// An archived project is retired: the page still opens and reads in full, but nothing on it may be
// changed. Every control below is drawn only while the project is editable, and the endpoints
// behind them refuse an archived project even if a request is crafted to reach them anyway.
$editable = !$p->archived;

$subprojects = q('SELECT * FROM projects WHERE parent_id=? ORDER BY id', [$id])->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Project::class);

page_start($p->title);
?>
	<div class="page-wide mx-auto p-3 p-md-4">
		<nav aria-label="breadcrumb">
			<ol class="breadcrumb">
				<li class="breadcrumb-item"><a href="/index.php">Dashboard</a></li>
				<?php foreach ($ancestors as $ancestor): ?>
					<li class="breadcrumb-item"><a href="/project/?id=<?= $ancestor->id ?>"><?php ref_badge((int)$ancestor->id) ?><?= h($ancestor->title) ?></a></li>
				<?php endforeach ?>
				<li class="breadcrumb-item active"><?= h($p->title) ?></li>
			</ol>
		</nav>
		<div class="d-flex align-items-center gap-2 mb-2">
			<h1 class="h3 mb-0"><?php ref_badge($id) ?><?= h($p->title) ?></h1>
			<?php type_badge($p->type) ?>
			<?php // Archived is a state, not a hidden fact: the page still opens, and the badge is what
			       // says why it is on no board and in no list. ?>
			<?php if ($p->archived): ?>
				<span class="badge text-bg-secondary">Archived</span>
			<?php endif ?>
		</div>
		<div class="row mb-3">
			<div class="col-auto">
				<?php if ($editable): ?>
					<select class="form-select" name="status" hx-patch="/project/update_status.php" hx-target="#status-history-list" hx-swap="outerHTML" hx-vals='{"id": <?= $id ?>}'>
						<?php // panel_order(), the order work moves through — one list everywhere the statuses
						       // are offered, so a status reads the same here as on the board. ?>
						<?php foreach (panel_order() as $status): ?>
							<option value="<?= $status ?>"<?= $status === $p->status ? ' selected' : '' ?>><?= status_label($status) ?></option>
						<?php endforeach ?>
					</select>
				<?php else: ?>
					<span class="badge text-bg-<?= status_tone($p->status) ?>"><?= status_label($p->status) ?></span>
				<?php endif ?>
			</div>
		</div>
		<div class="row g-4">
		<div class="col-lg-8">
			<?php if ($editable): ?>
			<?php // The description flip card: the rendered text is the view, the textarea the edit
			       // state, and the card holds one at a time — the place it is read is the place it
			       // is written. The switch is a hidden checkbox flipped by labels, so unlike a
			       // fragment link it navigates nowhere and scrolls nothing; saving posts to
			       // description.php next door. ?>
			<div class="card mb-2 desc-card">
				<input type="checkbox" id="desc-toggle" class="desc-toggle visually-hidden">
				<div class="card-body" id="desc-view">
					<div class="d-flex align-items-center gap-2 mb-2">
						<span class="fw-semibold">Description</span>
						<label class="btn btn-sm btn-outline-secondary ms-auto" for="desc-toggle">Edit</label>
					</div>
					<?php if (trim($p->description) !== ''): ?>
						<div id="project-description" class="md-body"><?= rich_text($p->description) ?></div>
					<?php else: ?>
						<div class="text-body-secondary small">No description yet.</div>
					<?php endif ?>
				</div>
				<div class="card-body" id="desc-edit">
					<form method="post" action="/project/description.php">
						<input type="hidden" name="id" value="<?= $id ?>">
						<label class="form-label fw-semibold" for="desc">Description</label>
						<textarea class="form-control mb-2" id="desc" name="description" rows="6"><?= h($p->description) ?></textarea>
						<div class="form-text mb-2">Markdown: <code>**bold**</code> for <strong>bold</strong>, <code>*italic*</code> for <em>italic</em>, <code>`code`</code> for <code>code</code>, <code>- list</code>, <code>[text](url)</code>, and a bare <code>https://</code> URL links too</div>
						<button class="btn btn-primary">Save description</button>
						<label class="btn btn-outline-secondary" for="desc-toggle">Cancel</label>
					</form>
				</div>
			</div>
			<div class="card mb-2">
				<div class="card-body">
					<?php project_form($p, $id, [], '/project/update.php', false) ?>
				</div>
			</div>
			<?php else: ?>
			<?php // Archived reads the same, but as facts: the description is shown, and the fields
			       // are a definition list rather than inputs, because nothing here may be changed. ?>
			<div class="card mb-2">
				<div class="card-body">
					<div class="fw-semibold mb-2">Description</div>
					<?php if (trim($p->description) !== ''): ?>
						<div id="project-description" class="md-body"><?= rich_text($p->description) ?></div>
					<?php else: ?>
						<div class="text-body-secondary small">No description yet.</div>
					<?php endif ?>
				</div>
			</div>
			<div class="card mb-2">
				<div class="card-body">
					<dl class="row mb-0">
						<dt class="col-sm-3 fw-normal text-body-secondary">Type</dt>
						<dd class="col-sm-9"><?php type_badge($p->type) ?></dd>
						<dt class="col-sm-3 fw-normal text-body-secondary">Start date</dt>
						<dd class="col-sm-9"><?= h($p->start_date ?: '—') ?></dd>
						<dt class="col-sm-3 fw-normal text-body-secondary">Due date</dt>
						<dd class="col-sm-9"><?= h($p->due_date ?: '—') ?></dd>
					</dl>
				</div>
			</div>
			<?php endif ?>
				<?php last_updated_line($p) ?>
				<div class="card mb-3">
					<div class="card-header fw-semibold">Subprojects</div>
					<div class="list-group list-group-flush">
						<?php if (!$subprojects): ?>
							<div class="list-group-item text-body-secondary small">No subprojects yet.</div>
						<?php endif ?>
						<?php foreach ($subprojects as $sub): ?>
							<a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="/project/?id=<?= $sub->id ?>">
								<?php type_badge($sub->type) ?>
								<?php ref_badge((int)$sub->id) ?>
								<?= h($sub->title) ?>
								<?php if ($sub->archived): ?>
									<span class="badge text-bg-secondary">Archived</span>
								<?php endif ?>
								<?php if ($sub->due_date): ?>
									<small class="text-body-secondary">due <?= h($sub->due_date) ?></small>
								<?php endif ?>
								<span class="badge text-bg-<?= status_tone($sub->status) ?> ms-auto"><?= status_label($sub->status) ?></span>
							</a>
						<?php endforeach ?>
					</div>
					<div class="card-body">
						<?php if ($editable): ?>
							<form class="input-group" method="post" action="/project/add.php">
								<input class="form-control" name="title" required placeholder="New subproject title">
								<input type="hidden" name="parent_id" value="<?= $id ?>">
								<button class="btn btn-outline-primary">Add subproject</button>
							</form>
						<?php else: ?>
							<p class="text-body-secondary small mb-0">Archived, so no new subprojects can be added.</p>
						<?php endif ?>
					</div>
				</div>
				<div class="card" id="comments">
					<div class="card-header fw-semibold">Comments</div>
					<div class="card-body">
						<div id="cms">
							<?php render_comments($id) ?>
						</div>
						<?php if ($editable): ?>
							<form class="mt-3" method="post" action="/project/comment.php" hx-post="/project/comment.php" hx-target="#cms" hx-on::after-request="this.reset()">
								<input type="hidden" name="id" value="<?= $id ?>">
								<textarea class="form-control mb-2" name="body" rows="3" required placeholder="Write a comment"></textarea>
								<div class="form-text mb-2">Markdown: <code>**bold**</code> for <strong>bold</strong>, <code>*italic*</code> for <em>italic</em>, <code>`code`</code> for <code>code</code>, <code>- list</code>, <code>[text](url)</code>, and a bare <code>https://</code> URL links too</div>
								<button class="btn btn-outline-primary">Add comment</button>
							</form>
						<?php else: ?>
							<p class="text-body-secondary small mt-3 mb-0">Archived, so no new comments can be added.</p>
						<?php endif ?>
					</div>
				</div>
			</div>
			<div class="col-lg-4">
				<?php
				// The two pickers, written out rather than wrapped in a panel function. Each form is only
				// a value bag and a swap target: hx-include on the × and on the dialog entries posts the
				// rows already in the form plus one add or remove, and the endpoint answers with the
				// form's contents. Nothing that comes back holds a form or a dialog, so both survive
				// every swap and Bootstrap keeps hold of its modal.
				$assigned = array_map('intval', $p->assignees ?? []);
				$people_options = user_picker_options();
				$board_ids = project_board_ids($id);
				$board_options = board_picker_options();
				?>
				<div class="small text-body-secondary">Creator</div>
				<div class="mb-3"><?php user_link((int)$p->creator_id, $USERS[$p->creator_id] ?? 'Unknown') ?></div>

				<div class="small text-body-secondary mb-1">Assignees</div>
				<?php if ($editable): ?>
				<form id="people-form" class="mb-3">
					<input type="hidden" name="id" value="<?= $id ?>">
					<?php foreach ($assigned as $uid): $u = $people_options[$uid] ?? null; ?>
						<div class="d-flex align-items-center gap-2 border rounded bg-body-tertiary px-2 py-1 mb-1" data-picker-row data-value="<?= $uid ?>">
							<input type="hidden" name="as[]" value="<?= $uid ?>">
						<span class="text-truncate"><?php user_link($uid, $u['label'] ?? 'Unknown') ?></span>
							<button type="button" class="btn-close ms-auto" aria-label="Remove <?= h($u['label'] ?? 'person') ?>"
									hx-patch="/project/assign.php" hx-target="#people-form" hx-swap="innerHTML"
									hx-include="closest form, #picker-people-search" hx-vals='{"remove":<?= $uid ?>}'></button>
						</div>
					<?php endforeach ?>
					<?php if (!$assigned): ?>
						<div class="small text-body-secondary mb-2">Nobody assigned yet.</div>
					<?php endif ?>
					<button type="button" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#picker-people-modal">Add assignee</button>
				</form>

				<div class="modal fade" id="picker-people-modal" tabindex="-1" aria-labelledby="picker-people-title" aria-hidden="true">
					<div class="modal-dialog">
						<div class="modal-content">
							<div class="modal-header">
								<h5 class="modal-title" id="picker-people-title">Add an assignee</h5>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body">
								<?php // The search box filters through htmx, so no JS does it. shown.bs.modal re-runs
								     // it on every open, and that is Bootstrap's own event, so it needs no glue. ?>
								<input type="search" id="picker-people-search" name="q" autocomplete="off"
									   class="form-control mb-2" placeholder="Search people" aria-label="Search people"
									   hx-get="/project/assign.php" hx-target="#picker-people-options" hx-swap="innerHTML"
									   hx-trigger="input changed delay:250ms, search, shown.bs.modal from:#picker-people-modal"
									   hx-vals='{"id":<?= $id ?>,"mode":"options"}'>
								<div class="picker-list" id="picker-people-options">
									<?php foreach ($people_options as $oid => $o): ?>
										<button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$oid, $assigned, true) ? ' d-none' : '' ?>"
												data-picker-option data-value="<?= (int)$oid ?>"
												hx-patch="/project/assign.php" hx-target="#people-form" hx-swap="innerHTML"
												hx-include="#people-form, #picker-people-search" hx-vals='{"add":<?= (int)$oid ?>}'>
											<span class="text-truncate"><?= h($o['label']) ?></span>
											<?php if (!empty($o['sub'])): ?>
												<span class="small text-body-secondary text-truncate"><?= h($o['sub']) ?></span>
											<?php endif ?>
										</button>
									<?php endforeach ?>
									<?php if (!array_diff_key($people_options, array_flip($assigned))): ?>
										<div class="small text-body-secondary">No matches.</div>
									<?php endif ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php else: ?>
					<?php // Archived: the assignees are read, not edited. ?>
					<?php if (!$assigned): ?>
						<p class="text-body-secondary small mb-3">Nobody assigned.</p>
					<?php else: ?>
						<ul class="list-unstyled mb-3">
							<?php foreach ($assigned as $uid): ?>
								<li><?php user_link($uid, $people_options[$uid]['label'] ?? 'Unknown') ?></li>
							<?php endforeach ?>
						</ul>
					<?php endif ?>
				<?php endif ?>

				<div class="small text-body-secondary mb-1">Boards</div>
				<?php if ($editable): ?>
				<form id="boards-form" class="mb-3">
					<input type="hidden" name="id" value="<?= $id ?>">
					<?php foreach ($board_ids as $bid): $b = $board_options[$bid] ?? null; ?>
						<div class="d-flex align-items-center gap-2 border rounded bg-body-tertiary px-2 py-1 mb-1" data-picker-row data-value="<?= $bid ?>">
							<input type="hidden" name="boards[]" value="<?= $bid ?>">
							<span class="text-truncate"><a class="link-quiet" href="/board/?id=<?= $bid ?>"><?= h($b['label'] ?? 'Unknown') ?></a></span>
							<button type="button" class="btn-close ms-auto" aria-label="Remove <?= h($b['label'] ?? 'board') ?>"
									hx-patch="/project/project_boards.php" hx-target="#boards-form" hx-swap="innerHTML"
									hx-include="closest form, #picker-boards-search" hx-vals='{"remove":<?= $bid ?>}'></button>
						</div>
					<?php endforeach ?>
					<?php if (!$board_ids): ?>
						<div class="small text-body-secondary mb-2">On no board.</div>
					<?php endif ?>
					<button type="button" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#picker-boards-modal">Add board</button>
				</form>

				<div class="modal fade" id="picker-boards-modal" tabindex="-1" aria-labelledby="picker-boards-title" aria-hidden="true">
					<div class="modal-dialog">
						<div class="modal-content">
							<div class="modal-header">
								<h5 class="modal-title" id="picker-boards-title">Add a board</h5>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body">
								<?php // The search box filters through htmx, so no JS does it. shown.bs.modal re-runs
								     // it on every open, and that is Bootstrap's own event, so it needs no glue. ?>
								<input type="search" id="picker-boards-search" name="q" autocomplete="off"
									   class="form-control mb-2" placeholder="Search boards" aria-label="Search boards"
									   hx-get="/project/project_boards.php" hx-target="#picker-boards-options" hx-swap="innerHTML"
									   hx-trigger="input changed delay:250ms, search, shown.bs.modal from:#picker-boards-modal"
									   hx-vals='{"id":<?= $id ?>,"mode":"options"}'>
								<div class="picker-list" id="picker-boards-options">
									<?php foreach ($board_options as $oid => $o): ?>
										<button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$oid, $board_ids, true) ? ' d-none' : '' ?>"
												data-picker-option data-value="<?= (int)$oid ?>"
												hx-patch="/project/project_boards.php" hx-target="#boards-form" hx-swap="innerHTML"
												hx-include="#boards-form, #picker-boards-search" hx-vals='{"add":<?= (int)$oid ?>}'>
											<span class="text-truncate"><?= h($o['label']) ?></span>
											<?php if (!empty($o['sub'])): ?>
												<span class="small text-body-secondary text-truncate"><?= h($o['sub']) ?></span>
											<?php endif ?>
										</button>
									<?php endforeach ?>
									<?php if (!array_diff_key($board_options, array_flip($board_ids))): ?>
										<div class="small text-body-secondary">No matches.</div>
									<?php endif ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php else: ?>
					<?php // Archived: the boards are read, not edited. ?>
					<?php if (!$board_ids): ?>
						<p class="text-body-secondary small mb-3">On no board.</p>
					<?php else: ?>
						<ul class="list-unstyled mb-3">
							<?php foreach ($board_ids as $bid): ?>
								<li><a class="link-quiet" href="/board/?id=<?= $bid ?>"><?= h($board_options[$bid]['label'] ?? 'Unknown') ?></a></li>
							<?php endforeach ?>
						</ul>
					<?php endif ?>
				<?php endif ?>

				<div class="card mb-3">
					<div class="card-header fw-semibold">Status history</div>
					<?php status_history_list($id) ?>
				</div>
				<?php if (has_role($me, Role::Admin)): ?>
					<?php if ($p->archived): ?>
						<?php // One-way: there is no way back, so the only honest thing the page can
						      // offer is the fact. The badge above says what happened; this says it stays. ?>
						<p class="text-body-secondary small mb-0">This project is archived and cannot be restored. Its history remains on this page.</p>
					<?php else: ?>
						<?php // The only way a project leaves the screens is to retire it for good, so the
						      // confirmation has to say that plainly before it happens. ?>
						<form method="post" action="/project/archive.php"
							  onsubmit="return confirm('Archive this project? It leaves every board and list, and this cannot be undone.')">
							<input type="hidden" name="id" value="<?= $id ?>">
							<button class="btn btn-outline-secondary">Archive project</button>
						</form>
					<?php endif ?>
				<?php endif ?>
			</div>
		</div>
	</div>
<?php
page_end();
