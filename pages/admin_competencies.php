<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../lib/SimpleXlsx.php';

$u = require_role('admin');

function norm_head(string $s): string
{
    return preg_replace('/[^а-яa-z0-9]/u', '', mb_strtolower(trim($s))) ?? '';
}

$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    /* ---------------- импорт от таблица ---------------- */
    if ($act === 'import' && isset($_FILES['file'])) {
        $dry     = !empty($_POST['dry_run']);
        $replace = !empty($_POST['replace']);
        try {
            if (($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Файлът не е качен.');
            $tmp = $_FILES['file']['tmp_name'];
            $isXlsx = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION)) === 'xlsx';

            // търсене на заглавния ред във всички листове
            $sheets = $isXlsx ? SimpleXlsx::sheetNames($tmp) : [''];
            $rows = []; $head = -1; $sheet = '';
            foreach ($sheets as $i => $name) {
                $cand = SimpleXlsx::rows($tmp, $isXlsx ? $i : 0);
                foreach (array_slice($cand, 0, 25, true) as $r => $line) {
                    $n = array_map('norm_head', $line);
                    $hasClass = in_array('клас', $n, true) || in_array('класове', $n, true);
                    $hasSubj  = in_array('предмет', $n, true) || in_array('учебенпредмет', $n, true);
                    $hasComp  = (bool)array_filter($n, static fn($x) => str_starts_with($x, 'компетен'));
                    if ($hasClass && $hasSubj && $hasComp) { $rows = $cand; $head = $r; $sheet = (string)$name; break 2; }
                }
            }
            if ($head < 0) {
                throw new RuntimeException('Не намирам заглавен ред с колони „Клас“, „Предмет“ и „Компетенция“. '
                    . 'Изтеглете образеца по-долу.');
            }

            $map = [];
            foreach ($rows[$head] as $col => $title) {
                $h = norm_head((string)$title);
                if ($h === 'клас' || $h === 'класове')                 $map['grade']   = $col;
                elseif ($h === 'предмет' || $h === 'учебенпредмет')    $map['subject'] = $col;
                elseif (str_starts_with($h, 'компетен'))               $map['title']   = $col;
                elseif ($h === 'код')                                  $map['code']    = $col;
                elseif (str_starts_with($h, 'източник') || $h === 'дос' || $h === 'уп') $map['source'] = $col;
            }

            $get = static fn(array $r, string $k) => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';

            $log = ['ok' => 0, 'skip' => 0, 'subjects' => [], 'errors' => [], 'pairs' => []];
            if (!$dry) db()->beginTransaction();

            $order = [];
            foreach (array_slice($rows, $head + 1) as $ln => $r) {
                $no    = $head + 2 + $ln;
                $grade = $get($r, 'grade');
                $subj  = $get($r, 'subject');
                $title = $get($r, 'title');
                if ($grade === '' && $subj === '' && $title === '') continue;
                if (mb_stripos($grade, 'пример') === 0 || mb_stripos($subj, 'пример') === 0) continue;

                preg_match('/\d+/', $grade, $mm);
                $level = (int)($mm[0] ?? 0);
                if ($level < 1 || $level > 12 || $subj === '' || $title === '') {
                    $log['errors'][] = "Ред $no: непълни или неверни данни (клас/предмет/компетенция).";
                    $log['skip']++;
                    continue;
                }

                $s = one('SELECT id FROM mo_subjects WHERE name = ?', [$subj]);
                if (!$s) {
                    $log['subjects'][] = $subj;
                    if (!$dry) { q('INSERT INTO mo_subjects (name) VALUES (?)', [$subj]); $s = ['id' => (int)db()->lastInsertId()]; }
                    else $s = ['id' => 0];
                }

                $key = $s['id'] . '-' . $level;
                if ($replace && !$dry && !isset($order[$key])) {
                    // старите се скриват, а не се трият – историята на отметките остава
                    q('UPDATE mo_competencies SET is_active = 0 WHERE subject_id = ? AND grade_level = ?', [$s['id'], $level]);
                }
                $order[$key] = ($order[$key] ?? 0) + 10;
                $log['pairs'][$subj . ' · ' . $level . ' клас'] = ($log['pairs'][$subj . ' · ' . $level . ' клас'] ?? 0) + 1;

                if (!$dry) {
                    q('INSERT INTO mo_competencies (subject_id, grade_level, code, title, source, sort_order)
                       VALUES (?,?,?,?,?,?)',
                      [$s['id'], $level, $get($r, 'code') ?: null, mb_substr($title, 0, 600),
                       $get($r, 'source') ?: null, $order[$key]]);
                }
                $log['ok']++;
            }

            if (!$dry) {
                q('INSERT INTO mo_import_log (user_id, filename, rows_ok, rows_error, details) VALUES (?,?,?,?,?)',
                  [$u['id'], $_FILES['file']['name'], $log['ok'], $log['skip'], json_encode($log, JSON_UNESCAPED_UNICODE)]);
                db()->commit();
            }
            $report = ['log' => $log, 'dry' => $dry, 'sheet' => $sheet, 'head' => $head + 1];
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            flash('Импортът е прекратен: ' . e($ex->getMessage()), 'err');
        }
    }

    /* ---------------- ръчно добавяне / скриване ---------------- */
    elseif ($act === 'add') {
        $subj = trim((string)($_POST['subject'] ?? ''));
        $s = one('SELECT id FROM mo_subjects WHERE name = ?', [$subj]);
        if (!$s && $subj !== '') { q('INSERT INTO mo_subjects (name) VALUES (?)', [$subj]); $s = ['id' => (int)db()->lastInsertId()]; }
        if ($s && trim((string)($_POST['title'] ?? '')) !== '') {
            q('INSERT INTO mo_competencies (subject_id, grade_level, code, title, source, sort_order)
               VALUES (?,?,?,?,?,?)',
              [$s['id'], (int)($_POST['grade_level'] ?? 8), trim((string)($_POST['code'] ?? '')) ?: null,
               trim((string)($_POST['title'] ?? '')), trim((string)($_POST['source'] ?? '')) ?: null,
               (int)($_POST['sort_order'] ?? 0)]);
            flash('Компетентността е добавена.');
        } else {
            flash('Попълнете предмет и текст на компетентността.', 'err');
        }
        redirect(base_url('pages/admin_competencies.php?s=' . (int)($s['id'] ?? 0)));
    } elseif ($act === 'toggle') {
        q('UPDATE mo_competencies SET is_active = 1 - is_active WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        flash('Статусът е променен. Скритите не се показват на учителите, но старите отметки остават.');
        redirect(base_url('pages/admin_competencies.php?s=' . (int)($_POST['subject_id'] ?? 0)));
    }
}

