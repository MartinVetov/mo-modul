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
 * Привежда името на предмета от файла към предмет + подраздел.
 * Първо се търси в таблицата със съответствия, после вградените правила.
 *
 * @return array{0:string,1:?string}
 */
function normalize_subject(string $name): array
{
    [$subject, $section] = resolve_subject($name);
    if ($section !== null || $subject !== trim($name)) return [$subject, $section];

    $n = preg_replace('/\s+/u', ' ', mb_strtolower(trim($name))) ?? '';
    if (in_array($n, ['литература', 'лит.'], true))                     return [BEL_SUBJECT, 'Литература'];
    if (in_array($n, ['български език', 'бълг. език'], true))           return [BEL_SUBJECT, 'Български език'];
    if (in_array($n, ['бел', 'б.е.л.', 'български език и литература'], true)) return [BEL_SUBJECT, null];
    return [trim($name), null];
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
 * Компетентностите за предмет + паралелка: тези за професията или
 * специалността на паралелката плюс общите (program_id IS NULL).
 */
function competencies_for(int $subjectId, int $classId): array
{
    $c = one('SELECT grade_level, program_id FROM mo_classes WHERE id = ?', [$classId]);
    if (!$c) return [];
    return all(
        'SELECT k.id, k.code, k.title, k.source, k.section, k.program_id,
                pr.name AS program_name, pr.kind AS program_kind
         FROM mo_competencies k
         LEFT JOIN mo_programs pr ON pr.id = k.program_id
         WHERE k.subject_id = ? AND k.grade_level = ? AND k.is_active = 1
           AND (k.program_id IS NULL OR k.program_id <=> ?)
         ORDER BY ' . section_order_sql('k') . ', k.sort_order, k.id',
        [$subjectId, $c['grade_level'], $c['program_id']]
    );
}

/** Папката, в която се пазят изготвените документи. */
function docs_dir(): string
{
    $d = rtrim(UPLOAD_DIR, '/\\') . '/dokumenti';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}

/* --------------------------------------------------------------------
 * Методически обединения.
 * Предметът принадлежи на МО, а МО има председател и заместник.
 * Затова смяната на председател не пипа нито предметите, нито анализите.
 * ------------------------------------------------------------------ */

/** МО-то, на което принадлежи предметът. */
function department_of_subject(int $subjectId): ?int
{
    $r = one('SELECT department_id FROM mo_subjects WHERE id = ?', [$subjectId]);
    return $r && $r['department_id'] !== null ? (int)$r['department_id'] : null;
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
