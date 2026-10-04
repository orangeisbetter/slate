-- 003: boards. A project can sit on any number of boards, so the link table is
-- project_boards. Membership is independent per project, including for subprojects.

CREATE TABLE boards(
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  description TEXT DEFAULT '',
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL);

CREATE TABLE project_boards(
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
  added_by INTEGER REFERENCES users(id),
  added_at TEXT NOT NULL,
  PRIMARY KEY(project_id, board_id));

-- board.php loads a board by id and then needs its projects, so the reverse direction
-- gets its own index.
CREATE INDEX project_boards_board ON project_boards(board_id);

-- Everything that exists today lands on one default board, so nothing is left off
-- every board by the migration and the board views have something to show.
INSERT INTO boards(name,description,created_at)
  VALUES('General','Projects that are not on a more specific board.',
         strftime('%Y-%m-%dT%H:%M:%S+00:00','now'));

INSERT INTO project_boards(project_id,board_id,added_at)
  SELECT id, (SELECT id FROM boards WHERE name='General'),
         strftime('%Y-%m-%dT%H:%M:%S+00:00','now')
  FROM projects;
