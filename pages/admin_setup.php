<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('admin');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid = current_year_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    /* ---------------- учебни години ---------------- */
    if ($act === 'year_add') {
        $label = trim((string)($_POST['label'] ?? ''));
        if (!preg_match('#^\d{4}[-/]\d{4}$#', $label)) {
            flash('Форматът е 2027-2028.', 'err');
        } elseif (one('SELECT id FROM mo_years WHERE label = ?', [$label])) {
            flash('Тази учебна година вече съществува.', 'err');
        } else {
            q('INSERT INTO mo_years (label) VALUES (?)', [$label]);
            $new = (int)db()->lastInsertId();

            /* прехвърляне на паралелките нагоре */
            if (!empty($_POST['promote_from'])) {
                [$made, $grad] = promote_classes((int)$_POST['promote_from'], $new);
                flash("Учебната година е добавена. Прехвърлени са $made паралелки с един клас нагоре"
                    . ($grad ? ", а $grad дванадесети класа завършиха." : '.')
                    . ' Добавете новите осми класове и им задайте професия.');
            } else {
                flash('Учебната година е добавена.');
            }
        }
    } elseif ($act === 'year_activate') {
        q('UPDATE mo_years SET is_active = 0');
        q('UPDATE mo_years SET is_active = 1 WHERE id = ?', [(int)$_POST['id']]);
        $_SESSION['year_id'] = (int)$_POST['id'];
        flash('Годината е активна.');
    } elseif ($act === 'promote') {
        $from = (int)($_POST['from_year'] ?? 0);
        $to   = (int)($_POST['to_year'] ?? 0);
        if (!$from || !$to || $from === $to) {
            flash('Изберете две различни учебни години.', 'err');
        } else {
            [$made, $grad] = promote_classes($from, $to);
            flash("Прехвърлени са $made паралелки. Дванадесетите класове ($grad) завършват и не се пренасят.");
        }
    }

    /* ---------------- професии и специалности ---------------- */
    elseif ($act === 'prog_add') {
        $n = trim((string)($_POST['name'] ?? ''));
        $kind = (string)($_POST['kind'] ?? 'profession');
        if ($n !== '' && isset(PROGRAM_KINDS[$kind])) {
            q('INSERT IGNORE INTO mo_programs (kind, name, note) VALUES (?,?,?)',
              [$kind, $n, trim((string)($_POST['note'] ?? '')) ?: null]);
            flash('Записано.');
        }
    } elseif ($act === 'prog_toggle') {
        q('UPDATE mo_programs SET is_active = 1 - is_active WHERE id = ?', [(int)$_POST['id']]);
    }

    /* ---------------- паралелки ---------------- */
    elseif ($act === 'class_prog') {
        $pid = ($_POST['program_id'] ?? '') !== '' ? (int)$_POST['program_id'] : null;
        q('UPDATE mo_classes SET program_id = ? WHERE id = ?', [$pid, (int)$_POST['id']]);
        flash('Записано.');
    } elseif ($act === 'class_prog_bulk') {
        $pid = ($_POST['program_id'] ?? '') !== '' ? (int)$_POST['program_id'] : null;
        $ids = array_map('intval', (array)($_POST['classes'] ?? []));
        if ($ids) {
            $st = db()->prepare('UPDATE mo_classes SET program_id = ? WHERE id = ?');
            foreach ($ids as $cid) $st->execute([$pid, $cid]);
            flash('Записана е програмата на ' . count($ids) . ' паралелки.');
        } else {
            flash('Изберете поне една паралелка.', 'err');
        }
    } elseif ($act === 'class_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        preg_match('/\d+/', $name, $m);
        $lvl = (int)($m[0] ?? 0);
        $letter = trim(preg_replace('/[0-9\s]/u', '', $name) ?? '');
        if ($lvl >= 1 && $lvl <= 12 && $letter !== '' && $yid) {
            q('INSERT IGNORE INTO mo_classes (name, grade_level, letter, year_id, program_id)
               VALUES (?,?,?,?,?)',
              [$name, $lvl, $letter, $yid,
               ($_POST['program_id'] ?? '') !== '' ? (int)$_POST['program_id'] : null]);
            flash('Паралелката е добавена.');
        } else {
            flash('Въведете паралелка във вид „8А“.', 'err');
        }
    } elseif ($act === 'class_toggle') {
        q('UPDATE mo_classes SET is_active = 1 - is_active WHERE id = ?', [(int)$_POST['id']]);
    }

    redirect(base_url('pages/admin_setup.php'));
}

