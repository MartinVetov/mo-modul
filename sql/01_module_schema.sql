-- =====================================================================
-- МОДУЛ „Анализи на МО“ – оптимизирана схема
-- Изпълнява се В БАЗАТА НА ВИС. Съществуващата таблица `users` не се променя.
-- Маршрут: общообразователен предмет -> общообразователно МО; професионален предмет -> професия/специалност на класа -> професионално МО -> председател.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mo_user_roles (
  user_id     BIGINT UNSIGNED NOT NULL,
  role        ENUM('teacher','methodist','deputy','admin') NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role),
  CONSTRAINT fk_mur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_years (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(20) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_programs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('profession','specialty') NOT NULL,
  name VARCHAR(200) NOT NULL,
  code VARCHAR(32) NULL,
  note VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_mo_prog (kind,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL UNIQUE,
  department_type ENUM('general','professional') NOT NULL DEFAULT 'general',
  chair_id BIGINT UNSIGNED NULL,
  deputy_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dep_chair FOREIGN KEY (chair_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_dep_deputy FOREIGN KEY (deputy_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_dep_heads CHECK (chair_id IS NULL OR deputy_id IS NULL OR chair_id <> deputy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL UNIQUE,
  department_id INT NULL COMMENT 'Legacy: общообразователното МО на предмета, когато е зададено.',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_sub_dep FOREIGN KEY (department_id) REFERENCES mo_departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(10) NOT NULL,
  grade_level TINYINT NOT NULL,
  letter VARCHAR(4) NOT NULL,
  year_id INT NOT NULL,
  program_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_mo_class (name,year_id),
  KEY fk_mcl_year (year_id),
  KEY fk_mcl_prog (program_id),
  CONSTRAINT fk_mcl_year FOREIGN KEY (year_id) REFERENCES mo_years(id) ON DELETE CASCADE,
  CONSTRAINT fk_mcl_prog FOREIGN KEY (program_id) REFERENCES mo_programs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кои професии/специалности организационно се обслужват от кое МО.
-- Една програма може да има само едно отговорно МО.
CREATE TABLE IF NOT EXISTS mo_department_programs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT NOT NULL,
  program_id INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mdp_program (program_id),
  KEY idx_mdp_department (department_id,is_active),
  CONSTRAINT fk_mdp_department FOREIGN KEY (department_id) REFERENCES mo_departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_mdp_program FOREIGN KEY (program_id) REFERENCES mo_programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Общообразователен предмет -> МО. Приложението допуска едно активно МО на предмет.
-- Професионалните предмети НЕ използват тази таблица за маршрутизиране.
CREATE TABLE IF NOT EXISTS mo_subject_departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL,
  department_id INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_msd_subject_department (subject_id,department_id),
  KEY idx_msd_department (department_id,is_active),
  CONSTRAINT fk_msd_subject FOREIGN KEY (subject_id) REFERENCES mo_subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_msd_department FOREIGN KEY (department_id) REFERENCES mo_departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- РПП предмети: самият предмет остава в mo_subjects, а тук се пази
-- само специалният му тип и приложимостта по клас и програма.
CREATE TABLE IF NOT EXISTS mo_rpp_subjects (
  subject_id INT NOT NULL PRIMARY KEY,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rpp_subject FOREIGN KEY (subject_id) REFERENCES mo_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_rpp_subject_grades (
  subject_id INT NOT NULL,
  grade_level TINYINT NOT NULL,
  PRIMARY KEY (subject_id,grade_level),
  CONSTRAINT fk_rpp_grade_subject FOREIGN KEY (subject_id) REFERENCES mo_rpp_subjects(subject_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_rpp_subject_programs (
  subject_id INT NOT NULL,
  program_id INT NOT NULL,
  PRIMARY KEY (subject_id,program_id),
  KEY idx_rpp_program (program_id),
  CONSTRAINT fk_rpp_program_subject FOREIGN KEY (subject_id) REFERENCES mo_rpp_subjects(subject_id) ON DELETE CASCADE,
  CONSTRAINT fk_rpp_program FOREIGN KEY (program_id) REFERENCES mo_programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_competencies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL,
  grade_level TINYINT NOT NULL,
  program_id INT NULL,
  section VARCHAR(120) NULL,
  code VARCHAR(40) NULL,
  title VARCHAR(600) NOT NULL,
  source VARCHAR(200) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mc2 (subject_id,grade_level,is_active),
  KEY idx_mc_section (subject_id,grade_level,section),
  KEY fk_mc_prog (program_id),
  CONSTRAINT fk_mc_sub FOREIGN KEY (subject_id) REFERENCES mo_subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_mc_prog FOREIGN KEY (program_id) REFERENCES mo_programs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_subject_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alias VARCHAR(200) NOT NULL UNIQUE,
  subject_name VARCHAR(200) NOT NULL,
  section VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  year_id INT NOT NULL,
  term ENUM('I','II') NOT NULL DEFAULT 'I',
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  group_no ENUM('0','1','2') NOT NULL DEFAULT '0',
  students_count SMALLINT NOT NULL DEFAULT 0,
  g2 SMALLINT NOT NULL DEFAULT 0,
  g3 SMALLINT NOT NULL DEFAULT 0,
  g4 SMALLINT NOT NULL DEFAULT 0,
  g5 SMALLINT NOT NULL DEFAULT 0,
  g6 SMALLINT NOT NULL DEFAULT 0,
  total_grades SMALLINT GENERATED ALWAYS AS (g2+g3+g4+g5+g6) STORED,
  avg_grade DECIMAL(4,3) GENERATED ALWAYS AS (
    CASE WHEN (g2+g3+g4+g5+g6)=0 THEN NULL
         ELSE (g2*2+g3*3+g4*4+g5*5+g6*6)/(g2+g3+g4+g5+g6) END
  ) STORED,
  measures TEXT NULL,
  status ENUM('draft','sent') NOT NULL DEFAULT 'draft',
  methodist_id BIGINT UNSIGNED NULL COMMENT 'Конкретният председател/получател към момента на изпращането',
  department_id INT NULL COMMENT 'МО, към което организационно принадлежи анализът',
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mo_entry (user_id,year_id,term,class_id,subject_id,group_no),
  KEY fk_me_y (year_id),
  KEY fk_me_c (class_id),
  KEY fk_me_s (subject_id),
  KEY idx_me_box (methodist_id,status,year_id,term),
  KEY idx_ent_dep (department_id,status,year_id,term),
  CONSTRAINT fk_me_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_me_y FOREIGN KEY (year_id) REFERENCES mo_years(id) ON DELETE CASCADE,
  CONSTRAINT fk_me_c FOREIGN KEY (class_id) REFERENCES mo_classes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_me_s FOREIGN KEY (subject_id) REFERENCES mo_subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_me_m FOREIGN KEY (methodist_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ent_dep FOREIGN KEY (department_id) REFERENCES mo_departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_entry_competencies (
  entry_id INT NOT NULL,
  competency_id INT NOT NULL,
  state ENUM('mastered','partial','not_mastered') NOT NULL,
  PRIMARY KEY (entry_id,competency_id),
  CONSTRAINT fk_mec_e FOREIGN KEY (entry_id) REFERENCES mo_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_mec_c FOREIGN KEY (competency_id) REFERENCES mo_competencies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ръчно въведени компетентности за конкретен РПП анализ.
-- Не се превръщат в глобални компетентности и не влияят на други анализи.
CREATE TABLE IF NOT EXISTS mo_entry_manual_competencies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_id INT NOT NULL,
  title VARCHAR(600) NOT NULL,
  state ENUM('mastered','partial','not_mastered') NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_memc_entry (entry_id,sort_order,id),
  CONSTRAINT fk_memc_entry FOREIGN KEY (entry_id) REFERENCES mo_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_summaries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  methodist_id BIGINT UNSIGNED NULL,
  department_id INT NULL,
  year_id INT NOT NULL,
  term ENUM('I','II') NOT NULL,
  title VARCHAR(200) NULL,
  summary_text TEXT NULL,
  strengths TEXT NULL,
  improvements TEXT NULL,
  measures TEXT NULL,
  other TEXT NULL,
  notes TEXT NULL,
  ai_draft MEDIUMTEXT NULL,
  ai_generated_at DATETIME NULL,
  status ENUM('draft','sent') NOT NULL DEFAULT 'draft',
  deputy_id BIGINT UNSIGNED NULL,
  finalized_by BIGINT UNSIGNED NULL,
  sent_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mo_sum_dep (department_id,year_id,term),
  KEY fk_ms_y (year_id),
  KEY fk_ms_d (deputy_id),
  KEY fk_sum_fin (finalized_by),
  KEY idx_sum_meth (methodist_id),
  CONSTRAINT fk_ms_m FOREIGN KEY (methodist_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sum_dep FOREIGN KEY (department_id) REFERENCES mo_departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_sum_fin FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ms_y FOREIGN KEY (year_id) REFERENCES mo_years(id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_d FOREIGN KEY (deputy_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_summary_entries (
  summary_id INT NOT NULL,
  entry_id INT NOT NULL,
  PRIMARY KEY (summary_id,entry_id),
  CONSTRAINT fk_mse_s FOREIGN KEY (summary_id) REFERENCES mo_summaries(id) ON DELETE CASCADE,
  CONSTRAINT fk_mse_e FOREIGN KEY (entry_id) REFERENCES mo_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  summary_id INT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  year_id INT NOT NULL,
  term ENUM('I','II') NOT NULL,
  title VARCHAR(250) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  html_snapshot MEDIUMTEXT NULL,
  size_bytes INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY fk_md_sum (summary_id),
  KEY fk_md_year (year_id),
  KEY idx_md (user_id,year_id,term),
  CONSTRAINT fk_md_sum FOREIGN KEY (summary_id) REFERENCES mo_summaries(id) ON DELETE SET NULL,
  CONSTRAINT fk_md_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_md_year FOREIGN KEY (year_id) REFERENCES mo_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mo_import_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  filename VARCHAR(255) NULL,
  rows_ok INT NOT NULL DEFAULT 0,
  rows_error INT NOT NULL DEFAULT 0,
  details MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW mo_v_entries AS
SELECT e.*,
       TRIM(CONCAT(COALESCE(u.name,''),' ',COALESCE(u.last_name,u.surname,''))) AS teacher_name,
       u.email AS teacher_email,
       c.name AS class_name,c.grade_level,c.program_id,
       p.name AS program_name,p.kind AS program_kind,
       s.name AS subject_name,s.department_id AS subject_department_id,
       d.name AS department_name,
       y.label AS year_label,
       ((SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id=e.id AND x.state='mastered') +
        (SELECT COUNT(*) FROM mo_entry_manual_competencies x WHERE x.entry_id=e.id AND x.state='mastered')) AS c_mastered,
       ((SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id=e.id AND x.state='partial') +
        (SELECT COUNT(*) FROM mo_entry_manual_competencies x WHERE x.entry_id=e.id AND x.state='partial')) AS c_partial,
       ((SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.entry_id=e.id AND x.state='not_mastered') +
        (SELECT COUNT(*) FROM mo_entry_manual_competencies x WHERE x.entry_id=e.id AND x.state='not_mastered')) AS c_failed,
       CASE WHEN EXISTS (SELECT 1 FROM mo_rpp_subjects rs WHERE rs.subject_id=e.subject_id)
            THEN (SELECT COUNT(*) FROM mo_entry_manual_competencies x WHERE x.entry_id=e.id)
            ELSE (SELECT COUNT(*) FROM mo_competencies k
                   WHERE k.subject_id=e.subject_id AND k.grade_level=c.grade_level AND k.is_active=1
                     AND (k.program_id IS NULL OR k.program_id <=> c.program_id))
       END AS c_total
FROM mo_entries e
JOIN users u ON u.id=e.user_id
JOIN mo_classes c ON c.id=e.class_id
LEFT JOIN mo_programs p ON p.id=c.program_id
JOIN mo_subjects s ON s.id=e.subject_id
LEFT JOIN mo_departments d ON d.id=e.department_id
JOIN mo_years y ON y.id=e.year_id;

-- Производната роля "methodist" се синхронизира с ръководството на МО.
DELETE FROM mo_user_roles WHERE role='methodist';
INSERT IGNORE INTO mo_user_roles (user_id,role,assigned_by)
SELECT x.user_id,'methodist',NULL
FROM (
  SELECT chair_id user_id FROM mo_departments WHERE is_active=1 AND chair_id IS NOT NULL
  UNION
  SELECT deputy_id user_id FROM mo_departments WHERE is_active=1 AND deputy_id IS NOT NULL
) x;
