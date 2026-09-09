-- =====================================================================
--  Миграция v5 – методически обединения вместо назначаване на методист
--
--  Промяната накратко: предметът принадлежи на МО, а МО има председател
--  и заместник. Анализът тръгва към МО-то на предмета, тоест при смяна
--  на председателя нищо друго не се пипа.
--
--  ПРЕДИ ИЗПЪЛНЕНИЕ: mysqldump на базата!
--  2026-09-18
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Методически обединения
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_departments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(200) NOT NULL UNIQUE,
  chair_id   BIGINT UNSIGNED NULL,          -- председател
  deputy_id  BIGINT UNSIGNED NULL,          -- заместник-председател
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dep_chair  FOREIGN KEY (chair_id)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_dep_deputy FOREIGN KEY (deputy_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Предметът принадлежи на МО
-- ---------------------------------------------------------------------
ALTER TABLE mo_subjects
  ADD COLUMN department_id INT NULL AFTER name,
  ADD CONSTRAINT fk_sub_dep FOREIGN KEY (department_id)
      REFERENCES mo_departments(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 3. Анализът се води към МО, а не към конкретен човек
-- ---------------------------------------------------------------------
ALTER TABLE mo_entries
  ADD COLUMN department_id INT NULL AFTER methodist_id,
  ADD CONSTRAINT fk_ent_dep FOREIGN KEY (department_id)
      REFERENCES mo_departments(id) ON DELETE SET NULL,
  ADD INDEX idx_ent_dep (department_id, status, year_id, term);

-- ---------------------------------------------------------------------
-- 4. Обобщението също е на МО; финализира го председателят или замeстникът
-- ---------------------------------------------------------------------
ALTER TABLE mo_summaries
  ADD COLUMN department_id INT NULL AFTER methodist_id,
  ADD COLUMN finalized_by  BIGINT UNSIGNED NULL AFTER deputy_id,
  ADD CONSTRAINT fk_sum_dep FOREIGN KEY (department_id)
      REFERENCES mo_departments(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_sum_fin FOREIGN KEY (finalized_by)
      REFERENCES users(id) ON DELETE SET NULL;

-- Старият уникален ключ беше по методист. Той обаче обслужва външния
-- ключ към users, затова първо пада ключът, после индексът, и накрая
-- връзката се възстановява.
SET @fk := (SELECT constraint_name FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = 'mo_summaries'
               AND column_name = 'methodist_id' AND referenced_table_name = 'users' LIMIT 1);
SET @sql := IF(@fk IS NULL, 'DO 0', CONCAT('ALTER TABLE mo_summaries DROP FOREIGN KEY `', @fk, '`'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS
                      WHERE table_schema = DATABASE() AND table_name = 'mo_summaries'
                        AND index_name = 'uq_mo_sum'),
               'ALTER TABLE mo_summaries DROP INDEX uq_mo_sum', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE mo_summaries MODIFY COLUMN methodist_id BIGINT UNSIGNED NULL;
ALTER TABLE mo_summaries ADD UNIQUE KEY uq_mo_sum_dep (department_id, year_id, term);
ALTER TABLE mo_summaries ADD INDEX idx_sum_meth (methodist_id);
ALTER TABLE mo_summaries
  ADD CONSTRAINT fk_ms_m FOREIGN KEY (methodist_id) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 5. Пренасяне на съществуващите данни
--    Всеки досегашен методист става председател на ново МО с неговото име,
--    а предметите, по които са идвали анализи при него, отиват в това МО.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_departments (name, chair_id)
SELECT CONCAT('МО на ', TRIM(CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.last_name, u.surname, '')))), u.id
FROM mo_user_roles r
JOIN users u ON u.id = r.user_id
WHERE r.role = 'methodist';

-- ако няма нито един методист, правим едно общо МО, за да не остане празно
INSERT IGNORE INTO mo_departments (name)
SELECT 'Методическо обединение'
WHERE NOT EXISTS (SELECT 1 FROM mo_departments);

-- предметите се разпределят по МО-то на методиста, който ги е получавал
UPDATE mo_subjects s
  JOIN (SELECT e.subject_id, e.methodist_id, COUNT(*) n
          FROM mo_entries e WHERE e.methodist_id IS NOT NULL
          GROUP BY e.subject_id, e.methodist_id) x ON x.subject_id = s.id
  JOIN mo_departments d ON d.chair_id = x.methodist_id
  SET s.department_id = d.id
  WHERE s.department_id IS NULL;

-- останалите предмети отиват в първото МО, за да не увисне анализ
UPDATE mo_subjects
  SET department_id = (SELECT id FROM (SELECT id FROM mo_departments ORDER BY id LIMIT 1) t)
  WHERE department_id IS NULL;

-- анализите получават своето МО
UPDATE mo_entries e JOIN mo_subjects s ON s.id = e.subject_id
  SET e.department_id = s.department_id
  WHERE e.department_id IS NULL;

-- обобщенията също
UPDATE mo_summaries m JOIN mo_departments d ON d.chair_id = m.methodist_id
  SET m.department_id = d.id
  WHERE m.department_id IS NULL;

DELETE FROM mo_summaries WHERE department_id IS NULL;

-- ---------------------------------------------------------------------
-- 6. Разпределението учител → методист вече не се ползва
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS mo_teacher_methodist;

-- ---------------------------------------------------------------------
-- 7. Изгледът с новите връзки
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW mo_v_entries AS
SELECT e.*,
       TRIM(CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.last_name, u.surname, ''))) AS teacher_name,
       u.email AS teacher_email,
       c.name AS class_name, c.grade_level, c.program_id,
       p.name AS program_name, p.kind AS program_kind,
       s.name AS subject_name, s.department_id AS subject_department_id,
       d.name AS department_name,
       y.label AS year_label,
       (SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id = e.id AND x.state='mastered')     AS c_mastered,
       (SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id = e.id AND x.state='partial')      AS c_partial,
       (SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id = e.id AND x.state='not_mastered') AS c_failed,
       (SELECT COUNT(*) FROM mo_competencies k
         WHERE k.subject_id = e.subject_id AND k.grade_level = c.grade_level AND k.is_active = 1
           AND (k.program_id IS NULL OR k.program_id <=> c.program_id))                                  AS c_total
FROM mo_entries e
JOIN users       u ON u.id = e.user_id
JOIN mo_classes  c ON c.id = e.class_id
LEFT JOIN mo_programs p ON p.id = c.program_id
JOIN mo_subjects s ON s.id = e.subject_id
LEFT JOIN mo_departments d ON d.id = e.department_id
JOIN mo_years    y ON y.id = e.year_id;
