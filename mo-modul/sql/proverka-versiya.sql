-- =====================================================================
--  ПРОВЕРКА НА ВЕРСИЯТА НА БАЗАТА
--  Изпълнете този файл в phpMyAdmin (раздел SQL) ПРЕДИ всяка миграция.
--  Той нищо не променя – само казва какво ви трябва.
-- =====================================================================
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE table_schema = DATABASE() AND table_name = 'mo_competencies') = 0
      THEN 'Няма модул. Изпълнете 01_module_schema.sql и после 02_module_seed.sql.'

    WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE table_schema = DATABASE() AND table_name = 'mo_competencies'
             AND column_name = 'section') = 1
      THEN 'Базата е НАЙ-НОВАТА (v4). НЕ пускайте никаква миграция.'

    WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE table_schema = DATABASE() AND table_name = 'mo_classes'
             AND column_name = 'program_id') = 1
      THEN 'Базата е v3. Пуснете само: 2026-09-14-v4-podrazdeli-i-syotvetstviya.sql'

    WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE table_schema = DATABASE() AND table_name = 'mo_entries'
             AND column_name = 'measures') = 1
      THEN 'Базата е v2. Пуснете по ред: v3, после v4.'

    ELSE 'Базата е v1. Пуснете по ред: v2, после v3, после v4.'
  END AS `Какво да направите`;

-- Подробности за проверка на око
SELECT 'mo_entries.note (стара)'        AS `елемент`,
       (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE()
         AND table_name='mo_entries' AND column_name='note') AS `има ли го`
UNION ALL SELECT 'mo_entries.measures (v2)',
       (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE()
         AND table_name='mo_entries' AND column_name='measures')
UNION ALL SELECT 'mo_classes.specialty_id (стара)',
       (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE()
         AND table_name='mo_classes' AND column_name='specialty_id')
UNION ALL SELECT 'mo_classes.program_id (v3)',
       (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE()
         AND table_name='mo_classes' AND column_name='program_id')
UNION ALL SELECT 'mo_competencies.section (v4)',
       (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE()
         AND table_name='mo_competencies' AND column_name='section')
UNION ALL SELECT 'таблица mo_specialties (стара)',
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema=DATABASE()
         AND table_name='mo_specialties')
UNION ALL SELECT 'таблица mo_programs (v3)',
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema=DATABASE()
         AND table_name='mo_programs')
UNION ALL SELECT 'таблица mo_subject_aliases (v4)',
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema=DATABASE()
         AND table_name='mo_subject_aliases');
