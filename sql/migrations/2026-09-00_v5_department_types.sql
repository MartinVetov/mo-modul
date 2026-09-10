-- =====================================================================
-- MO module v5 – типове методически обединения
--
-- general      -> МО обслужва общообразователни предмети
-- professional -> МО обслужва професии/специалности
--
-- Изпълнете веднъж върху съществуваща v4 база.
-- Не изтрива анализи, компетентности, класове или потребители.
-- =====================================================================

START TRANSACTION;

ALTER TABLE mo_departments
  ADD COLUMN IF NOT EXISTS department_type ENUM('general','professional')
  NOT NULL DEFAULT 'general' AFTER name;

-- Професионалните МО, уточнени за системата.
UPDATE mo_departments
SET department_type='professional'
WHERE name IN (
  'МО "Техника"',
  'МО "Информационна и комуникационна техника"',
  'МО "Бизнес администрация"'
);

-- Ако има други МО, които вече обслужват професия/специалност,
-- класифицираме и тях като професионални.
UPDATE mo_departments d
SET d.department_type='professional'
WHERE EXISTS (
  SELECT 1
  FROM mo_department_programs dp
  WHERE dp.department_id=d.id AND dp.is_active=1
);

-- Общoобразователните МО не трябва да носят професионални програми.
DELETE dp
FROM mo_department_programs dp
JOIN mo_departments d ON d.id=dp.department_id
WHERE d.department_type <> 'professional';

-- Професионалните МО не трябва да имат предметни връзки.
DELETE sd
FROM mo_subject_departments sd
JOIN mo_departments d ON d.id=sd.department_id
WHERE d.department_type <> 'general';

-- В общообразователните МО оставяме само чисто общообразователни предмети.
-- Единственото изключение е „Чужд език по професията“.
DELETE sd
FROM mo_subject_departments sd
JOIN mo_subjects s ON s.id=sd.subject_id
WHERE s.name <> 'Чужд език по професията'
  AND (
    NOT EXISTS (
      SELECT 1 FROM mo_competencies k
      WHERE k.subject_id=s.id AND k.is_active=1 AND k.program_id IS NULL
    )
    OR EXISTS (
      SELECT 1 FROM mo_competencies k
      WHERE k.subject_id=s.id AND k.is_active=1 AND k.program_id IS NOT NULL
    )
  );

-- v4 е могла да премахне предметната връзка на „Чужд език по професията“,
-- защото компетентностите му са програмно-специфични. Ако legacy таблицата
-- от v2 все още съществува, възстановяваме старото общо правило само когато
-- няма вече направена текуща настройка. При чиста v4 инсталация тази стъпка
-- се пропуска автоматично.
SET @has_legacy_mspd := (
  SELECT COUNT(*)
  FROM information_schema.tables
  WHERE table_schema=DATABASE() AND table_name='mo_subject_program_departments'
);
SET @restore_chep_sql := IF(
  @has_legacy_mspd > 0,
  'INSERT INTO mo_subject_departments (subject_id,department_id,is_active)
   SELECT spd.subject_id, spd.department_id, 1
   FROM mo_subject_program_departments spd
   JOIN mo_subjects s ON s.id=spd.subject_id
   JOIN mo_departments d ON d.id=spd.department_id
   WHERE s.name=''Чужд език по професията''
     AND spd.program_id IS NULL
     AND spd.is_active=1
     AND d.department_type=''general''
     AND NOT EXISTS (SELECT 1 FROM mo_subject_departments x WHERE x.subject_id=spd.subject_id AND x.is_active=1)
   ON DUPLICATE KEY UPDATE is_active=1',
  'SELECT 1'
);
PREPARE restore_chep_stmt FROM @restore_chep_sql;
EXECUTE restore_chep_stmt;
DEALLOCATE PREPARE restore_chep_stmt;

-- Синхронизираме legacy колоната само с едно общообразователно МО.
UPDATE mo_subjects s
LEFT JOIN (
    SELECT sd.subject_id,
           CASE WHEN COUNT(*) = 1 THEN MAX(sd.department_id) ELSE NULL END AS department_id
    FROM mo_subject_departments sd
    JOIN mo_departments d ON d.id=sd.department_id
    WHERE sd.is_active=1 AND d.department_type='general'
    GROUP BY sd.subject_id
) x ON x.subject_id=s.id
SET s.department_id=x.department_id;

COMMIT;
