-- =====================================================================
--  ПОЧИСТВАНЕ след погрешно пусната стара миграция
--
--  Изпълнете САМО ако сте стартирали 2026-09-04-v2 (или v3) върху база,
--  която вече е с новата структура. Тогава миграцията е спряла по средата
--  и е оставила излишни неща: колона mo_classes.specialty_id и празна
--  таблица mo_specialties.
--
--  Файлът е безопасен: ако няма какво да чисти, не прави нищо.
--  Не пипа компетентности, анализи и отметки.
--  2026-09-16
-- =====================================================================
SET NAMES utf8mb4;

-- 1. Излишният външен ключ към mo_specialties -------------------------
SET @fk := (SELECT constraint_name FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = 'mo_classes'
               AND column_name = 'specialty_id' AND referenced_table_name IS NOT NULL
             LIMIT 1);
SET @sql := IF(@fk IS NULL, 'DO 0',
               CONCAT('ALTER TABLE mo_classes DROP FOREIGN KEY `', @fk, '`'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. Излишната колона – само ако вече има program_id (тоест базата е нова)
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE()
     AND table_name = 'mo_classes' AND column_name = 'specialty_id') = 1
  AND
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE()
     AND table_name = 'mo_classes' AND column_name = 'program_id') = 1,
  'ALTER TABLE mo_classes DROP COLUMN specialty_id', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. Същото за компетентностите, ако v2 е стигнала дотам ---------------
SET @fk := (SELECT constraint_name FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = 'mo_competencies'
               AND column_name = 'specialty_id' AND referenced_table_name IS NOT NULL
             LIMIT 1);
SET @sql := IF(@fk IS NULL, 'DO 0',
               CONCAT('ALTER TABLE mo_competencies DROP FOREIGN KEY `', @fk, '`'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE()
     AND table_name = 'mo_competencies' AND column_name = 'specialty_id') = 1
  AND
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE()
     AND table_name = 'mo_competencies' AND column_name = 'program_id') = 1,
  'ALTER TABLE mo_competencies DROP COLUMN specialty_id', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. Празната таблица mo_specialties – само ако mo_programs вече е налице
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()
     AND table_name = 'mo_specialties') = 1
  AND
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()
     AND table_name = 'mo_programs') = 1,
  'DROP TABLE mo_specialties', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. Резултат ----------------------------------------------------------
SELECT 'Почистването приключи. Проверете с sql/proverka-versiya.sql.' AS `Готово`;
