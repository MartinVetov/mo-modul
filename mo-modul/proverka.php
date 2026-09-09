<?php
/**
 * ПРОВЕРКА НА ИНСТАЛАЦИЯТА
 * Отворете: http://localhost/.../mo-modul/proverka.php
 * Показва къде точно се къса връзката. ИЗТРИЙТЕ ФАЙЛА след настройката.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$rows = [];
$add = static function (string $what, bool $ok, string $detail = '', string $fix = '') use (&$rows) {
    $rows[] = ['what' => $what, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
};

/* 1. config.php ---------------------------------------------------- */
$cfg = __DIR__ . '/config.php';
if (!is_file($cfg)) {
    $add('config.php', false, 'липсва',
         'Копирайте config.sample.php като config.php в същата папка.');
} else {
    // проверка за повторени define преди да го включим
    $src = (string)file_get_contents($cfg);
    $dupes = [];
    foreach (['DEV_MODE', 'DEV_USER_ID', 'DB_NAME', 'LARAVEL_PATH', 'VIS_URL'] as $c) {
        if (preg_match_all("/^\s*define\(\s*'" . $c . "'/m", $src) > 1) $dupes[] = $c;
    }
    if ($dupes) {
        $add('Повторени настройки в config.php', false, implode(', ', $dupes),
             'PHP приема САМО първото define за дадена константа. Изтрийте излишните редове '
           . 'и поправете стойността на първия, вместо да добавяте нов ред отдолу.');
    } else {
        $add('config.php', true, 'намерен, без повторени настройки');
    }
    require_once $cfg;
}

if (!defined('DB_NAME')) {
    render($rows);
    exit;
}

/* 2. PHP разширения ------------------------------------------------ */
foreach (['pdo_mysql' => 'връзка с базата', 'mbstring' => 'кирилица',
          'zip' => 'четене на .xlsx (за CSV не е нужно)', 'openssl' => 'сесията на Laravel'] as $ext => $why) {
    $add('Разширение ' . $ext, extension_loaded($ext), $why,
         extension_loaded($ext) ? '' : 'В php.ini махнете ; пред extension=' . $ext . ' и рестартирайте Apache.');
}

/* 3. Режим за разработка ------------------------------------------- */
$add('DEV_MODE', true, DEV_MODE ? 'включен (за тест)' : 'изключен (за реална работа)',
     DEV_MODE ? '' : 'За тест без ВИС: сложете define(\'DEV_MODE\', true) и валиден DEV_USER_ID.');

/* 4. База данни ---------------------------------------------------- */
$pdo = null;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                   DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $add('Връзка с базата', true, DB_NAME . ' на ' . DB_HOST);
} catch (Throwable $e) {
    $add('Връзка с базата', false, $e->getMessage(),
         'Проверете DB_NAME, DB_USER и DB_PASS в config.php и дали MySQL в XAMPP е стартиран.');
}

$users = [];
if ($pdo) {
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
        $add('Таблица users (от ВИС)', $n > 0, $n . ' записа',
             $n ? '' : 'Заредете дъмпа на ВИС или поне vis_users.sql в тази база.');
        $users = $pdo->query("SELECT id, TRIM(CONCAT(COALESCE(name,''),' ',COALESCE(last_name,surname,'')))
                              AS ime FROM users ORDER BY id LIMIT 8")->fetchAll();
    } catch (Throwable $e) {
        $add('Таблица users (от ВИС)', false, 'липсва',
             'Модулът работи В БАЗАТА НА ВИС. Заредете vis_users.sql или посочете правилната база.');
    }

    $missing = [];
    foreach (['mo_years', 'mo_classes', 'mo_subjects', 'mo_competencies',
              'mo_entries', 'mo_user_roles', 'mo_summaries'] as $t) {
        try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); } catch (Throwable $e) { $missing[] = $t; }
    }
    $add('Таблици на модула', !$missing, $missing ? 'липсват: ' . implode(', ', $missing) : 'всички са налице',
         $missing ? 'Изпълнете sql/01_module_schema.sql, после sql/02_module_seed.sql в phpMyAdmin.' : '');

    if (!$missing) {
        $c = (int)$pdo->query('SELECT COUNT(*) c FROM mo_classes WHERE is_active=1')->fetch()['c'];
        $k = (int)$pdo->query('SELECT COUNT(*) c FROM mo_competencies WHERE is_active=1')->fetch()['c'];
        $y = $pdo->query('SELECT label FROM mo_years WHERE is_active=1 LIMIT 1')->fetch();
        $add('Начални данни', $c > 0, $c . ' паралелки, ' . $k . ' компетентности, активна година: '
             . ($y['label'] ?? 'НЯМА'),
             $c ? '' : 'Изпълнете sql/02_module_seed.sql.');
    }
}

