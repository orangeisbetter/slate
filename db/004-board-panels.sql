-- 004: per-board panels. A panel is one status column on the board, and a board says how each
-- one behaves: always shown, shown only once something is in it, or never shown.
--
-- Only panels that differ from the default are stored. The default is the three statuses most
-- boards work in — see DEFAULT_BOARD_PANELS — so an absent row is not the same as "never shown",
-- and a board nobody has configured yet behaves the way it always has rather than coming up
-- blank. A hidden panel still gets a row whenever hiding it is not the default, which is the case
-- for those three: for them an absent row means the opposite.
--
-- No rows are inserted here: every board that exists today takes the default, which is the
-- point of having one. Reading one board's panels is keyed on board_id, which the primary key
-- above already covers.
CREATE TABLE board_panels(
  board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
  status   TEXT NOT NULL,
  mode     TEXT NOT NULL CHECK (mode IN ('required','optional','hidden')),
  PRIMARY KEY(board_id, status));