$years = all('SELECT y.*,
   (SELECT COUNT(*) FROM mo_classes c WHERE c.year_id = y.id) n_classes,
   (SELECT COUNT(*) FROM mo_entries e WHERE e.year_id = y.id) n_entries
   FROM mo_years y ORDER BY y.label DESC');

$classes = all('SELECT c.*, p.name AS program_name, p.kind AS program_kind,
                       (SELECT COUNT(*) FROM mo_entries e WHERE e.class_id = c.id) n
                FROM mo_classes c LEFT JOIN mo_programs p ON p.id = c.program_id
                WHERE c.year_id = ? ORDER BY c.grade_level, c.letter', [$yid]);

$progs = all('SELECT p.*,
                (SELECT COUNT(*) FROM mo_classes c WHERE c.program_id = p.id) n_classes,
                (SELECT COUNT(*) FROM mo_competencies k WHERE k.program_id = p.id AND k.is_active=1) n_comp
              FROM mo_programs p ORDER BY p.kind DESC, p.name');
$profs = array_values(array_filter($progs, static fn($p) => $p['kind'] === 'profession' && $p['is_active']));
$specs = array_values(array_filter($progs, static fn($p) => $p['kind'] === 'specialty'  && $p['is_active']));


/** Падащо меню: за 8 клас само професии, за останалите и двете. */
function program_select(string $name, ?int $current, int $grade, array $profs, array $specs, string $style = ''): void
{
    echo '<select name="' . e($name) . '"' . ($style ? ' style="' . e($style) . '"' : '') . '>';
    echo '<option value="">– няма –</option>';
    echo '<optgroup label="Професии (нова класификация)">';
    foreach ($profs as $p) {
        echo '<option value="' . (int)$p['id'] . '"' . ($current === (int)$p['id'] ? ' selected' : '') . '>'
           . e($p['name']) . '</option>';
    }
    echo '</optgroup>';
    if ($grade !== 8) {
        echo '<optgroup label="Специалности (стара класификация)">';
        foreach ($specs as $p) {
            echo '<option value="' . (int)$p['id'] . '"' . ($current === (int)$p['id'] ? ' selected' : '') . '>'
               . e($p['name']) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
}

header_html('Години и паралелки', 'adm_setup');
section_title('Учебни години, паралелки, професии и предмети');
?>

<div class="panel">
  <h2>Учебни години</h2>
  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="year_add">
    <label>Нова година<input type="text" name="label" placeholder="2027-2028" required></label>
    <label>Прехвърли паралелките от
      <select name="promote_from">
        <option value="">– не прехвърляй –</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y['id'] ?>" <?= $y['is_active'] ? 'selected' : '' ?>><?= e($y['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn primary" type="submit">Добави</button>
  </form>
  <p class="muted small">При прехвърляне 8А става 9А <strong>със същата професия</strong>, 9Б става 10Б
     и така нататък. Дванадесетите класове завършват и не се пренасят. За новата година остава
     да въведете само осмите класове и да им зададете професия.</p>

  <table class="grid">
    <thead><tr><th>Година</th><th>Паралелки</th><th>Анализи</th><th>Активна</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($years as $y): ?>
      <tr>
        <td><strong><?= e($y['label']) ?></strong></td>
        <td><?= (int)$y['n_classes'] ?></td>
        <td><?= (int)$y['n_entries'] ?></td>
        <td><?= $y['is_active'] ? '<span class="badge ok">да</span>' : '' ?></td>
        <td><?php if (!$y['is_active']): ?>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="year_activate">
            <input type="hidden" name="id" value="<?= (int)$y['id'] ?>">
            <button class="btn small" type="submit">Направи активна</button>
          </form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h3>Допълнително прехвърляне</h3>
  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="promote">
    <label>От година
      <select name="from_year">
        <?php foreach ($years as $y): ?><option value="<?= (int)$y['id'] ?>"><?= e($y['label']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Към година
      <select name="to_year">
        <?php foreach ($years as $y): ?><option value="<?= (int)$y['id'] ?>"><?= e($y['label']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Прехвърли</button>
  </form>
  <p class="muted small">Прехвърлянето не пипа съществуващите паралелки в целевата година
     и не докосва миналите анализи.</p>
</div>

<div class="panel">
  <h2>Професии и специалности</h2>
  <p class="muted small">
    <strong>Професиите</strong> са новата класификация на МОН – по тях тръгват осмите класове.
    <strong>Специалностите</strong> са старият термин и важат за випуските, започнали по него
    (за 2026-2027 това са 9-12 клас). При прехвърляне нагоре всяка паралелка запазва своята,
    затова двата термина ще съществуват едновременно, докато старите випуски завършат.
  </p>
  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="prog_add">
    <label>Вид
      <select name="kind">
        <?php foreach (PROGRAM_KINDS as $k => $lab): ?><option value="<?= e($k) ?>"><?= e($lab) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Наименование<input type="text" name="name" required size="30"></label>
    <label>Бележка<input type="text" name="note" size="20"></label>
    <button class="btn primary" type="submit">Добави</button>
  </form>

  <table class="grid">
    <thead><tr><th>Наименование</th><th>Вид</th><th>Паралелки</th><th>Компетентности</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($progs as $p): ?>
      <tr class="<?= $p['is_active'] ? '' : 'off' ?>">
        <td><strong><?= e($p['name']) ?></strong>
            <?php if ($p['note']): ?><br><small class="muted"><?= e($p['note']) ?></small><?php endif; ?></td>
        <td><span class="badge <?= $p['kind'] === 'profession' ? 'ok' : '' ?>"><?= e(PROGRAM_KINDS[$p['kind']]) ?></span></td>
        <td><?= (int)$p['n_classes'] ?></td>
        <td><?= (int)$p['n_comp'] ?></td>
        <td><?= $p['is_active'] ? 'активна' : 'скрита' ?></td>
        <td>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="prog_toggle">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn small ghost" type="submit"><?= $p['is_active'] ? 'Скрий' : 'Върни' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>Паралелки за <?= e(one('SELECT label FROM mo_years WHERE id=?', [$yid])['label'] ?? '—') ?></h2>
  <?php year_picker('I'); ?>

  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="class_add">
    <label>Нова паралелка<input type="text" name="name" placeholder="8З" required size="8"></label>
    <label>Професия
      <select name="program_id">
        <option value="">– няма –</option>
        <?php foreach ($profs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button class="btn primary" type="submit">Добави</button>
  </form>

  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="class_prog_bulk">
    <label>Задай наведнъж
      <select name="program_id">
        <option value="">– няма –</option>
        <optgroup label="Професии (нова класификация)">
          <?php foreach ($profs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="Специалности (стара класификация)">
          <?php foreach ($specs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </optgroup>
      </select>
    </label>
    <label>Паралелки (Ctrl за няколко)
      <select name="classes[]" multiple size="6">
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= $c['program_name'] ? ' · ' . e($c['program_name']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Запиши</button>
  </form>

  <table class="grid">
    <thead><tr><th>Паралелка</th><th>Клас</th><th>Професия / специалност</th><th>Анализи</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($classes as $c): ?>
      <tr class="<?= $c['is_active'] ? '' : 'off' ?>">
        <td><strong><?= e($c['name']) ?></strong></td>
        <td><?= (int)$c['grade_level'] ?>
            <?php if ((int)$c['grade_level'] === 8): ?><span class="badge ok">само професия</span><?php endif; ?></td>
        <td>
          <form method="post" style="display:inline-flex; gap:.25rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="class_prog">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <?php program_select('program_id', $c['program_id'] !== null ? (int)$c['program_id'] : null,
                                 (int)$c['grade_level'], $profs, $specs, 'width:auto; padding:.2rem .5rem'); ?>
            <button class="btn small" type="submit">OK</button>
          </form>
          <?php if ($c['program_kind']): ?>
            <span class="badge <?= $c['program_kind'] === 'profession' ? 'ok' : '' ?>">
              <?= e(PROGRAM_KINDS[$c['program_kind']]) ?></span>
          <?php endif; ?>
        </td>
        <td><?= (int)$c['n'] ?></td>
        <td><?= $c['is_active'] ? '<span class="badge ok">активна</span>' : '<span class="badge">скрита</span>' ?></td>
        <td>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="class_toggle">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn small ghost" type="submit"><?= $c['is_active'] ? 'Скрий' : 'Върни' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$classes): ?>
      <tr><td colspan="6" class="muted">За тази учебна година няма паралелки. Добавете ги или ги
          прехвърлете от предишната година.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="muted small">При осмите класове се предлагат само професии – те тръгват изцяло по
     новата класификация. При 9-12 клас се предлагат и двете, защото випуските по старата
     уредба довършват със своята специалност.</p>
</div>

<div class="panel">
  <h2>Предмети и методически обединения</h2>
  <p class="small">Добавянето и редакцията на предмети, обхватът на МО по професии/специалности и общообразователните маршрути са обединени в една страница.</p>
  <a class="btn primary" href="<?= base_url('pages/admin_departments.php') ?>">Отвори „Методически обединения“</a>
</div>

<div class="panel">
  <h2>Връзка с ВИС</h2>
  <table class="diag">
    <?php foreach (LaravelAuth::diagnose() as $k => $v): ?>
      <tr><td><?= e((string)$k) ?></td><td><?= e((string)$v) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php footer_html(); ?>
