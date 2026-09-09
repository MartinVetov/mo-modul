-- =====================================================================
--  Начални данни за модула. Изпълнява се СЛЕД 01_module_schema.sql
--  Не създава потребители – те идват от `users` на ВИС.
-- =====================================================================
SET NAMES utf8mb4;

-- Учебна година (както се изписва във ВИС)
INSERT IGNORE INTO mo_years (label, is_active) VALUES ('2025-2026', 1);
INSERT IGNORE INTO mo_years (label, is_active) VALUES ('2026-2027', 0);

-- ---------------------------------------------------------------------
-- Регистър: НОВИ професии и СТАРИ специалности
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_programs (kind, name, note) VALUES
('profession', 'Комуникационни и компютърни мрежи',        'нова класификация на МОН'),
('profession', 'Информационни системи',                    'нова класификация на МОН'),
('profession', 'Разработка на софтуер',                    'нова класификация на МОН'),
('profession', 'Осигуряване на качеството на софтуер',     'нова класификация на МОН'),
('profession', 'Компютърни системи и технологии',          'нова класификация на МОН'),
('profession', 'Електронна търговия, маркетинг и реклама', 'нова класификация на МОН'),
('profession', 'Икономическа информатика',                 'нова класификация на МОН');

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
-- Паралелки 8А … 12Ж за активната учебна година.
-- Осмите се въвеждат с ПРОФЕСИЯ, а 9-12 клас за 2026-2027 остават със
-- СПЕЦИАЛНОСТ. Задава се от „Години и паралелки“.
-- При нова учебна година паралелките се прехвърлят нагоре с един клас.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_classes (name, grade_level, letter, year_id)
SELECT CONCAT(g.n, l.s), g.n, l.s, (SELECT id FROM mo_years WHERE is_active = 1 LIMIT 1)
FROM (SELECT 8 n UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12) g
CROSS JOIN (SELECT 'А' s UNION SELECT 'Б' UNION SELECT 'В' UNION SELECT 'Г'
            UNION SELECT 'Д' UNION SELECT 'Е' UNION SELECT 'Ж') l;

-- ---------------------------------------------------------------------
-- Методически обединения (примерни – преименуват се от админ панела)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_departments (name) VALUES
('МО „Български език и литература“'),
('МО „Природни науки и математика“'),
('МО „Професионална подготовка“'),
('МО „Обществени науки и гражданско образование“');

-- ---------------------------------------------------------------------
-- Примерни предмети. Реалните се създават сами при импорта на
-- компетентностите (клас · предмет · компетенция).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_subjects (name, department_id) VALUES
('Български език и литература', (SELECT id FROM mo_departments WHERE name='МО „Български език и литература“')),
('Математика',                  (SELECT id FROM mo_departments WHERE name='МО „Природни науки и математика“')),
('Компютърни мрежи',            (SELECT id FROM mo_departments WHERE name='МО „Професионална подготовка“')),
('Микропроцесорна техника',     (SELECT id FROM mo_departments WHERE name='МО „Професионална подготовка“'));

-- ---------------------------------------------------------------------
-- Съответствия на имена: БЕЛ се води един предмет с два подраздела
-- ---------------------------------------------------------------------
INSERT IGNORE INTO mo_subject_aliases (alias, subject_name, section) VALUES
('Литература',                  'Български език и литература', 'Литература'),
('Български език',              'Български език и литература', 'Български език'),
('БЕЛ',                         'Български език и литература', NULL),
('Български език и литература', 'Български език и литература', NULL);

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

INSERT INTO mo_competencies (subject_id, grade_level, program_id, code, title, source, sort_order)
SELECT s.id, 11, (SELECT id FROM mo_programs WHERE kind='specialty' AND name='Компютърни мрежи'),
       'ДОС 2.1', 'Извършва адресиране и подмрежиране по IPv4', 'УП, раздел II', 10
FROM mo_subjects s WHERE s.name = 'Компютърни мрежи'
AND NOT EXISTS (SELECT 1 FROM mo_competencies k WHERE k.subject_id = s.id AND k.grade_level = 11 AND k.code = 'ДОС 2.1');

INSERT INTO mo_competencies (subject_id, grade_level, program_id, code, title, source, sort_order)
SELECT s.id, 11, (SELECT id FROM mo_programs WHERE kind='specialty' AND name='Телекомуникационни системи'),
       'ДОС 2.2', 'Изгражда и тества оптична линия', 'УП, раздел III', 20
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
