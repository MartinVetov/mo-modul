<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_user();
/* Ръководените МО стоят първи: администраторът вижда всички, но по
   подразбиране застава на своето, а не на първото по азбука. */
$ledDeps = my_departments($u);
$ledIds  = array_map('intval', array_column($ledDeps, 'id'));

if (has_role('admin', $u)) {
    $others = all('SELECT * FROM mo_departments WHERE is_active = 1'
        . ($ledIds ? ' AND id NOT IN (' . implode(',', $ledIds) . ')' : '')
        . ' ORDER BY name');
    $myDeps = array_merge($ledDeps, $others);
} else {
    $myDeps = $ledDeps;
}

if (!$myDeps) {
    http_response_code(403);
    die('<p style="font-family:sans-serif">Не сте председател или заместник на методическо обединение.</p>');
}

$depIds = array_map('intval', array_column($myDeps, 'id'));
$depId  = (int)($_GET['dep'] ?? 0);
if (!in_array($depId, $depIds, true)) $depId = $ledIds[0] ?? $depIds[0];
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'return') {
        q('UPDATE mo_entries SET status="draft", sent_at=NULL WHERE id=? AND department_id=?',
          [$id, (int)($_POST['dep'] ?? 0)]);
        flash('Анализът е върнат на учителя за поправка.');
    }
    redirect(base_url('pages/methodist_inbox.php?term=' . $term . '&dep=' . (int)($_POST['dep'] ?? $depId)));
}

$P = [':d' => $depId, ':y' => $yid, ':t' => $term];

