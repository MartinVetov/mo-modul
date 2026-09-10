<?php
/**
 * Предметна логика: срокове, професии, компетентности, документи
 *
 * Включва се от inc/bootstrap.php – не се require-ва пряко.
 */

/* ------------------------------------------------------------------ */
/* Домейн                                                              */
/* ------------------------------------------------------------------ */
const TERMS  = ['I' => 'I срок', 'II' => 'II срок'];

const GROUPS = ['0' => 'цяла паралелка', '1' => 'I-ва група', '2' => 'II-ра група'];

const STATES = [
    'mastered'     => 'усвоена',
    'partial'      => 'частично усвоена',
    'not_mastered' => 'неусвоена',
];

function term_code(?string $t): string { return isset(TERMS[(string)$t]) ? (string)$t : 'I'; }

function term_label(?string $t): string { return TERMS[term_code($t)]; }

function active_year(): ?array
{
    return one('SELECT * FROM mo_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1')
        ?? one('SELECT * FROM mo_years ORDER BY id DESC LIMIT 1');
}

function current_year_id(): int
{
    if (!empty($_SESSION['year_id'])) return (int)$_SESSION['year_id'];
    $y = active_year();
    return $y ? (int)$y['id'] : 0;
}

/* Видове програми: новите професии и старите специалности. */
const PROGRAM_KINDS = ['profession' => 'професия', 'specialty' => 'специалност'];

/** Списък с професии и специалности за падащите менюта. */
function programs(?string $kind = null, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM mo_programs WHERE 1=1';
    $p = [];
    if ($kind !== null) { $sql .= ' AND kind = ?'; $p[] = $kind; }
    if ($onlyActive)    { $sql .= ' AND is_active = 1'; }
    return all($sql . ' ORDER BY kind DESC, name', $p);
}

function program_label(?array $prog): string
{
    if (!$prog) return '—';
    return $prog['name'] . ' (' . (PROGRAM_KINDS[$prog['kind']] ?? $prog['kind']) . ')';
}

/**
 * Разрешава името на предмет от файл към предмет в системата и подраздел.
 * Пример: „Литература“ → [„Български език и литература“, „Литература“].
 */
function resolve_subject(string $name): array
{
    $name = trim($name);
    $a = one('SELECT subject_name, section FROM mo_subject_aliases WHERE alias = ?', [$name]);
    if ($a) return [$a['subject_name'], $a['section'] !== '' ? $a['section'] : null];
    return [$name, null];
}

/* --------------------------------------------------------------------
 * Български език и литература е един предмет, но учебната програма е
 * разделена на два дяла. Затова компетентностите му се водят с подраздел.
 * ------------------------------------------------------------------ */
const BEL_SUBJECT  = 'Български език и литература';

const BEL_SECTIONS = ['Български език', 'Литература'];

/**
 * Уеднаквява всички използвани варианти за учебна практика към „УП - …“.
 * Приема напр.:
 *   „УП Програмиране“, „УП: Програмиране“, „УП-Програмиране“,
 *   „УП – Програмиране“, „Учебна практика: Програмиране“,
 *   „Учебна практика - Програмиране“, „Учебна практика Програмиране“.
 */
function normalize_practice_subject(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

    if (preg_match('/^(?:Учебна\s+практика|УП)(?=\s|[-–—:.]|$)\s*(?:[-–—:.]\s*)?(.+)$/ui', $name, $m)) {
        $rest = trim($m[1]);
        $rest = preg_replace('/^[\s\-–—:.]+/u', '', $rest) ?? $rest;
        $rest = preg_replace('/\s+/u', ' ', trim($rest)) ?? trim($rest);
        if ($rest !== '') return 'УП - ' . $rest;
    }

    return $name;
}

/**
 * Привежда името на предмета от файла към предмет + подраздел.
 * Първо се търси в таблицата със съответствия, после се уеднаквява
 * учебната практика и накрая се прилагат вградените правила за БЕЛ.
 *
 * @return array{0:string,1:?string}
 */
