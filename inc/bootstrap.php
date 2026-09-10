<?php
/**
 * Стартов файл на модула – включва се в началото на всяка страница.
 * Модулът няма собствен вход: потребителят идва от сесията на ВИС.
 */
declare(strict_types=1);

if (!file_exists(__DIR__ . '/../config.php')) {
    die('<p style="font-family:sans-serif">Липсва <code>config.php</code>. Копирайте '
      . '<code>config.sample.php</code> като <code>config.php</code> и попълнете настройките.</p>');
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/laravel_auth.php';

date_default_timezone_set(APP_TZ);
mb_internal_encoding('UTF-8');
ini_set('display_errors', DEV_MODE ? '1' : '0');
error_reporting(E_ALL);

// Собствена сесия само за CSRF и съобщения – НЕ пипа сесията на Laravel
session_name('MOMODUL');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ------------------------------------------------------------------ */
/* База данни                                                          */
/* ------------------------------------------------------------------ */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );
        } catch (PDOException $e) {
            die('<p style="font-family:sans-serif">Няма връзка с базата на ВИС. Проверете config.php.<br><small>'
              . htmlspecialchars($e->getMessage()) . '</small></p>');
        }
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }

/* ------------------------------------------------------------------ */
/* Помощни                                                             */
/* ------------------------------------------------------------------ */
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect(string $url) { header('Location: ' . $url); exit; }

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$t)) { http_response_code(419); die('Изтекла сесия. Презаредете страницата.'); }
}
function json_out(array $d, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Адрес на файл от assets с версия накрая (?v=…).
 * Версията е датата на промяна на файла, затова след обновяване
 * браузърът зарежда новия файл, вместо стария от кеша.
 */
function asset_url(string $file): string
{
    $url = base_url('assets/' . ltrim($file, '/'));
    $abs = __DIR__ . '/../assets/' . ltrim($file, '/');
    $v = is_file($abs) ? (string)filemtime($abs) : (string)time();
    return $url . '?v=' . $v;
}

function base_url(string $path = ''): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $dir = preg_replace('#/(pages|api)$#', '', $dir);
    return ($dir === '' ? '' : $dir) . '/' . ltrim($path, '/');
}

/* ------------------------------------------------------------------ */
/* Потребител и роли                                                   */
/* ------------------------------------------------------------------ */

/** Влезлият потребител според сесията на ВИС, или null. */
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;

    $id = DEV_MODE && DEV_USER_ID ? (int)DEV_USER_ID : LaravelAuth::userId();
    if (!$id) return $u = null;

    $row = one('SELECT id, name, surname, last_name, email, role FROM users WHERE id = ?', [$id]);
    if (!$row) return $u = null;

    $row['display_name'] = trim(($row['name'] ?? '') . ' ' . ($row['last_name'] ?: ($row['surname'] ?? '')));
    if ($row['display_name'] === '') $row['display_name'] = $row['email'];

    // роли от модула + подразбиращата се роля от ВИС
    $roles = array_column(all('SELECT role FROM mo_user_roles WHERE user_id = ?', [$id]), 'role');
    if (in_array(strtolower((string)$row['role']), ['teacher', 'user'], true) || !$roles) $roles[] = 'teacher';
    $row['roles'] = array_values(array_unique($roles));

    return $u = $row;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        http_response_code(401);
        require __DIR__ . '/no_session.php';
        exit;
    }
    return $u;
}

function has_role(string $role, ?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && in_array($role, $u['roles'], true);
}

function require_role(string ...$roles): array
{
    $u = require_user();
    foreach ($roles as $r) if (has_role($r, $u)) return $u;
    http_response_code(403);
    die('<p style="font-family:sans-serif">Нямате права за тази страница. Обърнете се към администратора на системата.</p>');
}

function role_bg(string $r): string
{
    return ['teacher' => 'учител', 'methodist' => 'методист',
            'deputy' => 'зам-директор', 'admin' => 'администратор'][$r] ?? $r;
}

