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
            $ext = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
            $isXlsx = $ext === 'xlsx';

            $sheets = $isXlsx ? SimpleXlsx::sheetNames($tmp) : [''];
            $rows = []; $head = -1; $sheet = '';
            foreach ($sheets as $i => $name) {
                $cand = SimpleXlsx::rows($tmp, $isXlsx ? $i : 0, $ext);
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
                // редът на колоните няма значение – разпознават се по име
                if ($h === 'клас' || $h === 'класове' || $h === 'класниво')  $map['grade'] = $col;
                elseif ($h === 'предмет' || $h === 'учебенпредмет')          $map['subject'] = $col;
                elseif (str_starts_with($h, 'компетен'))                     $map['title'] = $col;
                elseif ($h === 'код' || $h === 'кодподос')                   $map['code'] = $col;
                elseif (str_starts_with($h, 'източник') || $h === 'дос' || $h === 'уп'
                        || str_starts_with($h, 'раздел') || str_starts_with($h, 'тема')) $map['source'] = $col;
            }
            $get = static fn(array $r, string $k) => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';

            /* --------------------------------------------------------------
             * Професиите/специалностите се избират ТУК, не се четат от файла.
             * Може да са няколко: същият списък се записва за всяка от тях.
             * Празен избор = „за всички“ (общообразователните предмети).
             * ------------------------------------------------------------ */
            $picked = array_values(array_unique(array_map('intval', (array)($_POST['program_ids'] ?? []))));
            $forAll = !empty($_POST['for_all']) || !$picked;

            $targets = [];                       // [id|null => етикет]
            if ($forAll) {
                $targets[] = ['id' => null, 'label' => 'всички професии и специалности'];
            } else {
                foreach ($picked as $pid) {
                    $prog = one('SELECT * FROM mo_programs WHERE id = ?', [$pid]);
                    if (!$prog) throw new RuntimeException('Избрана е несъществуваща професия или специалност.');
                    $targets[] = ['id' => (int)$prog['id'], 'label' => program_label($prog)];
                }
            }

            $log = ['ok' => 0, 'skip' => 0, 'dupes' => 0, 'rows' => 0, 'subjects' => [],
                    'errors' => [], 'pairs' => [], 'targets' => array_column($targets, 'label')];
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
                    $log['errors'][] = "Ред $no: непълни данни (клас/предмет/компетенция).";
                    $log['skip']++;
                    continue;
                }

                $s = one('SELECT id FROM mo_subjects WHERE name = ?', [$subj]);
                if (!$s) {
                    $log['subjects'][] = $subj;
                    if (!$dry) { q('INSERT INTO mo_subjects (name) VALUES (?)', [$subj]); $s = ['id' => (int)db()->lastInsertId()]; }
                    else $s = ['id' => 0];
                }
                $log['rows']++;

                /* един ред от файла се записва за всяка избрана професия */
                foreach ($targets as $tg) {
                    $progId = $tg['id'];

                    if (!$replace && (int)$s['id'] > 0) {
                        $dup = one('SELECT id FROM mo_competencies
                                    WHERE subject_id = ? AND grade_level = ? AND (program_id <=> ?)
                                      AND title = ? AND is_active = 1',
                                   [$s['id'], $level, $progId, mb_substr($title, 0, 600)]);
                        if ($dup) { $log['dupes']++; continue; }
                    }

                    $key = $s['id'] . '-' . $level . '-' . ($progId ?? 0);
                    if ($replace && !$dry && !isset($order[$key])) {
                        q('UPDATE mo_competencies SET is_active = 0
                           WHERE subject_id = ? AND grade_level = ? AND (program_id <=> ?)',
                          [$s['id'], $level, $progId]);
                    }
                    $order[$key] = ($order[$key] ?? 0) + 10;

                    $label = $subj . ' · ' . $level . ' клас · ' . $tg['label'];
                    $log['pairs'][$label] = ($log['pairs'][$label] ?? 0) + 1;

                    if (!$dry) {
                        q('INSERT INTO mo_competencies (subject_id, grade_level, program_id, code, title, source, sort_order)
                           VALUES (?,?,?,?,?,?,?)',
                          [$s['id'], $level, $progId, $get($r, 'code') ?: null,
                           mb_substr($title, 0, 600), $get($r, 'source') ?: null, $order[$key]]);
                    }
                    $log['ok']++;
                }
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

    /* ---------------- ръчно добавяне ---------------- */
    elseif ($act === 'add') {
        $subj = trim((string)($_POST['subject'] ?? ''));
        $s = one('SELECT id FROM mo_subjects WHERE name = ?', [$subj]);
        if (!$s && $subj !== '') { q('INSERT INTO mo_subjects (name) VALUES (?)', [$subj]); $s = ['id' => (int)db()->lastInsertId()]; }
        if ($s && trim((string)($_POST['title'] ?? '')) !== '') {
            q('INSERT INTO mo_competencies (subject_id, grade_level, program_id, code, title, source, sort_order)
               VALUES (?,?,?,?,?,?,?)',
              [$s['id'], (int)($_POST['grade_level'] ?? 8),
               ($_POST['program_id'] ?? '') !== '' ? (int)$_POST['program_id'] : null,
               trim((string)($_POST['code'] ?? '')) ?: null, trim((string)($_POST['title'] ?? '')),
               trim((string)($_POST['source'] ?? '')) ?: null, (int)($_POST['sort_order'] ?? 0)]);
            flash('Компетентността е добавена.');
        } else {
            flash('Попълнете предмет и текст на компетентността.', 'err');
        }
        redirect(base_url('pages/admin_competencies.php?s=' . (int)($s['id'] ?? 0)));
    }

    /* ---------------- редакция и пренасочване ---------------- */
    elseif ($act === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $subj = trim((string)($_POST['subject'] ?? ''));
        $s = one('SELECT id FROM mo_subjects WHERE name = ?', [$subj]);
        if (!$s && $subj !== '') { q('INSERT INTO mo_subjects (name) VALUES (?)', [$subj]); $s = ['id' => (int)db()->lastInsertId()]; }
        if ($id && $s) {
            q('UPDATE mo_competencies SET subject_id=?, grade_level=?, program_id=?, code=?, title=?, source=?
               WHERE id=?',
              [$s['id'], (int)($_POST['grade_level'] ?? 8),
               ($_POST['program_id'] ?? '') !== '' ? (int)$_POST['program_id'] : null,
               trim((string)($_POST['code'] ?? '')) ?: null,
               mb_substr(trim((string)($_POST['title'] ?? '')), 0, 600),
               trim((string)($_POST['source'] ?? '')) ?: null, $id]);
            flash('Компетентността е обновена. Съществуващите отметки по нея се запазват.');
        }
        redirect(base_url('pages/admin_competencies.php?s=' . (int)($s['id'] ?? 0)));
    }

    /* ---------------- изтриване ---------------- */
    elseif ($act === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $sid = (int)($_POST['subject_id'] ?? 0);
        $uses = (int)(one('SELECT COUNT(*) n FROM mo_entry_competencies WHERE competency_id = ?', [$id])['n'] ?? 0);
        if ($uses > 0 && empty($_POST['force'])) {
            flash("Компетентността е използвана в $uses анализа. Скрийте я или отметнете „изтрий заедно с отметките“.", 'err');
        } else {
            q('DELETE FROM mo_competencies WHERE id = ?', [$id]);
            flash($uses ? "Компетентността и $uses отметки по нея са изтрити." : 'Компетентността е изтрита.');
        }
        redirect(base_url('pages/admin_competencies.php?s=' . $sid));
    }

    elseif ($act === 'toggle') {
        q('UPDATE mo_competencies SET is_active = 1 - is_active WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        flash('Статусът е променен. Скритите не се показват на учителите, но старите отметки остават.');
        redirect(base_url('pages/admin_competencies.php?s=' . (int)($_POST['subject_id'] ?? 0)));
    }
}