function normalize_subject(string $name): array
{
    $raw = trim($name);
    [$subject, $section] = resolve_subject($raw);
    $subject = normalize_practice_subject($subject);
    if ($section !== null || $subject !== $raw) return [$subject, $section];

    $n = preg_replace('/\s+/u', ' ', mb_strtolower($raw)) ?? '';
    if (in_array($n, ['литература', 'лит.'], true))                     return [BEL_SUBJECT, 'Литература'];
    if (in_array($n, ['български език', 'бълг. език'], true))           return [BEL_SUBJECT, 'Български език'];
    if (in_array($n, ['бел', 'б.е.л.', 'български език и литература'], true)) return [BEL_SUBJECT, null];
    return [normalize_practice_subject($raw), null];
}

/** Подрежда подразделите: първо езикът, после литературата. */
function section_order_sql(string $alias = 'k'): string
{
    return "CASE $alias.section WHEN 'Български език' THEN 1 WHEN 'Литература' THEN 2 ELSE 0 END";
}

/** Професията или специалността на паралелка. */
function class_program(int $classId): ?int
{
    $r = one('SELECT program_id FROM mo_classes WHERE id = ?', [$classId]);
    return $r && $r['program_id'] !== null ? (int)$r['program_id'] : null;
}

/**
 * Валиден ли е предметът за конкретната паралелка.
 * За анализа приемаме, че предметът е приложим само ако има поне една
 * активна компетентност за класа (съответния випуск) и за неговата
 * професия/специалност или обща компетентност за всички програми.
 */
/**
 * Изключение от автоматичната класификация.
 * „Чужд език по професията“ се третира като общообразователен предмет,
 * дори когато компетентностите му са въведени за конкретни програми.
 */
/** РПП предмет без предварително заредени компетентности. */
function subject_is_rpp(int $subjectId): bool
{
    return (bool)one('SELECT subject_id FROM mo_rpp_subjects WHERE subject_id=? LIMIT 1', [$subjectId]);
}

/** Валиден ли е РПП предметът за конкретната паралелка. */
function rpp_subject_available_for_class(int $subjectId, int $classId): bool
{
    $c = one('SELECT grade_level,program_id FROM mo_classes WHERE id=? AND is_active=1', [$classId]);
    if (!$c || $c['program_id'] === null || !subject_is_rpp($subjectId)) return false;
    return (bool)one(
        'SELECT rs.subject_id
         FROM mo_rpp_subjects rs
         JOIN mo_subjects s ON s.id=rs.subject_id AND s.is_active=1
         JOIN mo_rpp_subject_grades rg ON rg.subject_id=rs.subject_id AND rg.grade_level=?
         JOIN mo_rpp_subject_programs rp ON rp.subject_id=rs.subject_id AND rp.program_id=?
         WHERE rs.subject_id=? AND rs.is_active=1
         LIMIT 1',
        [(int)$c['grade_level'], (int)$c['program_id'], $subjectId]
    );
}

function subject_is_general_exception(int $subjectId): bool
{
    $r = one('SELECT name FROM mo_subjects WHERE id=? AND is_active=1', [$subjectId]);
    if (!$r) return false;
    $name = preg_replace('/\\s+/u', ' ', trim((string)$r['name']));
    return $name === 'Чужд език по професията';
}

/**
 * Тип на предмета според активните компетентности.
 * - professional: има поне една компетентност за конкретна програма;
 * - general: има само общи компетентности (program_id IS NULL);
 * - null: няма активни компетентности.
 *
 * Изключение: „Чужд език по професията“ винаги е general за целите на
 * маршрутизирането, независимо от начина, по който са въведени компетентностите.
 */
function subject_training_type(int $subjectId): ?string
{
    if (subject_is_rpp($subjectId)) return 'professional';

    if (subject_is_general_exception($subjectId)) {
        $hasAny = (bool)one(
            'SELECT id FROM mo_competencies WHERE subject_id=? AND is_active=1 LIMIT 1',
            [$subjectId]
        );
        return $hasAny ? 'general' : null;
    }

    $specific = (bool)one(
        'SELECT id FROM mo_competencies
         WHERE subject_id=? AND is_active=1 AND program_id IS NOT NULL LIMIT 1',
        [$subjectId]
    );
    if ($specific) return 'professional';

    $general = (bool)one(
        'SELECT id FROM mo_competencies
         WHERE subject_id=? AND is_active=1 AND program_id IS NULL LIMIT 1',
        [$subjectId]
    );
    return $general ? 'general' : null;
}

