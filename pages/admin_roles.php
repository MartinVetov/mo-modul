<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('admin');
$yid = current_year_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($act === 'role_add' && $uid) {
        $role = (string)($_POST['role'] ?? '');
        if (in_array($role, ['teacher', 'methodist', 'deputy', 'admin'], true)) {
            q('INSERT IGNORE INTO mo_user_roles (user_id, role, assigned_by) VALUES (?,?,?)', [$uid, $role, $u['id']]);
            flash('Ролята е добавена.');
        }
    } elseif ($act === 'role_del' && $uid) {
        $role = (string)($_POST['role'] ?? '');
        if ($uid === (int)$u['id'] && $role === 'admin') {
            flash('Не можете да махнете собствените си администраторски права.', 'err');
        } else {
            q('DELETE FROM mo_user_roles WHERE user_id = ? AND role = ?', [$uid, $role]);
            flash('Ролята е премахната.');
        }
    } elseif ($act === 'assign') {
        $mid = ($_POST['methodist_id'] ?? '') !== '' ? (int)$_POST['methodist_id'] : null;
        if ($mid) {
            q('INSERT INTO mo_teacher_methodist (teacher_id, methodist_id, year_id) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE methodist_id = VALUES(methodist_id)', [$uid, $mid, $yid]);
        } else {
            q('DELETE FROM mo_teacher_methodist WHERE teacher_id = ? AND year_id = ?', [$uid, $yid]);
        }
        flash('Разпределението е записано.');
    } elseif ($act === 'assign_bulk') {
        $mid = (int)($_POST['methodist_id'] ?? 0);
        $ids = array_map('intval', (array)($_POST['teachers'] ?? []));
        if ($mid && $ids) {
            $st = db()->prepare('INSERT INTO mo_teacher_methodist (teacher_id, methodist_id, year_id) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE methodist_id = VALUES(methodist_id)');
            foreach ($ids as $t) $st->execute([$t, $mid, $yid]);
            flash('Разпределени са ' . count($ids) . ' учители.');
        } else {
            flash('Изберете методист и поне един учител.', 'err');
        }
    }
    redirect(base_url('pages/admin_roles.php'));
}

$search = trim((string)($_GET['q'] ?? ''));
$params = [':y' => $yid];
$whereSearch = '';
if ($search !== '') {
    // всеки placeholder се ползва само веднъж – MySQL не приема повторени имена
    $whereSearch = ' AND (u.name LIKE :q1 OR u.surname LIKE :q2 OR u.last_name LIKE :q3 OR u.email LIKE :q4)';
    $like = '%' . $search . '%';
    $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
}

$users = all(
    'SELECT u.id, ' . user_name_sql('u') . ' AS name, u.email, u.role AS vis_role,
            (SELECT GROUP_CONCAT(r.role) FROM mo_user_roles r WHERE r.user_id = u.id) AS mroles,
            (SELECT methodist_id FROM mo_teacher_methodist tm WHERE tm.teacher_id = u.id AND tm.year_id = :y) AS methodist_id
     FROM users u
     WHERE 1 = 1' . $whereSearch . '
     ORDER BY name LIMIT 300', $params);

$methodists = all('SELECT u.id, ' . user_name_sql('u') . ' AS name FROM mo_user_roles r
                   JOIN users u ON u.id = r.user_id WHERE r.role = "methodist" ORDER BY name');
$deputies   = all('SELECT u.id, ' . user_name_sql('u') . ' AS name FROM mo_user_roles r
                   JOIN users u ON u.id = r.user_id WHERE r.role = "deputy" ORDER BY name');

header_html('Роли и методисти', 'adm_roles');
section_title('Роли в модула и разпределение по методисти');
?>

<div class="panel">
  <p class="muted small">Потребителите идват от <code>users</code> на ВИС – модулът не създава профили
     и не пипа паролите. Тук само се добавят права в модула. Всеки без изрична роля е
     <strong>учител</strong>.</p>
  <div class="cards">
    <div class="stat"><span class="k"><?= count($methodists) ?></span><span class="l">методисти</span></div>
    <div class="stat"><span class="k"><?= count($deputies) ?></span><span class="l">зам-директори</span></div>
    <div class="stat"><span class="k"><?= (int)(one('SELECT COUNT(*) n FROM mo_teacher_methodist WHERE year_id=?', [$yid])['n'] ?? 0) ?></span>
      <span class="l">разпределени учители</span></div>
  </div>
</div>

<?php if ($methodists): ?>
<div class="panel">
  <h2>Бързо разпределяне към методист</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="assign_bulk">
    <label>Методист
      <select name="methodist_id" required>
        <option value="">– избери –</option>
        <?php foreach ($methodists as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Учители (задръжте Ctrl за няколко)
      <select name="teachers[]" multiple size="8">
        <?php foreach ($users as $x): ?>
          <option value="<?= (int)$x['id'] ?>"><?= e($x['name'] ?: $x['email']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn primary" type="submit">Разпредели</button>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Потребители</h2>
  <form class="picker" method="get">
    <label>Търсене по име или имейл
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="напр. Петров">
    </label>
    <button class="btn small" type="submit">Търси</button>
  </form>

  <table class="grid">
    <thead><tr><th>Име</th><th>Имейл</th><th>Роля във ВИС</th><th>Роли в модула</th><th>Методист</th></tr></thead>
    <tbody>
    <?php foreach ($users as $x):
        $roles = $x['mroles'] ? explode(',', $x['mroles']) : []; ?>
      <tr>
        <td><strong><?= e($x['name'] ?: '—') ?></strong></td>
        <td class="small"><?= e($x['email']) ?></td>
        <td class="small muted"><?= e((string)$x['vis_role']) ?></td>
        <td>
          <?php foreach ($roles as $r): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="role_del">
              <input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
              <input type="hidden" name="role" value="<?= e($r) ?>">
              <button class="badge" type="submit" title="Премахни" style="border:0;cursor:pointer">
                <?= e(role_bg($r)) ?> ✕</button>
            </form>
          <?php endforeach; ?>
          <form method="post" style="display:inline-flex; gap:.25rem; margin-top:.3rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="role_add">
            <input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
            <select name="role" style="width:auto; padding:.2rem .5rem">
              <option value="methodist">методист</option>
              <option value="deputy">зам-директор</option>
              <option value="admin">администратор</option>
              <option value="teacher">учител</option>
            </select>
            <button class="btn small" type="submit">+</button>
          </form>
        </td>
        <td>
          <form method="post" style="display:inline-flex; gap:.25rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
            <select name="methodist_id" style="width:auto; padding:.2rem .5rem">
              <option value="">– няма –</option>
              <?php foreach ($methodists as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= (int)$x['methodist_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                  <?= e($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn small" type="submit">OK</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">Показват се до 300 души – ползвайте търсенето при по-голям колектив.</p>
</div>
<?php footer_html(); ?>
