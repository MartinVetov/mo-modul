<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('teacher');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $row = one('SELECT * FROM mo_entries WHERE id = ? AND user_id = ?', [$id, $u['id']]);
    if (!$row) {
        flash('Записът не е намерен.', 'err');
    } elseif (($_POST['action'] ?? '') === 'delete') {
        if ($row['status'] === 'sent') {
            flash('Изпратен анализ не се трие. Помолете методиста да го върне.', 'err');
        } else {
            q('DELETE FROM mo_entries WHERE id = ?', [$id]);
            flash('Черновата е изтрита.');
        }
    } elseif (($_POST['action'] ?? '') === 'withdraw') {
        q('UPDATE mo_entries SET status="draft", methodist_id=NULL, sent_at=NULL WHERE id=?', [$id]);
        flash('Анализът е върнат в чернови и може да се редактира.');
    }
    redirect(base_url('pages/my_entries.php?term=' . $term));
}

$rows = $yid ? all(
    'SELECT v.*, ' . user_name_sql('m') . ' AS methodist_name
     FROM mo_v_entries v LEFT JOIN users m ON m.id = v.methodist_id
     WHERE v.user_id = ? AND v.year_id = ? AND v.term = ?
     ORDER BY v.grade_level, v.class_name, v.subject_name', [$u['id'], $yid, $term]) : [];

header_html('Моите анализи', 'mine');
section_title('Моите анализи', '<a class="btn primary small" href="' . base_url('pages/entry.php?term=' . $term) . '">Въвеждане / редакция</a>');
year_picker($term);
?>

<?php if (!$rows): ?>
  <div class="panel"><p class="muted">За този срок още нямате въведени анализи.</p></div>
<?php else: ?>
<div class="panel">
<table class="grid">
  <thead>
    <tr><th>Паралелка</th><th>Предмет</th><th>Група</th><th>Компетентности</th>
        <th>Ср. успех</th><th>Бележки</th><th>Статус</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
      $left = max(0, (int)$r['c_total'] - (int)$r['c_mastered'] - (int)$r['c_failed']); ?>
    <tr>
      <td><strong><?= e($r['class_name']) ?></strong></td>
      <td><?= e($r['subject_name']) ?></td>
      <td><?= e(GROUPS[(string)$r['group_no']] ?? '') ?></td>
      <td class="small">
        <span class="badge ok"><?= (int)$r['c_mastered'] ?> усвоени</span>
        <span class="badge red"><?= (int)$r['c_failed'] ?> неусвоени</span>
        <span class="badge warn"><?= $left ?> за II срок</span>
      </td>
      <td><?= fmt_avg($r['avg_grade']) ?></td>
      <td class="small"><?= e(mb_strimwidth((string)$r['note'], 0, 70, '…')) ?></td>
      <td>
        <?php if ($r['status'] === 'sent'): ?>
          <span class="badge ok">изпратен</span><br>
          <span class="small muted">до <?= e($r['methodist_name'] ?: '—') ?></span>
        <?php else: ?>
          <span class="badge warn">чернова</span>
        <?php endif; ?>
      </td>
      <td>
        <form method="post" onsubmit="return confirm('Сигурни ли сте?')">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php if ($r['status'] === 'sent'): ?>
            <button class="btn small ghost" name="action" value="withdraw" type="submit">Върни за редакция</button>
          <?php else: ?>
            <button class="btn small danger" name="action" value="delete" type="submit">Изтрий</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted small">Неотбелязаните компетентности се водят прехвърлени за II срок и при
   въвеждането на втория срок се показват с етикет „прехвърлена от I срок“.</p>
<?php endif; ?>
<?php footer_html(); ?>
