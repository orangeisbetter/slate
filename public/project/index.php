<?php
// One page per project or subproject: /project/?id=N
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$p = project($id);

if (!$p) {
	http_response_code(404);
	page_start('Not found');
	?>
	<div class="page-wide mx-auto p-4">
		<p>Project not found. <a href="index.php">Back to the board</a></p>
	</div>
	<?php
	page_end();
	exit;
}

// Ancestors of this project, outermost first, for the breadcrumb.
$ancestors = [];
$current = $p;
while ($current['parent_id'] && ($current = project($current['parent_id']))) {
	array_unshift($ancestors, $current);
}

$subprojects = q('SELECT id,title,status,type,due_date FROM projects WHERE parent_id=? ORDER BY id', [$id])->fetchAll();
$history = q('SELECT * FROM status_log WHERE project_id=? ORDER BY id', [$id])->fetchAll();

page_start($p['title']);
?>
	<div class="page-wide mx-auto p-3 p-md-4">
		<nav aria-label="breadcrumb">
			<ol class="breadcrumb">
				<li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
				<?php foreach ($ancestors as $ancestor): ?>
					<li class="breadcrumb-item"><a href="project/?id=<?= $ancestor['id'] ?>"><?php ref_badge((int)$ancestor['id']) ?><?= h($ancestor['title']) ?></a></li>
				<?php endforeach ?>
				<li class="breadcrumb-item active"><?= h($p['title']) ?></li>
			</ol>
		</nav>
		<div class="d-flex align-items-center gap-2 mb-2">
			<h1 class="h3 mb-0"><?php ref_badge($id) ?><?= h($p['title']) ?></h1>
			<?php type_badge($p['type']) ?>
		</div>
		<div class="row mb-3">
			<div class="col-auto">
				<select class="form-select" name="status" hx-patch="project/update_status.php" hx-swap="none" hx-vals='{"id": <?= $id ?>}'>
					<?php foreach (LABELS as $status => $label): ?>
						<option value="<?= $status ?>"<?= $status === $p['status'] ? ' selected' : '' ?>><?= $label ?></option>
					<?php endforeach ?>
				</select>
			</div>
		</div>
		<div class="row g-4">
			<div class="col-lg-8">
				<div class="card mb-2">
					<div class="card-body">
						<?php project_form($p, $id, [], 'project/update.php') ?>
					</div>
				</div>
				<p class="text-body-secondary small mb-4">Created <?= dt($p['created_at']) ?>. Last updated <?= dt($p['updated_at']) ?>.</p>
				<div class="card mb-3">
					<div class="card-header fw-semibold">Subprojects</div>
					<div class="list-group list-group-flush">
						<?php if (!$subprojects): ?>
							<div class="list-group-item text-body-secondary small">No subprojects yet.</div>
						<?php endif ?>
						<?php foreach ($subprojects as $sub): ?>
							<a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="project/?id=<?= $sub['id'] ?>">
								<?php type_badge($sub['type']) ?>
								<?php ref_badge((int)$sub['id']) ?>
								<?= h($sub['title']) ?>
								<?php if ($sub['due_date']): ?>
									<small class="text-body-secondary">due <?= h($sub['due_date']) ?></small>
								<?php endif ?>
								<span class="badge text-bg-<?= TONE[$sub['status']] ?> ms-auto"><?= LABELS[$sub['status']] ?></span>
							</a>
						<?php endforeach ?>
					</div>
					<div class="card-body">
						<form class="input-group" method="post" action="project/add.php">
							<input class="form-control" name="title" required placeholder="New subproject title">
							<input type="hidden" name="parent_id" value="<?= $id ?>">
							<button class="btn btn-outline-primary">Add subproject</button>
						</form>
					</div>
				</div>
				<div class="card" id="comments">
					<div class="card-header fw-semibold">Comments</div>
					<div class="card-body">
						<div id="cms">
							<?php render_comments($id) ?>
						</div>
						<form class="mt-3" method="post" action="project/comment.php" hx-post="project/comment.php" hx-target="#cms" hx-on::after-request="this.reset()">
							<input type="hidden" name="id" value="<?= $id ?>">
							<textarea class="form-control mb-2" name="body" rows="3" required placeholder="Write a comment"></textarea>
							<button class="btn btn-outline-primary">Add comment</button>
						</form>
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
				$assigned = array_map('intval', $p['assignees'] ?? []);
				$people_options = user_picker_options();
				$board_ids = project_board_ids($id);
				$board_options = board_picker_options();
				?>
				<div class="small text-body-secondary">Creator</div>
				<div class="mb-3"><?php user_link((int)$p['creator_id'], $USERS[$p['creator_id']] ?? 'Unknown') ?></div>

				<div class="small text-body-secondary mb-1">Assignees</div>
				<form id="people-form" class="mb-3">
					<input type="hidden" name="id" value="<?= $id ?>">
					<?php foreach ($assigned as $uid): $u = $people_options[$uid] ?? null; ?>
						<div class="d-flex align-items-center gap-2 border rounded bg-body-tertiary px-2 py-1 mb-1" data-picker-row data-value="<?= $uid ?>">
							<input type="hidden" name="as[]" value="<?= $uid ?>">
						<span class="text-truncate"><?php user_link($uid, $u['label'] ?? 'Unknown') ?></span>
							<button type="button" class="btn-close ms-auto" aria-label="Remove <?= h($u['label'] ?? 'person') ?>"
									hx-post="project/assign.php" hx-target="#people-form" hx-swap="innerHTML"
									hx-include="closest form, #picker-people-search" hx-vals='{"remove":<?= $uid ?>}'></button>
						</div>
					<?php endforeach ?>
					<?php if (!$assigned): ?>
						<div class="small text-body-secondary mb-2">Nobody assigned yet.</div>
					<?php endif ?>
					<button type="button" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#picker-people-modal">Add assignee</button>
				</form>

				<div class="modal fade" id="picker-people-modal" tabindex="-1" aria-labelledby="picker-people-title" aria-hidden="true">
					<div class="modal-dialog modal-dialog-centered">
						<div class="modal-content">
							<div class="modal-header py-2">
								<h2 class="modal-title fs-5" id="picker-people-title">Add an assignee</h2>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body">
								<?php // The search box filters through htmx, so no JS does it. shown.bs.modal re-runs
								     // it on every open, and that is Bootstrap's own event, so it needs no glue. ?>
								<input type="search" id="picker-people-search" name="q" autocomplete="off"
									   class="form-control mb-2" placeholder="Search people" aria-label="Search people"
									   hx-get="project/assign.php" hx-target="#picker-people-options" hx-swap="innerHTML"
									   hx-trigger="input changed delay:250ms, search, shown.bs.modal from:#picker-people-modal"
									   hx-vals='{"id":<?= $id ?>,"mode":"options"}'>
								<div class="picker-list" id="picker-people-options">
									<?php foreach ($people_options as $oid => $o): ?>
										<button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$oid, $assigned, true) ? ' d-none' : '' ?>"
												data-picker-option data-value="<?= (int)$oid ?>"
												hx-post="project/assign.php" hx-target="#people-form" hx-swap="innerHTML"
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

				<div class="small text-body-secondary mb-1">Boards</div>
				<form id="boards-form" class="mb-3">
					<input type="hidden" name="id" value="<?= $id ?>">
					<?php foreach ($board_ids as $bid): $b = $board_options[$bid] ?? null; ?>
						<div class="d-flex align-items-center gap-2 border rounded bg-body-tertiary px-2 py-1 mb-1" data-picker-row data-value="<?= $bid ?>">
							<input type="hidden" name="boards[]" value="<?= $bid ?>">
							<span class="text-truncate"><a class="link-quiet" href="board/?id=<?= $bid ?>"><?= h($b['label'] ?? 'Unknown') ?></a></span>
							<button type="button" class="btn-close ms-auto" aria-label="Remove <?= h($b['label'] ?? 'board') ?>"
									hx-post="project/project_boards.php" hx-target="#boards-form" hx-swap="innerHTML"
									hx-include="closest form, #picker-boards-search" hx-vals='{"remove":<?= $bid ?>}'></button>
						</div>
					<?php endforeach ?>
					<?php if (!$board_ids): ?>
						<div class="small text-body-secondary mb-2">On no board. Projects off every board still show in the list view.</div>
					<?php endif ?>
					<button type="button" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#picker-boards-modal">Add board</button>
				</form>

				<div class="modal fade" id="picker-boards-modal" tabindex="-1" aria-labelledby="picker-boards-title" aria-hidden="true">
					<div class="modal-dialog modal-dialog-centered">
						<div class="modal-content">
							<div class="modal-header py-2">
								<h2 class="modal-title fs-5" id="picker-boards-title">Add a board</h2>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body">
								<?php // The search box filters through htmx, so no JS does it. shown.bs.modal re-runs
								     // it on every open, and that is Bootstrap's own event, so it needs no glue. ?>
								<input type="search" id="picker-boards-search" name="q" autocomplete="off"
									   class="form-control mb-2" placeholder="Search boards" aria-label="Search boards"
									   hx-get="project/project_boards.php" hx-target="#picker-boards-options" hx-swap="innerHTML"
									   hx-trigger="input changed delay:250ms, search, shown.bs.modal from:#picker-boards-modal"
									   hx-vals='{"id":<?= $id ?>,"mode":"options"}'>
								<div class="picker-list" id="picker-boards-options">
									<?php foreach ($board_options as $oid => $o): ?>
										<button type="button" class="w-100 text-start d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-body<?= in_array((int)$oid, $board_ids, true) ? ' d-none' : '' ?>"
												data-picker-option data-value="<?= (int)$oid ?>"
												hx-post="project/project_boards.php" hx-target="#boards-form" hx-swap="innerHTML"
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

				<div class="card mb-3">
					<div class="card-header fw-semibold">Status history</div>
					<ul class="list-group list-group-flush small">
						<?php foreach ($history as $entry): ?>
							<li class="list-group-item">
								<?= dt($entry['changed_at']) ?>:
								<?= $entry['from_status'] ? LABELS[$entry['from_status']] . ' to ' : 'Created in ' ?><?= LABELS[$entry['to_status']] ?>
							</li>
						<?php endforeach ?>
					</ul>
				</div>
				<?php if (is_admin($me)): ?>
					<form method="post" action="project/delete.php" onsubmit="return confirm('Delete this project, its subprojects, and all their comments?')">
						<input type="hidden" name="id" value="<?= $id ?>">
						<button class="btn btn-outline-danger">Delete project</button>
					</form>
				<?php endif ?>
			</div>
		</div>
	</div>
<?php
page_end();