function roles_bg(array $roles): string
{
    return implode(', ', array_map('role_bg', $roles));
}

function user_name_sql(string $alias = 'u'): string
{
    return "TRIM(CONCAT(COALESCE($alias.name,''),' ',COALESCE($alias.last_name,$alias.surname,'')))";
}

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

/* --------------------------------------------------------------------
 * Методически обединения – автоматично маршрутизиране v5.
 *
 * Има две независими правила:
 *   1) професия/специалност -> отговорно МО  (mo_department_programs)
 *   2) общообразователен предмет -> едно МО (mo_subject_departments)
 *
 * Типът на предмета е глобален според компетентностите:
 * - общообразователните предмети използват mo_subject_departments;
 * - професионалните предмети използват професията/специалността на класа
 *   чрез mo_department_programs.
 *
 * „Чужд език по професията“ е изрично общообразователно изключение, но
 * компетентностите му могат да останат програмно-специфични.
 * ------------------------------------------------------------------ */

/** Пълни данни за активно МО, включително ръководството. */
function department_assignment_details(int $departmentId): ?array
{
    return one(
        'SELECT d.id AS department_id, d.name AS department_name,
                d.is_active AS department_active, d.chair_id, d.deputy_id,
                ' . user_name_sql('ch') . ' AS chair_name, ch.email AS chair_email,
                ' . user_name_sql('dp') . ' AS deputy_name, dp.email AS deputy_email
         FROM mo_departments d
         LEFT JOIN users ch ON ch.id = d.chair_id
         LEFT JOIN users dp ON dp.id = d.deputy_id
         WHERE d.id = ?
         LIMIT 1',
        [$departmentId]
    );
}

/** Отговорното МО за конкретна професия/специалност. */
function program_department_assignment(?int $programId): ?array
{
    if (!$programId) return null;
    $r = one(
        'SELECT dp.id, dp.program_id, dp.department_id, dp.is_active,
                d.name AS department_name, d.is_active AS department_active
         FROM mo_department_programs dp
         JOIN mo_departments d ON d.id = dp.department_id
         WHERE dp.program_id = ? AND dp.is_active = 1
           AND d.is_active = 1 AND d.department_type = \'professional\'
         LIMIT 1',
        [$programId]
    );
    if (!$r) return null;
    $details = department_assignment_details((int)$r['department_id']);
    return $details ? array_merge($r, $details) : $r;
}

/**
 * Единственото общообразователно МО на предмета.
 * mo_subject_departments вече се използва само за тази цел.
 */
function general_subject_department_assignment(int $subjectId): array
{
    $rows = all(
        'SELECT sd.id, sd.subject_id, sd.department_id, sd.is_active,
                d.name AS department_name, d.is_active AS department_active,
                d.chair_id, d.deputy_id,
                ' . user_name_sql('ch') . ' AS chair_name, ch.email AS chair_email,
                ' . user_name_sql('dp') . ' AS deputy_name, dp.email AS deputy_email
         FROM mo_subject_departments sd
         JOIN mo_departments d ON d.id = sd.department_id
         LEFT JOIN users ch ON ch.id = d.chair_id
         LEFT JOIN users dp ON dp.id = d.deputy_id
         WHERE sd.subject_id = ? AND sd.is_active = 1
           AND d.department_type = \'general\'
         ORDER BY d.name',
        [$subjectId]
    );

    if (!$rows) {
        return [
            'ok' => false,
            'code' => 'general_subject_department',
            'error' => 'За този общообразователен предмет няма зададено методическо обединение.',
        ];
    }
    if (count($rows) > 1) {
        return [
            'ok' => false,
            'code' => 'general_subject_multiple_departments',
            'error' => 'Общообразователният предмет е зададен към повече от едно МО. Оставете само едно.',
            'candidates' => $rows,
        ];
    }
    if (!(int)$rows[0]['department_active']) {
        return [
            'ok' => false,
            'code' => 'department_inactive',
            'error' => 'Методическото обединение „' . $rows[0]['department_name'] . '“ е скрито.',
            'candidates' => $rows,
        ];
    }
    return [
        'ok' => true,
        'assignment' => $rows[0],
        'resolution' => 'general_subject',
    ];
}

