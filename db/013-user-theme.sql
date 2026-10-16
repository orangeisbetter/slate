-- 013: per-user site theme.
--
-- A theme is a choice, the way users.active is a flag: one column, defaulted, so every existing
-- account keeps the default look and nothing is written back. The slug is checked against THEMES
-- in src/lib.php before it is stored, so a theme the app cannot draw never reaches a row. The
-- palette itself is Bootstrap's data-bs-theme on the <html> element, and any decoration a theme
-- needs beyond a palette is a stylesheet under public/assets/themes/ named after the slug.
ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'default';
