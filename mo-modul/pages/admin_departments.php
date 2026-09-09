<?php
/**
 * Методически обединения: създаване, председател и заместник,
 * и разпределяне на предметите по МО.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $did = (int)($_POST['id'] ?? 0);

    if ($act === 'dep_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash('Въведете име на методическото обединение.', 'err');
        } elseif (one('SELECT id FROM mo_departments WHERE name = ?', [$name])) {
            flash('Такова МО вече съществува.', 'err');
        } else {
            q('INSERT INTO mo_departments (name) VALUES (?)', [$name]);
            flash('Методическото обединение е добавено. Задайте му председател и предмети.');
        }
    } elseif ($act === 'dep_rename') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '') { q('UPDATE mo_departments SET name = ? WHERE id = ?', [$name, $did]); flash('Името е сменено.'); }
    } elseif ($act === 'dep_heads') {
        $chair  = ($_POST['chair_id']  ?? '') !== '' ? (int)$_POST['chair_id']  : null;
        $deputy = ($_POST['deputy_id'] ?? '') !== '' ? (int)$_POST['deputy_id'] : null;
        if ($chair && $deputy && $chair === $deputy) {
            flash('Председателят и заместникът не може да са един и същ човек.', 'err');
        } else {
            q('UPDATE mo_departments SET chair_id = ?, deputy_id = ? WHERE id = ?', [$chair, $deputy, $did]);
            flash('Ръководството на МО е записано. Двамата виждат всички анализи по предметите на това МО.');
        }
    } elseif ($act === 'dep_toggle') {
        q('UPDATE mo_departments SET is_active = 1 - is_active WHERE id = ?', [$did]);
    } elseif ($act === 'dep_delete') {
        $n = (int)(one('SELECT COUNT(*) n FROM mo_subjects WHERE department_id = ?', [$did])['n'] ?? 0);
        if ($n > 0) {
            flash("В това МО има $n предмета. Преместете ги в друго МО, преди да го изтриете.", 'err');
        } else {
            q('DELETE FROM mo_departments WHERE id = ?', [$did]);
            flash('Методическото обединение е изтрито.');
        }
    } elseif ($act === 'subject_move') {
        $dep = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
        q('UPDATE mo_subjects SET department_id = ? WHERE id = ?', [$dep, (int)$_POST['subject_id']]);
        flash('Предметът е преместен.');
    } elseif ($act === 'subject_move_bulk') {
        $dep = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
        $ids = array_map('intval', (array)($_POST['subjects'] ?? []));
        if ($ids) {
            $st = db()->prepare('UPDATE mo_subjects SET department_id = ? WHERE id = ?');
            foreach ($ids as $sid) $st->execute([$dep, $sid]);
            flash('Преместени са ' . count($ids) . ' предмета.');
        } else {
            flash('Изберете поне един предмет.', 'err');
        }
    } elseif ($act === 'subject_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $dep  = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
        if ($name !== '') {
            q('INSERT INTO mo_subjects (name, department_id) VALUES (?,?)
               ON DUPLICATE KEY UPDATE department_id = VALUES(department_id)', [$name, $dep]);
            flash('Предметът е записан.');
        }
    }
    redirect(base_url('pages/admin_departments.php'));
}

$deps = all('SELECT d.*,
                    ' . user_name_sql('c') . ' AS chair_name,
                    ' . user_name_sql('z') . ' AS deputy_name,
                    (SELECT COUNT(*) FROM mo_subjects s WHERE s.department_id = d.id) AS n_subjects,
                    (SELECT COUNT(*) FROM mo_entries e WHERE e.department_id = d.id AND e.status = "sent") AS n_entries
             FROM mo_departments d
             LEFT JOIN users c ON c.id = d.chair_id
             LEFT JOIN users z ON z.id = d.deputy_id
             ORDER BY d.name');

$subjects = all('SELECT s.*, d.name AS department_name,
                        (SELECT COUNT(*) FROM mo_competencies k WHERE k.subject_id = s.id AND k.is_active = 1) n_comp
                 FROM mo_subjects s LEFT JOIN mo_departments d ON d.id = s.department_id
                 WHERE s.is_active = 1
                 ORDER BY (s.department_id IS NULL) DESC, d.name, s.name');

$people = all('SELECT id, ' . user_name_sql('u') . ' AS name, email FROM users u
               WHERE is_active IS NULL OR is_active = 1 ORDER BY name LIMIT 400');
$orphans = array_values(array_filter($subjects, static fn($s) => $s['department_id'] === null));

header_html('Методически обединения', 'adm_dep');
section_title('Методически обединения и предмети');
?>

<?php if ($orphans): ?>
<div class="flash warn">
  <strong><?= count($orphans) ?> предмета не са в никое МО:</strong>
  <?= e(implode(', ', array_column($orphans, 'name'))) ?>.
  Анализите по тях няма къде да отидат – разпределете ги по-долу.
</div>
<?php endif; ?>

<div class="panel">
  <h2>Ново методическо обединение</h2>
  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="dep_add">
    <label>Наименование<input type="text" name="name" placeholder="МО „Природни науки“" required size="32"></label>
    <button class="btn primary" type="submit">Добави</button>
  </form>
</div>

<div class="panel">
  <h2>Ръководство на МО</h2>
  <p class="muted small">Анализите по всички предмети на едно МО отиват едновременно при
     председателя и заместника. Всеки от двамата може да ги обобщи и да финализира доклада.
     Смяната на председател не пипа предметите и не мести стари анализи.</p>

  <table class="grid">
    <thead><tr><th>Методическо обединение</th><th>Председател</th><th>Заместник</th>
               <th>Предмети</th><th>Анализи</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($deps as $d): ?>
      <tr class="<?= $d['is_active'] ? '' : 'off' ?>">
        <td>
          <form method="post" class="rowform" style="display:inline-flex; gap:.3rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_rename">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input type="text" name="name" value="<?= e($d['name']) ?>" size="26" style="width:auto">
            <button class="btn small ghost" type="submit">Преименувай</button>
          </form>
        </td>
        <td colspan="2">
          <form method="post" style="display:flex; gap:.3rem; flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_heads">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <select name="chair_id" style="width:auto; padding:.2rem .5rem">
              <option value="">– без председател –</option>
              <?php foreach ($people as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$d['chair_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name'] ?: $p['email']) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="deputy_id" style="width:auto; padding:.2rem .5rem">
              <option value="">– без заместник –</option>
              <?php foreach ($people as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$d['deputy_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name'] ?: $p['email']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn small primary" type="submit">Запиши</button>
          </form>
          <?php if (!$d['chair_id'] && !$d['deputy_id']): ?>
            <span class="small danger">Няма ръководство – анализите ще стоят без получател.</span>
          <?php endif; ?>
        </td>
        <td><?= (int)$d['n_subjects'] ?></td>
        <td><?= (int)$d['n_entries'] ?></td>
        <td class="acts">
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_toggle">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn small ghost" type="submit"><?= $d['is_active'] ? 'Скрий' : 'Върни' ?></button>
          </form>
          <form method="post" style="display:inline" data-danger
                data-confirm-title="Изтриване на МО" data-confirm-ok="Изтрий"
                data-confirm="Методическото обединение ще бъде изтрито. Възможно е само ако в него няма предмети.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_delete">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn small danger" type="submit">Изтрий</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$deps): ?><tr><td colspan="6" class="muted">Още няма създадени МО.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>Предмети по методически обединения</h2>

  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="subject_add">
    <label>Нов предмет<input type="text" name="name" required size="26"></label>
    <label>В МО
      <select name="department_id">
        <option value="">– без МО –</option>
        <?php foreach ($deps as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button class="btn primary" type="submit">Добави</button>
  </form>

  <form method="post" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="subject_move_bulk">
    <label>Премести наведнъж в
      <select name="department_id">
        <option value="">– без МО –</option>
        <?php foreach ($deps as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Предмети (Ctrl за няколко)
      <select name="subjects[]" multiple size="7">
        <?php foreach ($subjects as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?><?= $s['department_name'] ? ' · ' . e($s['department_name']) : ' · без МО' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Премести</button>
  </form>

  <table class="grid">
    <thead><tr><th>Предмет</th><th>Методическо обединение</th><th>Компетентности</th></tr></thead>
    <tbody>
    <?php foreach ($subjects as $s): ?>
      <tr class="<?= $s['department_id'] === null ? 'off' : '' ?>">
        <td><strong><?= e($s['name']) ?></strong></td>
        <td>
          <form method="post" style="display:inline-flex; gap:.3rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="subject_move">
            <input type="hidden" name="subject_id" value="<?= (int)$s['id'] ?>">
            <select name="department_id" style="width:auto; padding:.2rem .5rem">
              <option value="">– без МО –</option>
              <?php foreach ($deps as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= (int)$s['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                  <?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn small" type="submit">OK</button>
          </form>
        </td>
        <td><?= (int)$s['n_comp'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php footer_html(); ?>