/**
 * Определя контекста на предмета за конкретната паралелка.
 * Типът се определя глобално от subject_training_type(), за да съвпада
 * точно с административната класификация на предметите.
 */
function subject_context_for_class(int $subjectId, int $classId): array
{
    $c = one('SELECT id, grade_level, program_id FROM mo_classes WHERE id=? AND is_active=1', [$classId]);
    if (!$c) return ['ok'=>false, 'code'=>'class', 'error'=>'Паралелката не съществува или е скрита.'];

    $programId = $c['program_id'] !== null ? (int)$c['program_id'] : null;
    $grade = (int)$c['grade_level'];

    if (subject_is_rpp($subjectId)) {
        if (!$programId) {
            return ['ok'=>false,'code'=>'program_missing','error'=>'Паралелката няма зададена професия/специалност.','mode'=>'professional','is_rpp'=>true];
        }
        if (!rpp_subject_available_for_class($subjectId, $classId)) {
            return ['ok'=>false,'code'=>'subject_class','error'=>'Този РПП предмет не е зададен за класа и професията/специалността на избраната паралелка.','mode'=>'professional','is_rpp'=>true];
        }
        return ['ok'=>true,'mode'=>'professional','program_id'=>$programId,'grade_level'=>$grade,'is_rpp'=>true];
    }

    $type = subject_training_type($subjectId);
    if (!$type) {
        return [
            'ok'=>false,
            'code'=>'subject_class',
            'error'=>'Предметът няма активни компетентности и не може да се използва за анализ.',
        ];
    }

    if ($type === 'professional') {
        if (!$programId) {
            return [
                'ok'=>false,
                'code'=>'program_missing',
                'error'=>'Паралелката няма зададена професия/специалност.',
                'mode'=>'professional',
            ];
        }
        $specific = (bool)one(
            'SELECT id FROM mo_competencies
             WHERE subject_id=? AND grade_level=? AND program_id=? AND is_active=1 LIMIT 1',
            [$subjectId, $grade, $programId]
        );
        if (!$specific) {
            return [
                'ok'=>false,
                'code'=>'subject_class',
                'error'=>'Този професионален предмет няма компетентности за специалността/професията на избраната паралелка.',
                'mode'=>'professional',
            ];
        }
        return ['ok'=>true, 'mode'=>'professional', 'program_id'=>$programId, 'grade_level'=>$grade];
    }

    // Общообразователен предмет. За „Чужд език по професията“ са валидни
    // и компетентности за конкретната програма, но маршрутът остава general.
    $available = false;
    if (subject_is_general_exception($subjectId) && $programId) {
        $available = (bool)one(
            'SELECT id FROM mo_competencies
             WHERE subject_id=? AND grade_level=? AND program_id=? AND is_active=1 LIMIT 1',
            [$subjectId, $grade, $programId]
        );
    }
    if (!$available) {
        $available = (bool)one(
            'SELECT id FROM mo_competencies
             WHERE subject_id=? AND grade_level=? AND program_id IS NULL AND is_active=1 LIMIT 1',
            [$subjectId, $grade]
        );
    }
    if (!$available) {
        return [
            'ok'=>false,
            'code'=>'subject_class',
            'error'=>'Този общообразователен предмет няма компетентности за избраната паралелка.',
            'mode'=>'general',
        ];
    }

    return ['ok'=>true, 'mode'=>'general', 'program_id'=>$programId, 'grade_level'=>$grade];
}

/**
 * Автоматично определя МО за предмет + клас.
 */