$subjects = all('SELECT s.*, (SELECT COUNT(*) FROM mo_competencies k WHERE k.subject_id = s.id AND k.is_active=1) n
                 FROM mo_subjects s ORDER BY s.name');
$sid   = (int)($_GET['s'] ?? ($subjects[0]['id'] ?? 0));
$grade = (int)($_GET['g'] ?? 0);

$list = $sid ? all('SELECT k.*, (SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.competency_id = k.id) uses
                    FROM mo_competencies k
                    WHERE k.subject_id = ?' . ($grade ? ' AND k.grade_level = ' . $grade : '') . '
                    ORDER BY k.grade_level, k.sort_order, k.id', [$sid]) : [];

header_html('Компетентности', 'adm_comp');
section_title('Компетентности по ДОС и учебни програми');
?>

<div class="panel">
  <h2>Зареждане от Excel</h2>
  <p class="muted small">Таблица с колони <strong>Клас · Предмет · Компетенция</strong> (задължителни)
     и по избор <strong>Код · Източник</strong>. Един ред = една компетентност.
     Класът може да е „8“ или „8 клас“ – взема се числото.</p>

  <?php if (!class_exists('ZipArchive')): ?>
    <div class="flash warn">Разширението <strong>zip</strong> на PHP е изключено, затова се приемат само .csv файлове.</div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <label>Файл <input type="file" name="file" accept="<?= class_exists('ZipArchive') ? '.xlsx,.csv' : '.csv' ?>" required></label>
    <label class="small"><input type="checkbox" name="dry_run" value="1" checked> Проба без запис (препоръчително)</label>
    <label class="small"><input type="checkbox" name="replace" value="1">
      Замени съществуващите за същия предмет и клас
      <span class="muted">(старите се скриват, отметките по тях се пазят)</span></label>
    <div class="actions">
      <button class="btn primary" type="submit">Зареди</button>
      <a class="btn ghost" href="<?= base_url('assets/obrazec_kompetentnosti.csv') ?>" download>Изтегли образец</a>
    </div>
  </form>
</div>

<?php if ($report): $L = $report['log']; ?>
<div class="panel <?= $report['dry'] ? '' : '' ?>">
  <h2><?= $report['dry'] ? 'Проба – нищо не е записано' : 'Зареждането приключи' ?></h2>
  <p class="muted small">Лист: <strong><?= e($report['sheet'] ?: 'първият') ?></strong>, заглавен ред: <?= (int)$report['head'] ?>.</p>
  <ul class="small">
    <li>Приети редове: <strong><?= (int)$L['ok'] ?></strong></li>
    <li>Пропуснати: <strong><?= (int)$L['skip'] ?></strong></li>
    <li>Нови предмети: <?= $L['subjects'] ? e(implode(', ', array_unique($L['subjects']))) : '—' ?></li>
  </ul>
  <?php if ($L['pairs']): ?>
    <table class="grid">
      <thead><tr><th>Предмет и клас</th><th>Компетентности</th></tr></thead>
      <tbody><?php foreach ($L['pairs'] as $k => $n): ?>
        <tr><td><?= e((string)$k) ?></td><td><?= (int)$n ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
  <?php if ($L['errors']): ?>
    <h3>Проблеми</h3>
    <ul class="small danger"><?php foreach (array_slice($L['errors'], 0, 40) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Ръчно добавяне</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="fields">
      <label>Предмет<input type="text" name="subject" list="subjlist" required
        value="<?= e(one('SELECT name FROM mo_subjects WHERE id=?', [$sid])['name'] ?? '') ?>"></label>
      <datalist id="subjlist">
        <?php foreach ($subjects as $s): ?><option value="<?= e($s['name']) ?>"></option><?php endforeach; ?>
      </datalist>
      <label>Клас<input type="number" name="grade_level" min="1" max="12" value="8" required></label>
      <label>Код<input type="text" name="code" placeholder="ДОС 2.1"></label>
      <label>Източник<input type="text" name="source" placeholder="УП, раздел II"></label>
    </div>
    <label>Компетентност<input type="text" name="title" required maxlength="600"></label>
    <button class="btn primary" type="submit">Добави</button>
  </form>
</div>

<div class="panel">
  <div class="sec-title"><h1 style="font-size:1.05rem">Въведени компетентности</h1></div>
  <form class="picker" method="get">
    <label>Предмет
      <select name="s" onchange="this.form.submit()">
        <?php foreach ($subjects as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $sid === (int)$s['id'] ? 'selected' : '' ?>>
            <?= e($s['name']) ?> (<?= (int)$s['n'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Клас
      <select name="g" onchange="this.form.submit()">
        <option value="0">всички</option>
        <?php for ($i = 8; $i <= 12; $i++): ?>
          <option value="<?= $i ?>" <?= $grade === $i ? 'selected' : '' ?>><?= $i ?> клас</option>
        <?php endfor; ?>
      </select>
    </label>
  </form>

  <table class="grid">
    <thead><tr><th>Клас</th><th>Код</th><th>Компетентност</th><th>Източник</th><th>Ползвана</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <tr class="<?= $c['is_active'] ? '' : 'off' ?>">
        <td><?= (int)$c['grade_level'] ?></td>
        <td class="small"><?= e($c['code']) ?></td>
        <td><?= e($c['title']) ?></td>
        <td class="small muted"><?= e($c['source']) ?></td>
        <td class="small"><?= (int)$c['uses'] ?> анализа</td>
        <td><?= $c['is_active'] ? '<span class="badge ok">активна</span>' : '<span class="badge">скрита</span>' ?></td>
        <td>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="subject_id" value="<?= (int)$sid ?>">
            <button class="btn small ghost" type="submit"><?= $c['is_active'] ? 'Скрий' : 'Върни' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="7" class="muted">Няма въведени компетентности.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php footer_html(); ?>
