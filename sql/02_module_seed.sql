-- =====================================================================
--  Начални данни за модула. Изпълнява се СЛЕД 01_module_schema.sql
--  Не създава потребители – те идват от `users` на ВИС.
-- =====================================================================
SET NAMES utf8mb4;

-- Учебна година (както се изписва във ВИС)
INSERT IGNORE INTO mo_years (label, is_active) VALUES ('2025-2026', 1);
INSERT IGNORE INTO mo_years (label, is_active) VALUES ('2026-2027', 0);

-- ---------------------------------------------------------------------
-- Паралелки 8А … 12Ж (5 класа × 7 букви = 35 паралелки)
-- Ако в гимназията няма някоя паралелка, скрийте я от
-- „Администриране → Паралелки“ вместо да я триете.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_classes (name, grade_level, letter) VALUES
('8А',8,'А'),('8Б',8,'Б'),('8В',8,'В'),('8Г',8,'Г'),('8Д',8,'Д'),('8Е',8,'Е'),('8Ж',8,'Ж'),
('9А',9,'А'),('9Б',9,'Б'),('9В',9,'В'),('9Г',9,'Г'),('9Д',9,'Д'),('9Е',9,'Е'),('9Ж',9,'Ж'),
('10А',10,'А'),('10Б',10,'Б'),('10В',10,'В'),('10Г',10,'Г'),('10Д',10,'Д'),('10Е',10,'Е'),('10Ж',10,'Ж'),
('11А',11,'А'),('11Б',11,'Б'),('11В',11,'В'),('11Г',11,'Г'),('11Д',11,'Д'),('11Е',11,'Е'),('11Ж',11,'Ж'),
('12А',12,'А'),('12Б',12,'Б'),('12В',12,'В'),('12Г',12,'Г'),('12Д',12,'Д'),('12Е',12,'Е'),('12Ж',12,'Ж');

-- ---------------------------------------------------------------------
-- Примерни предмети. Реалните се създават сами при импорта на
-- компетентностите (клас · предмет · компетенция).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_subjects (name) VALUES
('Български език и литература'),
('Математика'),
('Компютърни мрежи'),
('Микропроцесорна техника');

-- ---------------------------------------------------------------------
-- ПРИМЕРНИ компетентности – заменете ги с реалните от ДОС и УП
-- през „Администриране → Компетентности → Импорт от Excel“.
-- ---------------------------------------------------------------------
INSERT INTO mo_competencies (subject_id, grade_level, code, title, source, sort_order)
SELECT s.id, 8, 'ДОС 1.1', 'Разбира и интерпретира художествен текст', 'ДОС БЕЛ', 10
FROM mo_subjects s WHERE s.name = 'Български език и литература'
AND NOT EXISTS (SELECT 1 FROM mo_competencies k WHERE k.subject_id = s.id AND k.grade_level = 8);

INSERT INTO mo_competencies (subject_id, grade_level, code, title, source, sort_order)
SELECT s.id, 8, 'ДОС 1.2', 'Създава текст по зададена тема и жанр', 'ДОС БЕЛ', 20
FROM mo_subjects s WHERE s.name = 'Български език и литература'
AND NOT EXISTS (SELECT 1 FROM mo_competencies k WHERE k.subject_id = s.id AND k.grade_level = 8 AND k.code = 'ДОС 1.2');

INSERT INTO mo_competencies (subject_id, grade_level, code, title, source, sort_order)
SELECT s.id, 11, 'ДОС 2.1', 'Извършва адресиране и подмрежиране по IPv4', 'УП, раздел II', 10
FROM mo_subjects s WHERE s.name = 'Компютърни мрежи'
AND NOT EXISTS (SELECT 1 FROM mo_competencies k WHERE k.subject_id = s.id AND k.grade_level = 11 AND k.code = 'ДОС 2.1');

INSERT INTO mo_competencies (subject_id, grade_level, code, title, source, sort_order)
SELECT s.id, 11, 'ДОС 2.2', 'Конфигурира комутатор и маршрутизатор', 'УП, раздел III', 20
FROM mo_subjects s WHERE s.name = 'Компютърни мрежи'
AND NOT EXISTS (SELECT 1 FROM mo_competencies k WHERE k.subject_id = s.id AND k.grade_level = 11 AND k.code = 'ДОС 2.2');

-- ---------------------------------------------------------------------
-- Роли в модула.
-- ЗАМЕНЕТЕ имейлите с реалните хора от вашата ВИС.
-- Всеки с role='teacher' в `users` и без ред тук пак е учител –
-- ролята teacher се подразбира.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_user_roles (user_id, role)
SELECT id, 'admin' FROM users WHERE email = 'vetov_pgtk@abv.bg';

INSERT IGNORE INTO mo_user_roles (user_id, role)
SELECT id, 'methodist' FROM users WHERE email = 'bobiltz2299@gmail.com';
