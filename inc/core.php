<?php
/**
 * Ядро: база данни, сесия, помощни функции, форматиране
 *
 * Включва се от inc/bootstrap.php – не се require-ва пряко.
 */

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

function fmt_avg($v): string { return $v === null ? '–' : number_format((float)$v, 2, ',', ' '); }

function fmt_pct($v): string { return $v === null ? '–' : number_format((float)$v * 100, 1, ',', ' ') . '%'; }
