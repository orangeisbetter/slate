<?php
// Standup view: what changed across projects recently — status moves, new comments,
// new projects — newest first. status_log and comments already hold the raw material;
// this page only renders them together. Read-only, newest 100.
const SLATE_REQUIRE_LOGIN = true;
require __DIR__ . '/../../src/bootstrap.php';

// Archived projects are left out of the feed for the same reason they are off the boards: the page
// is a view of what the team is working on, not an audit log. Their own page still carries the
// whole history, which is where a record of a retired project is actually wanted.
$rows = q("SELECT kind, ts, project_id, title, actor_id, from_status, to_status, body FROM (
             SELECT 'project' AS kind, p.created_at AS ts, p.id AS project_id, p.title AS title,
                    p.creator_id AS actor_id, NULL AS from_status, NULL AS to_status, NULL AS body,
                    0 AS sid, 0 AS cid
             FROM projects p
             WHERE p.archived=0
             UNION ALL
             SELECT 'status' AS kind, s.changed_at AS ts, s.project_id AS project_id, p.title AS title,
                    s.changed_by AS actor_id, s.from_status AS from_status, s.to_status AS to_status, NULL AS body,
                    s.id AS sid, 0 AS cid
             FROM status_log s JOIN projects p ON p.id = s.project_id
             WHERE s.from_status IS NOT NULL AND p.archived=0
             UNION ALL
             SELECT 'comment' AS kind, c.created_at AS ts, c.project_id AS project_id, p.title AS title,
                    c.author_id AS actor_id, NULL AS from_status, NULL AS to_status,
                    substr(c.body, 1, 200) AS body,
                    0 AS sid, c.id AS cid
             FROM comments c JOIN projects p ON p.id = c.project_id
             WHERE p.archived=0
           ) ORDER BY ts DESC, sid DESC, cid DESC, project_id DESC LIMIT 100")->fetchAll();

page_start('Recent activity');
?>
<div class="page-wide mx-auto p-3 p-md-4">
    <h1 class="h4 mb-3">Recent activity</h1>
    <?php if (!$rows): ?>
        <p class="text-body-secondary">Nothing yet.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($rows as $row):
                $pid = (int)$row['project_id'];
                $actor = $row['actor_id'] !== null ? (int)$row['actor_id'] : null;
            ?>
                <li class="list-group-item small">
                    <span class="text-body-secondary"><?= dt($row['ts']) ?></span>
                    <?php if ($row['kind'] === 'project'): ?>
                        <?php if ($actor !== null): ?><?php user_link($actor, $USERS[$actor] ?? 'Unknown') ?><?php else: ?>Someone<?php endif ?>
                        created <a href="/project/?id=<?= $pid ?>"><?php ref_badge($pid) ?><?= h($row['title']) ?></a>
                    <?php elseif ($row['kind'] === 'status'): ?>
                        <?php if ($actor !== null): ?><?php user_link($actor, $USERS[$actor] ?? 'Unknown') ?><?php else: ?>Someone<?php endif ?>
                        moved <a href="/project/?id=<?= $pid ?>"><?php ref_badge($pid) ?><?= h($row['title']) ?></a>
                        from <?= h(status_label($row['from_status'])) ?> to <?= h(status_label($row['to_status'])) ?>
                    <?php else: ?>
                        <?php if ($actor !== null): ?><?php user_link($actor, $USERS[$actor] ?? 'Unknown') ?><?php else: ?>Someone<?php endif ?>
                        commented on <a href="/project/?id=<?= $pid ?>#comments"><?php ref_badge($pid) ?><?= h($row['title']) ?></a>:
                        <span><?= h($row['body'] ?? '') ?></span>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>
<?php
page_end();