/* 5. Кой ще бъде разпознат ----------------------------------------- */
if (DEV_MODE) {
    $id = (int)DEV_USER_ID;
    $found = null;
    if ($pdo && $id) {
        try {
            $found = $pdo->query('SELECT id FROM users WHERE id = ' . $id)->fetch();
        } catch (Throwable $e) { /* без таблица users */ }
    }
    $add('DEV_USER_ID', (bool)$found, $id ? ('id = ' . $id . ($found ? ' – намерен' : ' – НЯМА такъв потребител')) : 'не е зададен',
         $found ? '' : 'Сложете id от списъка вдясно, напр. define(\'DEV_USER_ID\', ' . ($users[0]['id'] ?? 1) . ');');
} else {
    require_once __DIR__ . '/inc/laravel_auth.php';
    // laravel_auth ползва one() от bootstrap само за „запомни ме“
    if (!function_exists('one')) {
        function one(string $sql, array $p = []): ?array
        {
            global $pdo;
            if (!$pdo) return null;
            $st = $pdo->prepare($sql); $st->execute($p);
            $r = $st->fetch(); return $r === false ? null : $r;
        }
    }
    foreach (LaravelAuth::diagnose() as $k => $v) {
        $ok = !in_array((string)$v, ['ЛИПСВА', 'не', 'липсва', 'няма', 'не е намерен', 'не е разчетено'], true);
        $add('ВИС: ' . $k, $ok, (string)$v);
    }
}

render($rows, $users);

/* ------------------------------------------------------------------ */
function render(array $rows, array $users = []): void
{
    $bad = array_filter($rows, static fn($r) => !$r['ok']);
    ?><!DOCTYPE html>
<html lang="bg"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Проверка на инсталацията</title>
<style>
 body{font:15px/1.5 "Segoe UI",Arial,sans-serif;background:#f2f5fa;color:#1f2937;margin:0;padding:1.5rem}
 .wrap{max-width:900px;margin:0 auto}
 h1{color:#2b5faa;font-size:1.4rem}
 table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;
       box-shadow:0 1px 3px rgba(16,42,80,.08)}
 td,th{padding:.55rem .7rem;border-bottom:1px solid #e5e9f0;text-align:left;vertical-align:top}
 th{background:#eef3fb;color:#16407d;font-size:.8rem;text-transform:uppercase}
 .ok{color:#15803d;font-weight:700}.no{color:#c62828;font-weight:700}
 .fix{color:#b45309;font-size:.87rem}
 .side{background:#fff;border-radius:8px;padding:.8rem 1rem;margin-top:1rem}
 code{background:#eef2f7;padding:.05rem .3rem;border-radius:4px}
 .top{padding:.8rem 1rem;border-radius:8px;margin-bottom:1rem}
 .good{background:#e9f6ee;color:#15803d}.bad{background:#fdecec;color:#c62828}
</style></head><body><div class="wrap">
<h1>Проверка на инсталацията</h1>
<div class="top <?= $bad ? 'bad' : 'good' ?>">
  <?= $bad ? 'Има ' . count($bad) . ' проблема – вижте редовете в червено и колоната „Какво да направите“.'
           : 'Всичко е наред. Отворете index.php и изтрийте този файл.' ?>
</div>
<table>
  <thead><tr><th>Проверка</th><th>Състояние</th><th>Какво да направите</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['what']) ?></td>
      <td><span class="<?= $r['ok'] ? 'ok' : 'no' ?>"><?= $r['ok'] ? '✓' : '✕' ?></span>
          <?= htmlspecialchars($r['detail']) ?></td>
      <td class="fix"><?= htmlspecialchars($r['fix']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($users): ?>
<div class="side">
  <strong>Потребители в базата</strong> – използвайте някое от тези id за <code>DEV_USER_ID</code>:
  <ul><?php foreach ($users as $u): ?>
    <li><code><?= (int)$u['id'] ?></code> – <?= htmlspecialchars($u['ime'] ?: '(без име)') ?></li>
  <?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="side">
  <strong>Помнете:</strong> в <code>config.php</code> всяка настройка се задава само веднъж.
  Ако трябва да смените стойност, <em>поправете съществуващия ред</em> – добавянето на нов
  <code>define</code> отдолу няма ефект.
  <br><br>Този файл показва вътрешни настройки – <strong>изтрийте го след инсталацията</strong>.
</div>
</div></body></html>
    <?php
}
