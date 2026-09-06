-- =====================================================================
--  Миграция v3 – специалности и професии в общ регистър,
--  паралелки по учебна година (за прехвърляне нагоре при нова година).
--
--  ПРЕДИ ИЗПЪЛНЕНИЕ: mysqldump на базата!
--  2026-09-10
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Общ регистър: старите СПЕЦИАЛНОСТИ и новите ПРОФЕСИИ
--    Двете са една и съща по смисъл величина – затова са в една таблица
--    с признак „вид“. Така паралелка може да е по едното или по другото.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_programs (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  kind      ENUM('profession','specialty') NOT NULL,
  name      VARCHAR(200) NOT NULL,
  code      VARCHAR(32) NULL,
  note      VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_mo_prog (kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Новите професии (за випуските от 2026-2027 нататък)
INSERT IGNORE INTO mo_programs (kind, name, note) VALUES
('profession', 'Комуникационни и компютърни мрежи',        'нова класификация на МОН'),
('profession', 'Информационни системи',                    'нова класификация на МОН'),
('profession', 'Разработка на софтуер',                    'нова класификация на МОН'),
('profession', 'Осигуряване на качеството на софтуер',     'нова класификация на МОН'),
('profession', 'Компютърни системи и технологии',          'нова класификация на МОН'),
('profession', 'Електронна търговия, маркетинг и реклама', 'нова класификация на МОН'),
('profession', 'Икономическа информатика',                 'нова класификация на МОН');

-- Старите специалности (важат за випуските, тръгнали по старата уредба)
INSERT IGNORE INTO mo_programs (kind, name, note) VALUES
('specialty', 'Телекомуникационни системи',       'стара класификация'),
('specialty', 'Оптически комуникационни системи', 'стара класификация'),
('specialty', 'Системно програмиране',            'стара класификация'),
('specialty', 'Приложно програмиране',            'стара класификация'),
('specialty', 'Компютърни мрежи',                 'стара класификация'),
('specialty', 'Компютърна техника и технологии',  'стара класификация'),
('specialty', 'Икономическа информатика',         'стара класификация'),
('specialty', 'Електронна търговия',              'стара класификация');

-- ---------------------------------------------------------------------
-- 2. Паралелките стават по учебна година
-- ---------------------------------------------------------------------
ALTER TABLE mo_classes
  ADD COLUMN year_id    INT NULL AFTER letter,
  ADD COLUMN program_id INT NULL AFTER year_id;

-- пренасяме старата специалност към новия регистър
UPDATE mo_classes c
  JOIN mo_specialties s ON s.id = c.specialty_id
  JOIN mo_programs   p ON p.name = s.name AND p.kind = 'specialty'
  SET c.program_id = p.id;

-- всички съществуващи паралелки отиват в активната учебна година
UPDATE mo_classes
  SET year_id = COALESCE(
        (SELECT id FROM (SELECT id FROM mo_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1) x),
        (SELECT id FROM (SELECT id FROM mo_years ORDER BY id LIMIT 1) y))
  WHERE year_id IS NULL;

ALTER TABLE mo_classes MODIFY COLUMN year_id INT NOT NULL;
ALTER TABLE mo_classes DROP INDEX uq_mo_class;
ALTER TABLE mo_classes ADD UNIQUE KEY uq_mo_class (name, year_id);
ALTER TABLE mo_classes
  ADD CONSTRAINT fk_mcl_year FOREIGN KEY (year_id)    REFERENCES mo_years(id)    ON DELETE CASCADE,
  ADD CONSTRAINT fk_mcl_prog FOREIGN KEY (program_id) REFERENCES mo_programs(id) ON DELETE SET NULL;

ALTER TABLE mo_classes DROP FOREIGN KEY fk_mcl_spec;
ALTER TABLE mo_classes DROP COLUMN specialty_id;

-- ---------------------------------------------------------------------
-- 3. Компетентностите също минават към общия регистър
-- ---------------------------------------------------------------------
ALTER TABLE mo_competencies ADD COLUMN program_id INT NULL AFTER grade_level;

UPDATE mo_competencies k
  JOIN mo_specialties s ON s.id = k.specialty_id
  JOIN mo_programs   p ON p.name = s.name AND p.kind = 'specialty'
  SET k.program_id = p.id;

-- новият индекс се създава преди да падне старият (нужен е на външния ключ)
ALTER TABLE mo_competencies ADD INDEX idx_mc3 (subject_id, grade_level, program_id, is_active);
ALTER TABLE mo_competencies DROP FOREIGN KEY fk_mc_spec;

-- Старият индекс се казва idx_mc или idx_mc2 според това как е създадена
-- базата (наготово или през миграция v2). Махаме този, който съществува.
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS
                      WHERE table_schema = DATABASE() AND table_name = 'mo_competencies'
                        AND index_name = 'idx_mc2'),
               'ALTER TABLE mo_competencies DROP INDEX idx_mc2', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS
                      WHERE table_schema = DATABASE() AND table_name = 'mo_competencies'
                        AND index_name = 'idx_mc'),
               'ALTER TABLE mo_competencies DROP INDEX idx_mc', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE mo_competencies DROP COLUMN specialty_id;
ALTER TABLE mo_competencies
  ADD CONSTRAINT fk_mc_prog FOREIGN KEY (program_id) REFERENCES mo_programs(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 4. Старата таблица вече не е нужна – всичко е пренесено
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS mo_specialties;

-- ---------------------------------------------------------------------
-- 5. Изгледът с новите имена
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW mo_v_entries AS
SELECT e.*,
       TRIM(CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.last_name, u.surname, ''))) AS teacher_name,
       u.email AS teacher_email,
       c.name AS class_name, c.grade_level, c.program_id,
       p.name AS program_name, p.kind AS program_kind,
       s.name AS subject_name,
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
JOIN mo_years    y ON y.id = e.year_id;
