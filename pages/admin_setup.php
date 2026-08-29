<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'year_add') {
        $label = trim((string)($_POST['label'] ?? ''));
        if (!preg_match('#^\d{4}[-/]\d{4}$#', $label)) {
            flash('Форматът е 2026-2027.', 'err');
        } else {
            q('INSERT IGNORE INTO mo_years (label) VALUES (?)', [$label]);
            flash('Учебната година е добавена.');
        }
    } elseif ($act === 'year_activate') {
        q('UPDATE mo_years SET is_active = 0');
        q('UPDATE mo_years SET is_active = 1 WHERE id = ?', [(int)$_POST['id']]);
        $_SESSION['year_id'] = (int)$_POST['id'];
        flash('Годината е активна.');
    } elseif ($act === 'class_toggle') {
        q('UPDATE mo_classes SET is_active = 1 - is_active WHERE id = ?', [(int)$_POST['id']]);
    } elseif ($act === 'class_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        preg_match('/\d+/', $name, $m);
        $lvl = (int)($m[0] ?? 0);
        $letter = trim(preg_replace('/[0-9\s]/u', '', $name) ?? '');
        if ($lvl >= 1 && $lvl <= 12 && $letter !== '') {
            q('INSERT IGNORE INTO mo_classes (name, grade_level, letter) VALUES (?,?,?)', [$name, $lvl, $letter]);
            flash('Паралелката е добавена.');
        } else {
            flash('Въведете паралелка във вид „8А“.', 'err');
        }
    } elseif ($act === 'subject_add') {
        $n = trim((string)($_POST['name'] ?? ''));
        if ($n !== '') { q('INSERT IGNORE INTO mo_subjects (name) VALUES (?)', [$n]); flash('Предметът е добавен.'); }
    } elseif ($act === 'subject_toggle') {
        q('UPDATE mo_subjects SET is_active = 1 - is_active WHERE id = ?', [(int)$_POST['id']]);
    }
    redirect(base_url('pages/admin_setup.php'));
}

$years = all('SELECT y.*, (SELECT COUNT(*) FROM mo_entries e WHERE e.year_id = y.id) n
              FROM mo_years y ORDER BY y.label DESC');
$classes = all('SELECT c.*, (SELECT COUNT(*) FROM mo_entries e WHERE e.class_id = c.id) n
                FROM mo_classes c ORDER BY c.grade_level, c.letter');
$subjects = all('SELECT s.*, (SELECT COUNT(*) FROM mo_competencies k WHERE k.subject_id = s.id AND k.is_active=1) n
                 FROM mo_subjects s ORDER BY s.name');

header_html('Години и паралелки', 'adm_setup');
section_title('Учебни години, паралелки и предмети');
?>

<div class="panel">
  <h2>Учебни години</h2>
  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="year_add">
    <label>Нова година<input type="text" name="label" placeholder="2026-2027" required></label>
    <button class="btn primary" type="submit">Добави</button>
  </form>
  <table class="grid">
    <thead><tr><th>Година</th><th>Анализи</th><th>Активна</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($years as $y): ?>
      <tr>
        <td><strong><?= e($y['label']) ?></strong></td>
        <td><?= (int)$y['n'] ?></td>
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
</div>

<div class="panel">
  <h2>Паралелки</h2>
  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="class_add">
    <label>Нова паралелка<input type="text" name="name" placeholder="8З" required></label>
    <button class="btn primary" type="submit">Добави</button>
  </form>
  <p class="muted small">Изключете паралелките, които гимназията няма – така падащото меню
     на учителите остава кратко.</p>
  <table class="grid">
    <thead><tr><th>Паралелка</th><th>Клас</th><th>Анализи</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($classes as $c): ?>
      <tr class="<?= $c['is_active'] ? '' : 'off' ?>">
        <td><strong><?= e($c['name']) ?></strong></td>
        <td><?= (int)$c['grade_level'] ?></td>
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
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>Предмети</h2>
  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="subject_add">
    <label>Нов предмет<input type="text" name="name" required></label>
    <button class="btn primary" type="submit">Добави</button>
  </form>
  <p class="muted small">Предметите се създават и автоматично при зареждането на компетентностите.</p>
  <table class="grid">
    <thead><tr><th>Предмет</th><th>Компетентности</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($subjects as $s): ?>
      <tr class="<?= $s['is_active'] ? '' : 'off' ?>">
        <td><?= e($s['name']) ?></td>
        <td><?= (int)$s['n'] ?></td>
        <td><?= $s['is_active'] ? '<span class="badge ok">активен</span>' : '<span class="badge">скрит</span>' ?></td>
        <td>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="subject_toggle">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn small ghost" type="submit"><?= $s['is_active'] ? 'Скрий' : 'Върни' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>Връзка с ВИС</h2>
  <table class="diag">
    <?php foreach (LaravelAuth::diagnose() as $k => $v): ?>
      <tr><td><?= e((string)$k) ?></td><td><?= e((string)$v) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small">Ако „разпознат user_id“ е празно за влязъл потребител, проверете
     LARAVEL_PATH и дали PHP има право да чете папката със сесиите.</p>
</div>
<?php footer_html(); ?>