$subjects = all('SELECT s.*, (SELECT COUNT(*) FROM mo_competencies k WHERE k.subject_id = s.id AND k.is_active=1) n
                 FROM mo_subjects s ORDER BY s.name');
$profs = programs('profession');
$specs = programs('specialty');
$sid   = (int)($_GET['s'] ?? ($subjects[0]['id'] ?? 0));
$grade = (int)($_GET['g'] ?? 0);
$edit  = (int)($_GET['edit'] ?? 0);

$list = $sid ? all('SELECT k.*, pr.name AS program_name, pr.kind AS program_kind,
                           (SELECT COUNT(*) FROM mo_entry_competencies x WHERE x.competency_id = k.id) uses
                    FROM mo_competencies k
                    LEFT JOIN mo_programs pr ON pr.id = k.program_id
                    WHERE k.subject_id = ?' . ($grade ? ' AND k.grade_level = ' . $grade : '') . '
                    ORDER BY k.grade_level, k.sort_order, k.id', [$sid]) : [];

header_html('Компетентности', 'adm_comp');
section_title('Компетентности по ДОС и учебни програми');
?>

<div class="panel">
  <h2>Зареждане от Excel</h2>
  <p class="muted small">Колони във файла: <strong>Клас · Предмет · Компетенция</strong>,
     по избор <strong>Код · Източник</strong>. Редът на колоните няма значение – разпознават се
     по име. Професията <strong>не се чете от файла</strong>: отбелязвате една или няколко тук и
     същият списък се записва за всяка от тях. Така предмет като Електротехника, който се учи в
     седем професии, се зарежда <strong>веднъж</strong>.</p>

  <?php if (!class_exists('ZipArchive')): ?>
    <div class="flash warn">Разширението <strong>zip</strong> на PHP е изключено – приемат се само .csv файлове.</div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <fieldset class="picker-group">
      <legend>За кои професии и специалности</legend>

      <label class="chk all-toggle">
        <input type="checkbox" name="for_all" value="1" id="forAll">
        <span><strong>За всички</strong> – общообразователни предмети, важащи навсякъде</span>
      </label>

      <div class="prog-pick" id="progPick">
        <div class="prog-col">
          <div class="prog-head">
            Професии <span class="muted">(нова класификация)</span>
            <button type="button" class="btn small ghost" data-pick="profession">Избери всички</button>
          </div>
          <?php foreach ($profs as $p): ?>
            <label class="chk">
              <input type="checkbox" name="program_ids[]" value="<?= (int)$p['id'] ?>" data-kind="profession">
              <span><?= e($p['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="prog-col">
          <div class="prog-head">
            Специалности <span class="muted">(стара класификация)</span>
            <button type="button" class="btn small ghost" data-pick="specialty">Избери всички</button>
          </div>
          <?php foreach ($specs as $p): ?>
            <label class="chk">
              <input type="checkbox" name="program_ids[]" value="<?= (int)$p['id'] ?>" data-kind="specialty">
              <span><?= e($p['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="muted small" id="pickCount">Не е избрана нито една – файлът ще се запише „за всички“.</p>
    </fieldset>

    <label>Файл <input type="file" name="file" accept="<?= class_exists('ZipArchive') ? '.xlsx,.csv' : '.csv' ?>" required></label>
    <label class="small"><input type="checkbox" name="dry_run" value="1" checked> Проба без запис (препоръчително)</label>
    <label class="small"><input type="checkbox" name="replace" value="1">
      Замени съществуващите за същия предмет, клас и специалност</label>
    <p class="muted small">Без отметката вече съществуващите компетентности се пропускат,
       за да не се дублират при повторно зареждане на същия файл.</p>
    <div class="actions">
      <button class="btn primary" type="submit">Зареди</button>
      <a class="btn ghost" href="<?= base_url('assets/obrazec_kompetentnosti.csv') ?>" download>Изтегли образец</a>
    </div>
  </form>
</div>

<?php if ($report): $L = $report['log']; ?>
<div class="panel">
  <h2><?= $report['dry'] ? 'Проба – нищо не е записано' : 'Зареждането приключи' ?></h2>
  <p class="muted small">Лист: <strong><?= e($report['sheet'] ?: 'първият') ?></strong>, заглавен ред: <?= (int)$report['head'] ?>.</p>
  <ul class="small">
    <li>Редове от файла: <strong><?= (int)($L['rows'] ?? 0) ?></strong></li>
    <li>Записани компетентности: <strong><?= (int)$L['ok'] ?></strong>
        <?php if (count($L['targets'] ?? []) > 1): ?>
          <span class="muted">(<?= (int)($L['rows'] ?? 0) ?> реда × <?= count($L['targets']) ?> професии)</span>
        <?php endif; ?></li>
    <li>Пропуснати заради непълни данни: <strong><?= (int)$L['skip'] ?></strong></li>
    <li>Пропуснати като вече съществуващи: <strong><?= (int)($L['dupes'] ?? 0) ?></strong></li>
    <li>Нови предмети: <?= $L['subjects'] ? e(implode(', ', array_unique($L['subjects']))) : '—' ?></li>
    <li>Записано за: <?= e(implode(' · ', $L['targets'] ?? [])) ?></li>
  </ul>
  <?php if ($L['pairs']): ?>
    <table class="grid">
      <thead><tr><th>Предмет · клас · професия/специалност</th><th>Компетентности</th></tr></thead>
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
      <label>Професия / специалност
        <select name="program_id">
          <option value="">за всички</option>
          <optgroup label="Професии">
            <?php foreach ($profs as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?>
          </optgroup>
          <optgroup label="Специалности">
            <?php foreach ($specs as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?>
          </optgroup>
        </select>
      </label>
      <label>Код<input type="text" name="code" placeholder="ДОС 2.1"></label>
      <label>Източник<input type="text" name="source" placeholder="УП, раздел II"></label>
    </div>
    <label>Компетентност<input type="text" name="title" required maxlength="600"></label>
    <button class="btn primary" type="submit">Добави</button>
  </form>
</div>

<div class="panel">
  <h2>Въведени компетентности</h2>
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
    <thead><tr><th>Клас</th><th>Професия / специалност</th><th>Код</th><th>Компетентност</th>
               <th>Ползвана</th><th>Статус</th><th>Действия</th></tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <?php if ($edit === (int)$c['id']): ?>
      <tr>
        <td colspan="7">
          <form method="post" class="fields">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <label>Предмет<input type="text" name="subject" list="subjlist"
              value="<?= e(one('SELECT name FROM mo_subjects WHERE id=?', [$c['subject_id']])['name'] ?? '') ?>"></label>
            <label>Клас<input type="number" name="grade_level" min="1" max="12" value="<?= (int)$c['grade_level'] ?>"></label>
            <label>Професия / специалност
              <select name="program_id">
                <option value="">за всички</option>
                <optgroup label="Професии">
                  <?php foreach ($profs as $sp): ?>
                    <option value="<?= (int)$sp['id'] ?>" <?= (int)$c['program_id'] === (int)$sp['id'] ? 'selected' : '' ?>>
                      <?= e($sp['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <optgroup label="Специалности">
                  <?php foreach ($specs as $sp): ?>
                    <option value="<?= (int)$sp['id'] ?>" <?= (int)$c['program_id'] === (int)$sp['id'] ? 'selected' : '' ?>>
                      <?= e($sp['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              </select>
            </label>
            <label>Код<input type="text" name="code" value="<?= e($c['code']) ?>"></label>
            <label>Източник<input type="text" name="source" value="<?= e($c['source']) ?>"></label>
            <label style="grid-column:1/-1">Компетентност
              <input type="text" name="title" value="<?= e($c['title']) ?>" maxlength="600"></label>
            <div class="actions" style="grid-column:1/-1">
              <button class="btn primary small" type="submit">Запази</button>
              <a class="btn ghost small" href="<?= base_url('pages/admin_competencies.php?s=' . $sid . '&g=' . $grade) ?>">Откажи</a>
            </div>
          </form>
        </td>
      </tr>
      <?php else: ?>
      <tr class="<?= $c['is_active'] ? '' : 'off' ?>">
        <td><?= (int)$c['grade_level'] ?></td>
        <td class="small"><?= e($c['program_name'] ?: 'всички') ?>
          <?php if ($c['program_kind']): ?><br><span class="badge <?= $c['program_kind'] === 'profession' ? 'ok' : '' ?>">
            <?= e(PROGRAM_KINDS[$c['program_kind']]) ?></span><?php endif; ?></td>
        <td class="small"><?= e($c['code']) ?></td>
        <td><?= e($c['title']) ?><?php if ($c['source']): ?><br><small class="muted"><?= e($c['source']) ?></small><?php endif; ?></td>
        <td class="small"><?= (int)$c['uses'] ?></td>
        <td><?= $c['is_active'] ? '<span class="badge ok">активна</span>' : '<span class="badge">скрита</span>' ?></td>
        <td class="acts">
          <a class="btn small" href="<?= base_url('pages/admin_competencies.php?s=' . $sid . '&g=' . $grade . '&edit=' . (int)$c['id']) ?>">Редакция</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="subject_id" value="<?= (int)$sid ?>">
            <button class="btn small ghost" type="submit"><?= $c['is_active'] ? 'Скрий' : 'Върни' ?></button>
          </form>
          <form method="post" style="display:inline" data-danger
                data-confirm-title="Изтриване на компетентност" data-confirm-ok="Изтрий"
                data-confirm="<?= (int)$c['uses']
                    ? 'Компетентността е отбелязана в ' . (int)$c['uses'] . ' анализа. Изтриването маха и тези отметки. За да я запазите в историята, използвайте „Скрий“.'
                    : 'Компетентността ще бъде изтрита.' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="subject_id" value="<?= (int)$sid ?>">
            <?php if ((int)$c['uses']): ?><input type="hidden" name="force" value="1"><?php endif; ?>
            <button class="btn small danger" type="submit">Изтрий</button>
          </form>
        </td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="7" class="muted">Няма въведени компетентности.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="muted small">„Редакция“ променя и предмета, класа или професията – така се
     пренасочва погрешно заредена компетентност, без да се губят отметките по нея.</p>
</div>
<script>
(function () {
  var all = document.getElementById('forAll');
  var box = document.getElementById('progPick');
  var cnt = document.getElementById('pickCount');
  if (!box) return;

  function boxes() { return box.querySelectorAll('input[name="program_ids[]"]'); }

  function refresh() {
    var n = box.querySelectorAll('input[name="program_ids[]"]:checked').length;
    box.classList.toggle('disabled', all.checked);
    boxes().forEach(function (b) { b.disabled = all.checked; });
    if (all.checked) {
      cnt.textContent = 'Файлът ще се запише веднъж – за всички професии и специалности.';
    } else if (n === 0) {
      cnt.textContent = 'Не е избрана нито една – файлът ще се запише „за всички“.';
    } else {
      cnt.textContent = 'Избрани: ' + n + '. Всеки ред от файла ще се запише ' + n + ' пъти – по веднъж за всяка.';
    }
  }

  all.addEventListener('change', refresh);
  box.addEventListener('change', refresh);
  box.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-pick]');
    if (!btn) return;
    ev.preventDefault();
    var kind = btn.getAttribute('data-pick');
    var list = box.querySelectorAll('input[data-kind="' + kind + '"]');
    var allOn = Array.prototype.every.call(list, function (b) { return b.checked; });
    list.forEach(function (b) { b.checked = !allOn; });
    btn.textContent = allOn ? 'Избери всички' : 'Изчисти';
    refresh();
  });
  refresh();
})();
</script>
<?php footer_html(); ?>