function resolve_subject_department_for_class(int $subjectId, int $classId): array
{
    $context = subject_context_for_class($subjectId, $classId);
    if (empty($context['ok'])) return $context;

    if (($context['mode'] ?? '') === 'professional') {
        $programId = (int)($context['program_id'] ?? 0);
        if (!$programId) {
            return [
                'ok'=>false,
                'code'=>'program_missing',
                'error'=>'Паралелката няма зададена професия/специалност.',
                'route_mode'=>'professional',
            ];
        }
        $assignment = program_department_assignment($programId);
        if (!$assignment || !(int)($assignment['department_active'] ?? 0)) {
            return [
                'ok'=>false,
                'code'=>'program_department',
                'error'=>'За професията/специалността на паралелката няма зададено активно методическо обединение.',
                'route_mode'=>'professional',
            ];
        }
        return [
            'ok'=>true,
            'assignment'=>$assignment,
            'resolution'=>'program_department',
            'route_mode'=>'professional',
        ];
    }

    $general = general_subject_department_assignment($subjectId);
    $general['route_mode'] = 'general';
    return $general;
}

function department_of_subject_for_class(int $subjectId, int $classId): ?int
{
    $r = resolve_subject_department_for_class($subjectId, $classId);
    return !empty($r['ok']) ? (int)$r['assignment']['department_id'] : null;
}

/**
 * Legacy helper: mo_subjects.department_id отразява само едното
 * общообразователно МО на предмета.
 */
function sync_subject_legacy_department(int $subjectId): void
{
    $rows = all('SELECT department_id FROM mo_subject_departments WHERE subject_id=? AND is_active=1 ORDER BY department_id', [$subjectId]);
    $dep = count($rows) === 1 ? (int)$rows[0]['department_id'] : null;
    q('UPDATE mo_subjects SET department_id=? WHERE id=?', [$dep, $subjectId]);
}

