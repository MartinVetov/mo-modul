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
        if (in_array($role, ['teacher', 'deputy', 'admin'], true)) {
            q('INSERT IGNORE INTO mo_user_roles (user_id, role, assigned_by) VALUES (?,?,?)', [$uid, $role, $u['id']]);
            flash('Ролята е добавена.');
        }
    } elseif ($act === 'role_del' && $uid) {
        $role = (string)($_POST['role'] ?? '');
        if ($uid === (int)$u['id'] && $role === 'admin') {
            flash('Не можете да махнете собствените си администраторски права.', 'err');
        } elseif ($role === 'methodist') {
            flash('Ролята „методист“ се управлява автоматично от ръководството на МО и не се премахва ръчно.', 'err');
        } else {
            q('DELETE FROM mo_user_roles WHERE user_id = ? AND role = ?', [$uid, $role]);
            flash('Ролята е премахната.');
        }
    }
    redirect(base_url('pages/admin_roles.php'));
}

$search = trim((string)($_GET['q'] ?? ''));
$params = [];
$whereSearch = '';
if ($search !== '') {
    // всеки placeholder се ползва само веднъж – MySQL не приема повторени имена
    $whereSearch = ' AND (u.name LIKE :q1 OR u.surname LIKE :q2 OR u.last_name LIKE :q3 OR u.email LIKE :q4)';
    $like = '%' . $search . '%';
    $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
}

$users = all(
    'SELECT u.id, ' . user_name_sql('u') . ' AS name, u.email, u.role AS vis_role,
            (SELECT GROUP_CONCAT(r.role) FROM mo_user_roles r WHERE r.user_id = u.id) AS mroles
     FROM users u
     WHERE 1 = 1' . $whereSearch . '
     ORDER BY name LIMIT 300', $params);

$leaders = all('SELECT d.name AS dep, ' . user_name_sql('c') . ' AS chair, ' . user_name_sql('z') . ' AS deputy
                FROM mo_departments d
                LEFT JOIN users c ON c.id = d.chair_id
                LEFT JOIN users z ON z.id = d.deputy_id
                WHERE d.is_active = 1 ORDER BY d.name');
$deputies   = all('SELECT u.id, ' . user_name_sql('u') . ' AS name FROM mo_user_roles r
                   JOIN users u ON u.id = r.user_id WHERE r.role = "deputy" ORDER BY name');

header_html('Роли и права', 'adm_roles');
section_title('Роли и права в модула');
?>

<div class="panel">
  <p class="muted small">Потребителите идват от <code>users</code> на ВИС – модулът не създава профили
     и не пипа паролите. Тук само се добавят права в модула. Всеки без изрична роля е
     <strong>учител</strong>. Ролята <strong>методист</strong> се получава автоматично от председателите и заместниците. Те се
     задават от <a href="<?= base_url('pages/admin_departments.php') ?>">Методически обединения</a>,
     защото професионалните анализи се маршрутизират по професията/специалността на паралелката, а общообразователните – по предмета.</p>
  <div class="cards">
    <div class="stat"><span class="k"><?= count($leaders) ?></span><span class="l">методически обединения</span></div>
    <div class="stat"><span class="k"><?= count($deputies) ?></span><span class="l">зам-директори</span></div>
    <div class="stat"><span class="k"><?= (int)(one('SELECT COUNT(DISTINCT sd.subject_id) n FROM mo_subject_departments sd WHERE sd.is_active=1 AND EXISTS (SELECT 1 FROM mo_competencies k WHERE k.subject_id=sd.subject_id AND k.is_active=1 AND k.program_id IS NULL)')['n'] ?? 0) ?></span>
      <span class="l">общообразователни предмета с МО</span></div>
  </div>
</div>


<div class="panel">
  <h2>Потребители</h2>
  <form class="picker" method="get">
    <label>Търсене по име или имейл
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="напр. Петров">
    </label>
    <button class="btn small" type="submit">Търси</button>
  </form>

  <table class="grid">
    <thead><tr><th>Име</th><th>Имейл</th><th>Роля във ВИС</th><th>Роли в модула</th></tr></thead>
    <tbody>
    <?php foreach ($users as $x):
        $roles = $x['mroles'] ? explode(',', $x['mroles']) : []; ?>
      <tr>
        <td><strong><?= e($x['name'] ?: '—') ?></strong></td>
        <td class="small"><?= e($x['email']) ?></td>
        <td class="small muted"><?= e((string)$x['vis_role']) ?></td>
        <td>
          <?php foreach ($roles as $r): ?>
            <?php if ($r === 'methodist'): ?>
              <span class="badge ok" title="Автоматична роля от ръководството на МО"><?= e(role_bg($r)) ?></span>
            <?php else: ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="role_del">
                <input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
                <input type="hidden" name="role" value="<?= e($r) ?>">
                <button class="badge" type="submit" title="Премахни" style="border:0;cursor:pointer">
                  <?= e(role_bg($r)) ?> ✕</button>
              </form>
            <?php endif; ?>
          <?php endforeach; ?>
          <form method="post" style="display:inline-flex; gap:.25rem; margin-top:.3rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="role_add">
            <input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
            <select name="role" style="width:auto; padding:.2rem .5rem">
              <option value="deputy">зам-директор</option>
              <option value="admin">администратор</option>
              <option value="teacher">учител</option>
            </select>
            <button class="btn small" type="submit">+</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">Показват се до 300 души – ползвайте търсенето при по-голям колектив.</p>
</div>
<?php footer_html(); ?>
