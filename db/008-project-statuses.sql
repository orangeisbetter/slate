-- 008: project statuses as rows rather than constants in src/lib.php.
--
-- Every status a project can hold is now a row: a slug stored in projects.status and
-- status_log, a label shown wherever the status is named, a tone for its badge, a position
-- in the order work moves through, and whether it counts as finished. An admin creates,
-- renames, reorders and deletes them at /admin/statuses/.
--
-- The slug is the identity and is never renamed: it is what is stored in projects.status,
-- status_log and board_panels, so changing it would orphan every row that points at it.
-- The label is the thing an admin edits.
--
-- Deleting is refused while any project sits on the status, so a slug in projects.status
-- always has a row behind it. status_log is not touched by a delete — it is a record of
-- what things were called at the time, and a status_log entry for a deleted slug still
-- renders, with the slug as its label.
--
-- The nine rows are the ones this app shipped with, in the order PANEL_ORDER used to hold:
-- deliberately not the label order, since review_ready belongs before review. The finished
-- pair are the two LABELS_FINISHED used to name.
CREATE TABLE project_statuses(
  id INTEGER PRIMARY KEY,
  slug TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL,
  tone TEXT NOT NULL DEFAULT 'secondary',
  sort_order INTEGER NOT NULL,
  is_finished INTEGER NOT NULL DEFAULT 0);

INSERT INTO project_statuses(slug, label, tone, sort_order, is_finished) VALUES
  ('draft',        'Draft',           'light',     1, 0),
  ('backlog',      'Backlog',         'info',      2, 0),
  ('todo',         'To do',           'secondary', 3, 0),
  ('blocked',      'Blocked',         'danger',    4, 0),
  ('in_progress',  'In progress',     'primary',   5, 0),
  ('review_ready', 'Ready for review','info',      6, 0),
  ('review',       'In review',       'warning',   7, 0),
  ('done',         'Done',            'success',   8, 1),
  ('cancelled',    'Cancelled',       'dark',      9, 1);
