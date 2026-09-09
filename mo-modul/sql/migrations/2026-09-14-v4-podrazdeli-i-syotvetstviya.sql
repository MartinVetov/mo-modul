-- =====================================================================
--  Миграция v4 – подраздели на компетентностите и съответствия
--  между имена на предмети (напр. „Литература“ → БЕЛ, подраздел Литература).
--
--  ПРЕДИ ИЗПЪЛНЕНИЕ: mysqldump на базата!
--  2026-09-14
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Подраздел на компетентността.
--    БЕЛ е един предмет, но учебната програма е разделена на
--    „Български език“ и „Литература“. Подразделът пази това деление,
--    без да се създава втори предмет.
-- ---------------------------------------------------------------------
ALTER TABLE mo_competencies
  ADD COLUMN section VARCHAR(120) NULL AFTER program_id;

ALTER TABLE mo_competencies DROP INDEX idx_mc3;
ALTER TABLE mo_competencies
  ADD INDEX idx_mc4 (subject_id, grade_level, program_id, is_active);

-- ---------------------------------------------------------------------
-- 2. Съответствия: как се нарича предметът във файла и къде да отиде.
--    При импорт „Литература“ се записва в „Български език и литература“
--    с подраздел „Литература“.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_subject_aliases (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  alias        VARCHAR(200) NOT NULL UNIQUE,   -- както пише във файла
  subject_name VARCHAR(200) NOT NULL,          -- към кой предмет отива
  section      VARCHAR(120) NULL,              -- подраздел (може празно)
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mo_subject_aliases (alias, subject_name, section) VALUES
('Литература',                  'Български език и литература', 'Литература'),
('Български език',              'Български език и литература', 'Български език'),
('БЕЛ',                         'Български език и литература', NULL),
('Български език и литература', 'Български език и литература', NULL);

-- ---------------------------------------------------------------------
-- 3. Ако вече е създаден отделен предмет „Литература“, прехвърляме
--    компетентностите му към БЕЛ с подраздел и скриваме излишния предмет.
--    Отметките на учителите се запазват – редовете не се трият.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_subjects (name) VALUES ('Български език и литература');

UPDATE mo_competencies k
  JOIN mo_subjects src ON src.id = k.subject_id AND src.name = 'Литература'
  JOIN mo_subjects dst ON dst.name = 'Български език и литература'
  SET k.subject_id = dst.id, k.section = COALESCE(k.section, 'Литература');

UPDATE mo_competencies k
  JOIN mo_subjects src ON src.id = k.subject_id AND src.name = 'Български език'
  JOIN mo_subjects dst ON dst.name = 'Български език и литература'
  SET k.subject_id = dst.id, k.section = COALESCE(k.section, 'Български език');

-- предметите остават в базата (заради стари анализи), но се скриват
UPDATE mo_subjects SET is_active = 0
  WHERE name IN ('Литература', 'Български език')
    AND NOT EXISTS (SELECT 1 FROM mo_entries e WHERE e.subject_id = mo_subjects.id);
