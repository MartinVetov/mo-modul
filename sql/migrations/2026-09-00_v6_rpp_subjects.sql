-- =====================================================================
-- v6 – РПП предмети без предварително заредени компетентности
-- Изпълнете върху съществуваща v5 база.
-- =====================================================================
SET NAMES utf8mb4;
START TRANSACTION;

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

COMMIT;