$rows = $yid ? all(
    'SELECT * FROM mo_v_entries
      WHERE department_id = :d AND status = "sent" AND year_id = :y AND term = :t
      ORDER BY teacher_name, grade_level, class_name, subject_name', $P) : [];

$mine = $yid ? all(
    'SELECT ' . user_name_sql('u') . ' AS name, u.id, COUNT(*) AS sent
     FROM mo_entries e JOIN users u ON u.id = e.user_id
     WHERE e.department_id = :d AND e.year_id = :y AND e.term = :t AND e.status = "sent"
     GROUP BY u.id ORDER BY name', $P) : [];

$sum = $yid ? one(
    'SELECT COUNT(*) n, AVG(avg_grade) avg, SUM(c_mastered) ok, SUM(c_partial) partial, SUM(c_failed) no_
     FROM mo_v_entries WHERE department_id=:d AND status="sent" AND year_id=:y AND term=:t', $P) : null;

$topFailed = $yid ? all(
    'SELECT z.title,z.code,z.subject_name,z.grade_level,
            SUM(z.failed) failed,SUM(z.partial) partial
     FROM (
       SELECT k.title,k.code,s.name AS subject_name,c.grade_level,
              SUM(ec.state="not_mastered") failed,SUM(ec.state="partial") partial
       FROM mo_entry_competencies ec
       JOIN mo_competencies k ON k.id=ec.competency_id
       JOIN mo_subjects s ON s.id=k.subject_id
       JOIN mo_entries e ON e.id=ec.entry_id
       JOIN mo_classes c ON c.id=e.class_id
       WHERE e.department_id=:d1 AND e.status="sent" AND e.year_id=:y1 AND e.term=:t1
       GROUP BY k.id,k.title,k.code,s.name,c.grade_level
       UNION ALL
       SELECT mc.title,"РПП" AS code,s.name AS subject_name,c.grade_level,
              SUM(mc.state="not_mastered") failed,SUM(mc.state="partial") partial
       FROM mo_entry_manual_competencies mc
       JOIN mo_entries e ON e.id=mc.entry_id
       JOIN mo_subjects s ON s.id=e.subject_id
       JOIN mo_classes c ON c.id=e.class_id
       WHERE e.department_id=:d2 AND e.status="sent" AND e.year_id=:y2 AND e.term=:t2
       GROUP BY mc.title,s.name,c.grade_level
     ) z
     GROUP BY z.title,z.code,z.subject_name,z.grade_level
     HAVING failed > 0 OR partial > 0
     ORDER BY failed DESC,partial DESC LIMIT 10',
    [':d1'=>$depId,':y1'=>$yid,':t1'=>$term,':d2'=>$depId,':y2'=>$yid,':t2'=>$term]) : [];

header_html('Получени анализи', 'inbox');
$depName = one('SELECT name FROM mo_departments WHERE id = ?', [$depId])['name'] ?? '—';
$myRole  = department_role_bg(department_role($depId, $u));
section_title('Получени анализи · ' . $depName . ($myRole ? ' (' . $myRole . ')' : ''),
    '<a class="btn primary small" href="' . base_url('pages/methodist_summary.php?term=' . $term . '&dep=' . $depId) . '">Към обобщението →</a>');
year_picker($term, ['dep' => $depId]);
if (count($myDeps) > 1): ?>
  <form class="picker no-print" method="get">
    <input type="hidden" name="term" value="<?= e($term) ?>">
    <label>Методическо обединение
      <select name="dep" onchange="this.form.submit()">
        <?php foreach ($myDeps as $d):
            $role = department_role_bg(department_role((int)$d['id'], $u)); ?>
          <option value="<?= (int)$d['id'] ?>" <?= $depId === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e($d['name']) ?><?= $role ? ' · ' . e($role) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
<?php endif;

?>

<div class="cards">
  <div class="stat"><span class="k"><?= (int)($sum['n'] ?? 0) ?></span><span class="l">получени анализа</span></div>
  <div class="stat"><span class="k"><?= fmt_avg($sum['avg'] ?? null) ?></span><span class="l">среден успех</span></div>
  <div class="stat"><span class="k"><?= (int)($sum['ok'] ?? 0) ?></span><span class="l">отчетени усвоени</span></div>
  <div class="stat"><span class="k"><?= (int)($sum['partial'] ?? 0) ?></span><span class="l">частично усвоени</span></div>
  <div class="stat"><span class="k"><?= (int)($sum['no_'] ?? 0) ?></span><span class="l">отчетени неусвоени</span></div>
</div>

<?php if ($mine): ?>
<div class="panel">
  <h2>Изпратили анализи (<?= count($mine) ?>)</h2>
  <p class="small"><?php foreach ($mine as $m): ?>
    <span class="badge ok"><?= e($m['name']) ?> · <?= (int)$m['sent'] ?></span>
  <?php endforeach; ?></p>
</div>
<?php endif; ?>

<?php if ($topFailed): ?>
<div class="panel">
  <h2>Компетентности с най-много отчетени пропуски</h2>
  <table class="grid">
    <thead><tr><th>Компетентност</th><th>Предмет</th><th>Клас</th><th>Частично</th><th>Неусвоена</th></tr></thead>
    <tbody>
    <?php foreach ($topFailed as $t): ?>
      <tr>
        <td><?php if ($t['code']): ?><span class="badge"><?= e($t['code']) ?></span> <?php endif; ?><?= e($t['title']) ?></td>
        <td><?= e($t['subject_name']) ?></td>
        <td><?= (int)$t['grade_level'] ?> клас</td>
        <td><?= (int)$t['partial'] ?></td>
        <td class="danger"><?= (int)$t['failed'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Всички получени анализи</h2>
  <?php if (!$rows): ?>
    <p class="muted">Още няма изпратени анализи за този срок.</p>
  <?php else: ?>
  <table class="grid">
    <thead><tr><th>Учител</th><th>Паралелка</th><th>Предмет</th><th>Компетентности</th>
               <th>Ср. успех</th><th>Мерки</th><th>Изпратен</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $left = max(0, (int)$r['c_total'] - (int)$r['c_mastered'] - (int)$r['c_partial'] - (int)$r['c_failed']); ?>
      <tr>
        <td><?= e($r['teacher_name']) ?></td>
        <td><strong><?= e($r['class_name']) ?></strong> <span class="small muted"><?= e(GROUPS[(string)$r['group_no']] ?? '') ?></span></td>
        <td><?= e($r['subject_name']) ?></td>
        <td class="small">
          <span class="badge ok"><?= (int)$r['c_mastered'] ?></span>
          <span class="badge warn"><?= (int)$r['c_partial'] ?></span>
          <span class="badge red"><?= (int)$r['c_failed'] ?></span>
          <span class="badge warn"><?= $left ?> за II срок</span>
        </td>
        <td><?= fmt_avg($r['avg_grade']) ?></td>
        <td class="small"><?= e($r['measures']) ?></td>
        <td class="small"><?= $r['sent_at'] ? e(date('d.m.Y', strtotime($r['sent_at']))) : '' ?></td>
        <td class="acts">
          <a class="btn small" href="<?= base_url('pages/methodist_entry_view.php?id=' . (int)$r['id'] . '&dep=' . (int)$depId . '&term=' . urlencode($term)) ?>">Преглед</a>
          <form method="post" style="display:inline" data-confirm="Анализът се връща на учителя за поправка и изчезва от обобщението, докато не го изпрати отново."
                data-confirm-title="Връщане на анализа" data-confirm-ok="Върни">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="dep" value="<?= (int)$depId ?>">
            <button class="btn small ghost" name="action" value="return" type="submit"
                    data-confirm="Анализът се връща на учителя за поправка."
                    data-confirm-title="Връщане на анализа" data-confirm-ok="Върни">Върни</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php footer_html(); ?>
