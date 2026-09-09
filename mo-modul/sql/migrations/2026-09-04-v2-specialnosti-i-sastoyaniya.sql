-- =====================================================================
--  Миграция v2 – изпълнява се ВЪРХУ вече работеща база.
--  При нова инсталация не е нужна: 01_module_schema.sql вече я съдържа.
--  ПРЕДИ ИЗПЪЛНЕНИЕ: mysqldump на базата.
--  2026-09-04
-- =====================================================================
SET NAMES utf8mb4;

-- 1. Специалности и професии --------------------------------------------
CREATE TABLE IF NOT EXISTS mo_specialties (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(200) NOT NULL UNIQUE,
  profession VARCHAR(200) NULL,
  code       VARCHAR(32)  NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Паралелката получава специалност -----------------------------------
ALTER TABLE mo_classes
  ADD COLUMN specialty_id INT NULL AFTER letter,
  ADD CONSTRAINT fk_mcl_spec FOREIGN KEY (specialty_id)
      REFERENCES mo_specialties(id) ON DELETE SET NULL;

-- 3. Компетентностите се водят по предмет + клас + специалност -----------
--    NULL = важи за всички специалности (общообразователните предмети)
ALTER TABLE mo_competencies
  ADD COLUMN specialty_id INT NULL AFTER grade_level,
  ADD CONSTRAINT fk_mc_spec FOREIGN KEY (specialty_id)
      REFERENCES mo_specialties(id) ON DELETE SET NULL;

-- Новият индекс се създава ПРЕДИ да се махне старият: външният ключ
-- към mo_subjects се нуждае от индекс през цялото време.
ALTER TABLE mo_competencies ADD INDEX idx_mc2 (subject_id, grade_level, specialty_id, is_active);
ALTER TABLE mo_competencies DROP INDEX idx_mc;

-- 4. Ново състояние на отметката: частично усвоена -----------------------
ALTER TABLE mo_entry_competencies
  MODIFY COLUMN state ENUM('mastered','partial','not_mastered') NOT NULL;

-- 5. „Бележки“ стават „Мерки за подобряване на качеството“ ---------------
ALTER TABLE mo_entries CHANGE COLUMN note measures TEXT NULL;

-- 6. Полета за обобщението на методиста ---------------------------------
ALTER TABLE mo_summaries
  ADD COLUMN summary_text TEXT NULL AFTER title,
  ADD COLUMN notes TEXT NULL AFTER other;

-- 7. Запазени документи на методиста ------------------------------------
CREATE TABLE IF NOT EXISTS mo_documents (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  summary_id   INT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  year_id      INT NOT NULL,
  term         ENUM('I','II') NOT NULL,
  title        VARCHAR(250) NOT NULL,
  filename     VARCHAR(255) NOT NULL,
  html_snapshot MEDIUMTEXT NULL,   -- за преглед и печат в PDF
  size_bytes   INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_md_sum  FOREIGN KEY (summary_id) REFERENCES mo_summaries(id) ON DELETE SET NULL,
  CONSTRAINT fk_md_user FOREIGN KEY (user_id)    REFERENCES users(id)        ON DELETE CASCADE,
  CONSTRAINT fk_md_year FOREIGN KEY (year_id)    REFERENCES mo_years(id)     ON DELETE CASCADE,
  INDEX idx_md (user_id, year_id, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Изгледът се пренаписва с новите колони ------------------------------
CREATE OR REPLACE VIEW mo_v_entries AS
SELECT e.*,
       TRIM(CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.last_name, u.surname, ''))) AS teacher_name,
       u.email AS teacher_email,
       c.name AS class_name, c.grade_level, c.specialty_id,
       sp.name AS specialty_name,
       s.name AS subject_name,
       y.label AS year_label,
       (SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id = e.id AND x.state='mastered')     AS c_mastered,
       (SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id = e.id AND x.state='partial')      AS c_partial,
       (SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id = e.id AND x.state='not_mastered') AS c_failed,
       (SELECT COUNT(*) FROM mo_competencies k
         WHERE k.subject_id = e.subject_id AND k.grade_level = c.grade_level AND k.is_active = 1
           AND (k.specialty_id IS NULL OR k.specialty_id <=> c.specialty_id))                            AS c_total
FROM mo_entries e
JOIN users       u ON u.id = e.user_id
JOIN mo_classes  c ON c.id = e.class_id
LEFT JOIN mo_specialties sp ON sp.id = c.specialty_id
JOIN mo_subjects s ON s.id = e.subject_id
JOIN mo_years    y ON y.id = e.year_id;

-- 9. Специалности и професии на гимназията -------------------------------
INSERT IGNORE INTO mo_specialties (name, profession) VALUES
('Телекомуникационни системи',            'Комуникационни и компютърни мрежи'),
('Оптически комуникационни системи',      'Комуникационни и компютърни мрежи'),
('Системно програмиране',                 'Разработка на софтуер'),
('Приложно програмиране',                 'Разработка на софтуер'),
('Компютърни мрежи',                      'Комуникационни и компютърни мрежи'),
('Компютърна техника и технологии',       'Компютърни системи и технологии'),
('Икономическа информатика',              'Икономическа информатика'),
('Електронна търговия',                   'Електронна търговия, маркетинг и реклама');
