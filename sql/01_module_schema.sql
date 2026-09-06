-- =====================================================================
--  МОДУЛ „Анализи на МО“ към ВИС (Laravel)
--  Всички таблици са с представка mo_ и се създават В БАЗАТА НА ВИС,
--  за да могат да ползват съществуващата таблица `users`.
--  Таблицата `users` НЕ се променя и НЕ се пипа от модула.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Роли в модула. Ролята в `users` остава непокътната – тук се назначават
-- допълнителните права: методист, зам-директор, администратор на модула.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_user_roles (
  user_id     BIGINT UNSIGNED NOT NULL,
  role        ENUM('teacher','methodist','deputy','admin') NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role),
  CONSTRAINT fk_mur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Учебни години
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_years (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  label     VARCHAR(20) NOT NULL UNIQUE,     -- 2025-2026, както е във ВИС
  is_active TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Общ регистър на професии и специалности.
--   profession – новата класификация на МОН (за випуските от 2026-2027)
--   specialty  – старата, за випуските, тръгнали по нея
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

-- ---------------------------------------------------------------------
-- Паралелки: 8А … 12Ж, ЗА ВСЯКА УЧЕБНА ГОДИНА поотделно.
-- Всяка паралелка е по професия (нова) или по специалност (стара);
-- заради това един и същ предмет има различни компетентности.
-- При нова учебна година паралелките се прехвърлят нагоре: 8А става 9А
-- със същата професия, а за новите осми се въвеждат професии.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_classes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(10) NOT NULL,          -- 8А
  grade_level TINYINT NOT NULL,              -- 8
  letter      VARCHAR(4) NOT NULL,           -- А
  year_id     INT NOT NULL,
  program_id  INT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_mo_class (name, year_id),
  CONSTRAINT fk_mcl_year FOREIGN KEY (year_id)    REFERENCES mo_years(id)    ON DELETE CASCADE,
  CONSTRAINT fk_mcl_prog FOREIGN KEY (program_id) REFERENCES mo_programs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Предмети
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_subjects (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(200) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Компетентности по ДОС/учебна програма: клас + предмет + текст.
-- Точно това зарежда администрацията от Excel (клас · предмет · компетенция).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_competencies (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  subject_id  INT NOT NULL,
  grade_level TINYINT NOT NULL,              -- 8..12
  program_id  INT NULL,                      -- NULL = за всички професии/специалности
  code        VARCHAR(40) NULL,
  title       VARCHAR(600) NOT NULL,
  source      VARCHAR(200) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mc_sub  FOREIGN KEY (subject_id)   REFERENCES mo_subjects(id)    ON DELETE CASCADE,
  CONSTRAINT fk_mc_prog FOREIGN KEY (program_id) REFERENCES mo_programs(id) ON DELETE SET NULL,
  INDEX idx_mc (subject_id, grade_level, program_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Кой методист обобщава кой учител
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_teacher_methodist (
  teacher_id   BIGINT UNSIGNED NOT NULL,
  methodist_id BIGINT UNSIGNED NOT NULL,
  year_id      INT NOT NULL,
  PRIMARY KEY (teacher_id, year_id),
  CONSTRAINT fk_mtm_t FOREIGN KEY (teacher_id)   REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_mtm_m FOREIGN KEY (methodist_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_mtm_y FOREIGN KEY (year_id)      REFERENCES mo_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Анализ на учителя: един ред = клас + предмет + група + срок
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_entries (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  year_id        INT NOT NULL,
  term           ENUM('I','II') NOT NULL DEFAULT 'I',
  class_id       INT NOT NULL,
  subject_id     INT NOT NULL,
  group_no       ENUM('0','1','2') NOT NULL DEFAULT '0',  -- 0 = цяла паралелка
  students_count SMALLINT NOT NULL DEFAULT 0,
  g2 SMALLINT NOT NULL DEFAULT 0,
  g3 SMALLINT NOT NULL DEFAULT 0,
  g4 SMALLINT NOT NULL DEFAULT 0,
  g5 SMALLINT NOT NULL DEFAULT 0,
  g6 SMALLINT NOT NULL DEFAULT 0,
  total_grades SMALLINT     GENERATED ALWAYS AS (g2+g3+g4+g5+g6) STORED,
  avg_grade    DECIMAL(4,3) GENERATED ALWAYS AS (
                 CASE WHEN (g2+g3+g4+g5+g6)=0 THEN NULL
                      ELSE (g2*2+g3*3+g4*4+g5*5+g6*6)/(g2+g3+g4+g5+g6) END) STORED,
  measures     TEXT NULL,                    -- „Мерки за подобряване качеството на обучение“
  status       ENUM('draft','sent') NOT NULL DEFAULT 'draft',
  methodist_id BIGINT UNSIGNED NULL,          -- до кого е изпратен
  sent_at      DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mo_entry (user_id, year_id, term, class_id, subject_id, group_no),
  CONSTRAINT fk_me_u FOREIGN KEY (user_id)    REFERENCES users(id)        ON DELETE CASCADE,
  CONSTRAINT fk_me_y FOREIGN KEY (year_id)    REFERENCES mo_years(id)     ON DELETE CASCADE,
  CONSTRAINT fk_me_c FOREIGN KEY (class_id)   REFERENCES mo_classes(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_me_s FOREIGN KEY (subject_id) REFERENCES mo_subjects(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_me_m FOREIGN KEY (methodist_id) REFERENCES users(id)      ON DELETE SET NULL,
  INDEX idx_me_box (methodist_id, status, year_id, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Отметките.
--   mastered     – зелена отметка (усвоена)
--   partial      – жълта отметка (частично усвоена)
--   not_mastered – червен кръст (неусвоена)
--   НЕОТБЕЛЯЗАНА компетентност изобщо не се записва тук:
--   липсата на ред означава „прехвърля се за втори срок“.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_entry_competencies (
  entry_id      INT NOT NULL,
  competency_id INT NOT NULL,
  state         ENUM('mastered','partial','not_mastered') NOT NULL,
  PRIMARY KEY (entry_id, competency_id),
  CONSTRAINT fk_mec_e FOREIGN KEY (entry_id)      REFERENCES mo_entries(id)      ON DELETE CASCADE,
  CONSTRAINT fk_mec_c FOREIGN KEY (competency_id) REFERENCES mo_competencies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Обобщение на методиста, което се изпраща на зам-директор
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_summaries (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  methodist_id BIGINT UNSIGNED NOT NULL,
  year_id      INT NOT NULL,
  term         ENUM('I','II') NOT NULL,
  title        VARCHAR(200) NULL,
  summary_text TEXT NULL,                    -- общо обобщение на МО
  strengths    TEXT NULL,
  improvements TEXT NULL,
  measures     TEXT NULL,
  other        TEXT NULL,
  notes        TEXT NULL,                    -- бележки на методиста
  ai_draft     MEDIUMTEXT NULL,
  ai_generated_at DATETIME NULL,
  status       ENUM('draft','sent') NOT NULL DEFAULT 'draft',
  deputy_id    BIGINT UNSIGNED NULL,
  sent_at      DATETIME NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mo_sum (methodist_id, year_id, term),
  CONSTRAINT fk_ms_m FOREIGN KEY (methodist_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ms_y FOREIGN KEY (year_id)      REFERENCES mo_years(id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_d FOREIGN KEY (deputy_id)    REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- кои анализи влизат в обобщението
CREATE TABLE IF NOT EXISTS mo_summary_entries (
  summary_id INT NOT NULL,
  entry_id   INT NOT NULL,
  PRIMARY KEY (summary_id, entry_id),
  CONSTRAINT fk_mse_s FOREIGN KEY (summary_id) REFERENCES mo_summaries(id) ON DELETE CASCADE,
  CONSTRAINT fk_mse_e FOREIGN KEY (entry_id)   REFERENCES mo_entries(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Запазени документи (Word) на методиста
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_documents (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  summary_id    INT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  year_id       INT NOT NULL,
  term          ENUM('I','II') NOT NULL,
  title         VARCHAR(250) NOT NULL,
  filename      VARCHAR(255) NOT NULL,
  html_snapshot MEDIUMTEXT NULL,
  size_bytes    INT NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_md_sum  FOREIGN KEY (summary_id) REFERENCES mo_summaries(id) ON DELETE SET NULL,
  CONSTRAINT fk_md_user FOREIGN KEY (user_id)    REFERENCES users(id)        ON DELETE CASCADE,
  CONSTRAINT fk_md_year FOREIGN KEY (year_id)    REFERENCES mo_years(id)     ON DELETE CASCADE,
  INDEX idx_md (user_id, year_id, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Дневник на импортите
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mo_import_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NULL,
  filename   VARCHAR(255) NULL,
  rows_ok    INT NOT NULL DEFAULT 0,
  rows_error INT NOT NULL DEFAULT 0,
  details    MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Изглед за методиста и зам-директора
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
