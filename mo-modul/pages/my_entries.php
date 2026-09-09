<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('teacher');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id  = (int)($_POST['id'] ?? 0);
    $act = $_POST['action'] ?? '';
    $row = one('SELECT * FROM mo_entries WHERE id = ? AND user_id = ?', [$id, $u['id']]);

    if (!$row) {
        flash('Записът не е намерен.', 'err');
    } elseif ($act === 'delete') {
        if ($row['status'] === 'sent') {
            flash('Изпратен анализ не се трие. Първо го върнете за редакция.', 'err');
        } else {
            q('DELETE FROM mo_entries WHERE id = ?', [$id]);
            flash('Черновата е изтрита.');
        }
    } elseif ($act === 'withdraw') {
        q('UPDATE mo_entries SET status="draft", sent_at=NULL WHERE id=?', [$id]);
        flash('Анализът е върнат в чернови и може да се редактира.');
    }
    redirect(base_url('pages/my_entries.php?term=' . $term));
}

$rows = $yid ? all(
    'SELECT * FROM mo_v_entries
     WHERE user_id = ? AND year_id = ? AND term = ?
     ORDER BY subject_name, grade_level, class_name', [$u['id'], $yid, $term]) : [];

header_html('Моите анализи', 'mine');
section_title('Моите анализи',
    '<a class="btn primary small" href="' . base_url('pages/entry.php?term=' . $term) . '">➕ Нов анализ</a>');
year_picker($term);
?>

<?php if (!$rows): ?>
  <div class="panel">
    <p class="muted">За този срок още нямате анализи.
      <a href="<?= base_url('pages/entry.php?term=' . $term) ?>">Създайте първия</a> – после ще можете
      да го дублирате за останалите паралелки с готовите отметки.</p>
  </div>
<?php else: ?>
<div class="panel">
<table class="grid">
  <thead>
    <tr><th>Предмет</th><th>Паралелка</th><th>Компетентности</th><th>Ср. успех</th>
        <th>Мерки</th><th>Статус</th><th>Действия</th></tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
      $left = max(0, (int)$r['c_total'] - (int)$r['c_mastered'] - (int)$r['c_partial'] - (int)$r['c_failed']); ?>
    <tr>
      <td><strong><?= e($r['subject_name']) ?></strong>
          <?php if ($r['department_name']): ?><br><small class="muted"><?= e($r['department_name']) ?></small>
          <?php else: ?><br><small class="danger">без МО</small><?php endif; ?></td>
      <td><?= e($r['class_name']) ?>
          <span class="small muted"><?= e(GROUPS[(string)$r['group_no']] ?? '') ?></span></td>
      <td class="small">
        <span class="badge ok"><?= (int)$r['c_mastered'] ?></span>
        <span class="badge warn"><?= (int)$r['c_partial'] ?></span>
        <span class="badge red"><?= (int)$r['c_failed'] ?></span>
        <span class="badge"><?= $left ?> за II срок</span>
      </td>
      <td><?= fmt_avg($r['avg_grade']) ?></td>
      <td class="small"><?= e(mb_strimwidth((string)$r['measures'], 0, 60, '…')) ?></td>
      <td><?php if ($r['status'] === 'sent'): ?>
            <span class="badge ok">изпратен</span>
            <br><span class="small muted"><?= $r['sent_at'] ? e(date('d.m.Y', strtotime($r['sent_at']))) : '' ?></span>
          <?php else: ?><span class="badge warn">чернова</span><?php endif; ?></td>
      <td class="acts">
        <a class="btn small" href="<?= base_url('pages/entry.php?id=' . (int)$r['id']) ?>">
          <?= $r['status'] === 'sent' ? 'Виж' : 'Редакция' ?></a>
        <a class="btn small ghost" href="<?= base_url('pages/entry.php?term=' . $term . '&copy=' . (int)$r['id']) ?>"
           title="Нов анализ със същите отметки">⧉ Дублирай</a>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php if ($r['status'] === 'sent'): ?>
            <button class="btn small ghost" name="action" value="withdraw" type="submit"
                    data-confirm="Анализът се връща в чернови и МО няма да го вижда, докато не го изпратите отново."
                    data-confirm-title="Връщане за редакция" data-confirm-ok="Върни">Върни</button>
          <?php else: ?>
            <button class="btn small danger" name="action" value="delete" type="submit" data-danger
                    data-confirm="Черновата ще бъде изтрита заедно с отметките по компетентностите."
                    data-confirm-title="Изтриване на чернова" data-confirm-ok="Изтрий">Изтрий</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted small">„Дублирай“ отваря нов анализ със същия предмет и същите отметки по
   компетентностите – остава да смените паралелката и да въведете оценките.</p>
<?php endif; ?>
<?php footer_html(); ?>
