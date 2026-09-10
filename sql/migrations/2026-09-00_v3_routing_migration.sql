-- =====================================================================
-- V3: маршрутизиране чрез две независими връзки
--   предмет -> едно/повече МО
--   професия/специалност -> едно отговорно МО
--
-- Изпълнява се ЕДНОКРАТНО върху работещата v2 база.
-- Старите mo_subject_program_departments се запазват като legacy/backup,
-- но новият код вече не ги използва.
-- =====================================================================
SET NAMES utf8mb4;

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

-- 1) Пренасяме всички вече направени предметни назначения.
-- Конкретните стари правила вече не са правила по програма; те просто
-- добавят съответното МО към допустимите МО на предмета.
INSERT INTO mo_subject_departments (subject_id,department_id,is_active)
SELECT DISTINCT subject_id,department_id,1
FROM mo_subject_program_departments
WHERE is_active=1
ON DUPLICATE KEY UPDATE is_active=1;

-- 2) МО „Техника“.
INSERT INTO mo_department_programs (department_id,program_id,is_active)
SELECT d.id,p.id,1 FROM mo_departments d JOIN mo_programs p
WHERE d.name='МО "Техника"'
  AND (
    (p.kind='specialty' AND p.name IN ('Телекомуникационни системи','Оптически комуникационни системи','Компютърни мрежи','Компютърна техника и технологии'))
    OR
    (p.kind='profession' AND p.name IN ('Комуникационни и компютърни мрежи','Компютърни системи и технологии'))
  )
ON DUPLICATE KEY UPDATE department_id=VALUES(department_id),is_active=1;

-- 3) МО „Информационна и комуникационна техника“.
INSERT INTO mo_department_programs (department_id,program_id,is_active)
SELECT d.id,p.id,1 FROM mo_departments d JOIN mo_programs p
WHERE d.name='МО "Информационна и комуникационна техника"'
  AND (
    (p.kind='specialty' AND p.name IN ('Системно програмиране','Приложно програмиране'))
    OR
    (p.kind='profession' AND p.name IN ('Разработка на софтуер','Информационни системи','Осигуряване на качеството на софтуер','Осигуряване на качество на софтуер'))
  )
ON DUPLICATE KEY UPDATE department_id=VALUES(department_id),is_active=1;

-- 4) МО „Бизнес администрация“.
INSERT INTO mo_department_programs (department_id,program_id,is_active)
SELECT d.id,p.id,1 FROM mo_departments d JOIN mo_programs p
WHERE d.name='МО "Бизнес администрация"'
  AND (
    (p.kind='specialty' AND p.name IN ('Икономическа информатика','Електронна търговия'))
    OR
    (p.kind='profession' AND p.name IN ('Икономическа информатика','Електронна търговия, маркетинг и реклама'))
  )
ON DUPLICATE KEY UPDATE department_id=VALUES(department_id),is_active=1;

-- 5) Поддържаме legacy колоната в mo_subjects само когато предметът има
-- точно едно активно МО. При предмети в две или повече МО тя става NULL.
UPDATE mo_subjects s
LEFT JOIN (
  SELECT subject_id,
         CASE WHEN COUNT(*)=1 THEN MAX(department_id) ELSE NULL END AS department_id
  FROM mo_subject_departments
  WHERE is_active=1
  GROUP BY subject_id
) x ON x.subject_id=s.id
SET s.department_id=x.department_id;