/** Валиден ли е предметът за конкретната паралелка според типа му. */
function subject_available_for_class(int $subjectId, int $classId): bool
{
    $c = one('SELECT grade_level, program_id FROM mo_classes WHERE id = ? AND is_active = 1', [$classId]);
    if (!$c) return false;

    if (subject_is_rpp($subjectId)) return rpp_subject_available_for_class($subjectId, $classId);

    $type = subject_training_type($subjectId);
    if ($type === 'professional') {
        if ($c['program_id'] === null) return false;
        return (bool)one(
            'SELECT k.id
             FROM mo_competencies k
             JOIN mo_subjects s ON s.id=k.subject_id AND s.is_active=1
             WHERE k.subject_id=? AND k.grade_level=? AND k.program_id=? AND k.is_active=1
             LIMIT 1',
            [$subjectId, $c['grade_level'], $c['program_id']]
        );
    }

    if ($type === 'general') {
        if (subject_is_general_exception($subjectId)) {
            // За „Чужд език по професията“ компетентностите могат да са
            // програмно-специфични, но маршрутът остава общообразователен.
            if ($c['program_id'] !== null) {
                $specific = (bool)one(
                    'SELECT k.id
                     FROM mo_competencies k
                     JOIN mo_subjects s ON s.id=k.subject_id AND s.is_active=1
                     WHERE k.subject_id=? AND k.grade_level=? AND k.program_id=? AND k.is_active=1
                     LIMIT 1',
                    [$subjectId, $c['grade_level'], $c['program_id']]
                );
                if ($specific) return true;
            }
        }

        return (bool)one(
            'SELECT k.id
             FROM mo_competencies k
             JOIN mo_subjects s ON s.id=k.subject_id AND s.is_active=1
             WHERE k.subject_id=? AND k.grade_level=? AND k.program_id IS NULL AND k.is_active=1
             LIMIT 1',
            [$subjectId, $c['grade_level']]
        );
    }
    return false;
}

/**
 * Компетентностите за предмет + паралелка.
 * Маршрутът е според глобалния тип на предмета. При специалното изключение
 * „Чужд език по професията“ първо се зареждат компетентностите за конкретната
 * програма на класа, а ако няма такива – общите компетентности.
 */
function competencies_for(int $subjectId, int $classId): array
{
    $c = one('SELECT grade_level, program_id FROM mo_classes WHERE id = ?', [$classId]);
    if (!$c) return [];

    if (subject_is_rpp($subjectId)) return [];

    $type = subject_training_type($subjectId);
    if ($type === 'professional') {
        if ($c['program_id'] === null) return [];
        return all(
            'SELECT k.id, k.code, k.title, k.source, k.section, k.program_id,
                    pr.name AS program_name, pr.kind AS program_kind
             FROM mo_competencies k
             LEFT JOIN mo_programs pr ON pr.id=k.program_id
             WHERE k.subject_id=? AND k.grade_level=? AND k.program_id=? AND k.is_active=1
             ORDER BY ' . section_order_sql('k') . ', k.sort_order, k.id',
            [$subjectId, $c['grade_level'], $c['program_id']]
        );
    }

    if ($type === 'general') {
        if (subject_is_general_exception($subjectId) && $c['program_id'] !== null) {
            $specific = all(
                'SELECT k.id, k.code, k.title, k.source, k.section, k.program_id,
                        pr.name AS program_name, pr.kind AS program_kind
                 FROM mo_competencies k
                 LEFT JOIN mo_programs pr ON pr.id=k.program_id
                 WHERE k.subject_id=? AND k.grade_level=? AND k.program_id=? AND k.is_active=1
                 ORDER BY ' . section_order_sql('k') . ', k.sort_order, k.id',
                [$subjectId, $c['grade_level'], $c['program_id']]
            );
            if ($specific) return $specific;
        }

        return all(
            'SELECT k.id, k.code, k.title, k.source, k.section, k.program_id,
                    pr.name AS program_name, pr.kind AS program_kind
             FROM mo_competencies k
             LEFT JOIN mo_programs pr ON pr.id=k.program_id
             WHERE k.subject_id=? AND k.grade_level=? AND k.program_id IS NULL AND k.is_active=1
             ORDER BY ' . section_order_sql('k') . ', k.sort_order, k.id',
            [$subjectId, $c['grade_level']]
        );
    }

    return [];
}