/** Пълният маршрут на анализ до председателя на правилното МО. */
function analysis_route(int $subjectId, int $classId, ?int $yearId = null): array
{
    $class = one('SELECT c.id, c.name, c.year_id, c.grade_level, c.program_id, c.is_active,
                         p.name AS program_name, p.kind AS program_kind
                  FROM mo_classes c
                  LEFT JOIN mo_programs p ON p.id = c.program_id
                  WHERE c.id = ?', [$classId]);
    if (!$class || !(int)$class['is_active']) {
        return ['ok' => false, 'code' => 'class', 'error' => 'Паралелката не съществува или е скрита.'];
    }
    if ($yearId !== null && (int)$class['year_id'] !== $yearId) {
        return ['ok' => false, 'code' => 'year', 'error' => 'Паралелката не е от избраната учебна година.'];
    }

    $subject = one('SELECT id, name, is_active FROM mo_subjects WHERE id = ?', [$subjectId]);
    if (!$subject || !(int)$subject['is_active']) {
        return ['ok' => false, 'code' => 'subject', 'error' => 'Предметът не съществува или е скрит.'];
    }

    $resolved = resolve_subject_department_for_class($subjectId, $classId);
    if (empty($resolved['ok'])) {
        return array_merge($resolved, ['class'=>$class, 'subject'=>$subject]);
    }

    $assignment = $resolved['assignment'];
    $chairId = $assignment['chair_id'] !== null ? (int)$assignment['chair_id'] : 0;
    $chairName = trim((string)($assignment['chair_name'] ?? ''));
    if (!$chairId) {
        return [
            'ok'=>false,
            'code'=>'chair',
            'error'=>'За ' . $assignment['department_name'] . ' няма назначен председател. Анализът може да се пази като чернова, но не може да бъде изпратен.',
            'department_id'=>(int)$assignment['department_id'],
            'department_name'=>$assignment['department_name'],
            'class'=>$class,
            'subject'=>$subject,
            'assignment'=>$assignment,
            'resolution'=>$resolved['resolution'] ?? null,
            'route_mode'=>$resolved['route_mode'] ?? null,
        ];
    }

    return [
        'ok'=>true,
        'department_id'=>(int)$assignment['department_id'],
        'department_name'=>$assignment['department_name'],
        'methodist_id'=>$chairId,
        'methodist_name'=>$chairName !== '' ? $chairName : (string)($assignment['chair_email'] ?? ''),
        'methodist_email'=>(string)($assignment['chair_email'] ?? ''),
        'deputy_id'=>$assignment['deputy_id'] !== null ? (int)$assignment['deputy_id'] : null,
        'deputy_name'=>trim((string)($assignment['deputy_name'] ?? '')),
        'class'=>$class,
        'subject'=>$subject,
        'assignment'=>$assignment,
        'resolution'=>$resolved['resolution'] ?? null,
        'route_mode'=>$resolved['route_mode'] ?? null,
        'is_rpp'=>subject_is_rpp($subjectId),
    ];
}

/** Единственото общообразователно МО на предмета, ако е зададено еднозначно. */
function department_of_subject(int $subjectId): ?int
{
    $r = general_subject_department_assignment($subjectId);
    return !empty($r['ok']) ? (int)$r['assignment']['department_id'] : null;
}

/**
 * Синхронизира производната роля "methodist" с ръководството на МО.
 * Ролята не се назначава ръчно: председателите и заместниците я получават
 * автоматично, а при освобождаване се премахва, ако човекът не ръководи друго МО.
 */
function sync_methodist_roles(?int $assignedBy = null): void
{
    q('DELETE r FROM mo_user_roles r
       LEFT JOIN mo_departments d
         ON d.is_active = 1 AND (d.chair_id = r.user_id OR d.deputy_id = r.user_id)
       WHERE r.role = "methodist" AND d.id IS NULL');

    q('INSERT IGNORE INTO mo_user_roles (user_id, role, assigned_by)
       SELECT x.user_id, "methodist", ?
       FROM (
           SELECT chair_id AS user_id FROM mo_departments WHERE is_active=1 AND chair_id IS NOT NULL
           UNION
           SELECT deputy_id AS user_id FROM mo_departments WHERE is_active=1 AND deputy_id IS NOT NULL
       ) x', [$assignedBy]);
}

/** МО-тата, в които потребителят е председател или заместник. */
function my_departments(?array $u = null): array
{
    $u = $u ?? current_user();
    if (!$u) return [];
    static $cache = [];
    $id = (int)$u['id'];
    if (!isset($cache[$id])) {
        $cache[$id] = all('SELECT * FROM mo_departments
                           WHERE is_active = 1 AND (chair_id = ? OR deputy_id = ?)
                           ORDER BY name', [$id, $id]);
    }
    return $cache[$id];
}

/** Ръководи ли потребителят поне едно МО? */
function leads_department(?array $u = null): bool
{
    return (bool)my_departments($u);
}

/** Каква е ролята му в дадено МО: председател, заместник или нищо. */
function department_role(int $depId, ?array $u = null): ?string
{
    $u = $u ?? current_user();
    if (!$u) return null;
    $d = one('SELECT chair_id, deputy_id FROM mo_departments WHERE id = ?', [$depId]);
    if (!$d) return null;
    if ((int)$d['chair_id']  === (int)$u['id']) return 'chair';
    if ((int)$d['deputy_id'] === (int)$u['id']) return 'deputy';
    return null;
}

function department_role_bg(?string $r): string
{
    return ['chair' => 'председател на МО', 'deputy' => 'зам.-председател на МО'][$r] ?? '';
}

function fmt_avg($v): string { return $v === null ? '–' : number_format((float)$v, 2, ',', ' '); }
function fmt_pct($v): string { return $v === null ? '–' : number_format((float)$v * 100, 1, ',', ' ') . '%'; }