/**
 * Компетентности за вече записан стандартен анализ.
 *
 * За нов анализ competencies_for() остава единственият източник. При
 * Преглед/Редакция обаче записаните отметки са snapshot на анализа и не
 * трябва да изчезват, ако по-късно е променена административната
 * класификация на предмета или връзката му с програма.
 *
 * Връщаме текущия официален списък и задължително добавяме всяка
 * компетентност, която реално участва в mo_entry_competencies за entryId.
 * Ако текущият официален списък е празен, възстановяваме най-подходящия
 * активен списък по предмет + клас + програма като legacy fallback.
 */
function competencies_for_existing_entry(int $entryId, int $subjectId, int $classId): array
{
    if ($entryId < 1 || $subjectId < 1 || $classId < 1 || subject_is_rpp($subjectId)) return [];

    $current = competencies_for($subjectId, $classId);
    $byId = [];
    foreach ($current as $row) $byId[(int)$row['id']] = $row;

    $class = one('SELECT grade_level,program_id FROM mo_classes WHERE id=?', [$classId]);

    /* Ако текущата логика вече не може да определи вида на предмета,
       възстановяваме списъка по реалната паралелка. Предпочитаме
       програмно-специфичните компетентности; ако няма такива – общите. */
    if (!$current && $class) {
        $fallback = [];
        if ($class['program_id'] !== null) {
            $fallback = all(
                'SELECT k.id,k.code,k.title,k.source,k.section,k.program_id,
                        pr.name AS program_name,pr.kind AS program_kind
                 FROM mo_competencies k
                 LEFT JOIN mo_programs pr ON pr.id=k.program_id
                 WHERE k.subject_id=? AND k.grade_level=? AND k.program_id=? AND k.is_active=1
                 ORDER BY ' . section_order_sql('k') . ',k.sort_order,k.id',
                [$subjectId, (int)$class['grade_level'], (int)$class['program_id']]
            );
        }
        if (!$fallback) {
            $fallback = all(
                'SELECT k.id,k.code,k.title,k.source,k.section,k.program_id,
                        pr.name AS program_name,pr.kind AS program_kind
                 FROM mo_competencies k
                 LEFT JOIN mo_programs pr ON pr.id=k.program_id
                 WHERE k.subject_id=? AND k.grade_level=? AND k.program_id IS NULL AND k.is_active=1
                 ORDER BY ' . section_order_sql('k') . ',k.sort_order,k.id',
                [$subjectId, (int)$class['grade_level']]
            );
        }
        foreach ($fallback as $row) {
            $id = (int)$row['id'];
            if (!isset($byId[$id])) {
                $current[] = $row;
                $byId[$id] = $row;
            }
        }
    }

    /* Записаните отметки са най-важният snapshot. Добавяме ги дори ако
       компетентността вече е деактивирана или е отпаднала от текущия
       официален набор, за да не се губи съдържание от стар анализ. */
    $savedRows = all(
        'SELECT k.id,k.code,k.title,k.source,k.section,k.program_id,
                pr.name AS program_name,pr.kind AS program_kind
         FROM mo_entry_competencies ec
         JOIN mo_competencies k ON k.id=ec.competency_id
         LEFT JOIN mo_programs pr ON pr.id=k.program_id
         WHERE ec.entry_id=?
         ORDER BY ' . section_order_sql('k') . ',k.sort_order,k.id',
        [$entryId]
    );
    foreach ($savedRows as $row) {
        $id = (int)$row['id'];
        if (!isset($byId[$id])) {
            $current[] = $row;
            $byId[$id] = $row;
        }
    }

    return $current;
}

/** Папката, в която се пазят изготвените документи. */
function docs_dir(): string
{
    $d = rtrim(UPLOAD_DIR, '/\\') . '/dokumenti';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}
