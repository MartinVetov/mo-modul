<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('methodist');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'return') {
        q('UPDATE mo_entries SET status="draft", sent_at=NULL WHERE id=? AND methodist_id=?', [$id, $u['id']]);
        flash('Анализът е върнат на учителя за поправка.');
    }
    redirect(base_url('pages/methodist_inbox.php?term=' . $term));
}

$P = [':m' => $u['id'], ':y' => $yid, ':t' => $term];

$rows = $yid ? all(
    'SELECT * FROM mo_v_entries
      WHERE methodist_id = :m AND status = "sent" AND year_id = :y AND term = :t
      ORDER BY teacher_name, grade_level, class_name, subject_name', $P) : [];

$mine = $yid ? all(
    'SELECT ' . user_name_sql('u') . ' AS name, u.id,
            (SELECT COUNT(*) FROM mo_entries e
              WHERE e.user_id = u.id AND e.year_id = :y AND e.term = :t AND e.status = "sent") AS sent
     FROM mo_teacher_methodist tm JOIN users u ON u.id = tm.teacher_id
     WHERE tm.methodist_id = :m AND tm.year_id = :y2
     ORDER BY name', $P + [':y2' => $yid]) : [];

$sum = $yid ? one(
    'SELECT COUNT(*) n, AVG(avg_grade) avg, SUM(c_mastered) ok, SUM(c_partial) partial, SUM(c_failed) no_
     FROM mo_v_entries WHERE methodist_id=:m AND status="sent" AND year_id=:y AND term=:t', $P) : null;

$topFailed = $yid ? all(
    'SELECT k.title, k.code, s.name AS subject_name, c.grade_level,
            SUM(ec.state="not_mastered") failed, SUM(ec.state="partial") partial
     FROM mo_entry_competencies ec
     JOIN mo_competencies k ON k.id = ec.competency_id
     JOIN mo_subjects s ON s.id = k.subject_id
     JOIN mo_entries e ON e.id = ec.entry_id
     JOIN mo_classes c ON c.id = e.class_id
     WHERE e.methodist_id = :m AND e.status="sent" AND e.year_id = :y AND e.term = :t
     GROUP BY k.id, k.title, k.code, s.name, c.grade_level
     HAVING failed > 0 ORDER BY failed DESC LIMIT 10', $P) : [];

header_html('Получени анализи', 'inbox');
section_title('Получени анализи',
    '<a class="btn primary small" href="' . base_url('pages/methodist_summary.php?term=' . $term) . '">Към обобщението →</a>');
year_picker($term);
?>

<div class="cards">
  <div class="stat"><span class="k"><?= (int)($sum['n'] ?? 0) ?></span><span class="l">получени анализа</span></div>
  <div class="stat"><span class="k"><?= fmt_avg($sum['avg'] ?? null) ?></span><span class="l">среден успех</span></div>
  <div class="stat"><span class="k"><?= (int)($sum['ok'] ?? 0) ?></span><span class="l">отчетени усвоени</span></div>
  <div class="stat"><span class="k"><?= (int)($sum['partial'] ?? 0) ?></span><span class="l">частично усвоени</span></div>
  <div class="stat"><span class="k"><?= (int)($sum['no_'] ?? 0) ?></span><span class="l">отчетени неусвоени</span></div>
</div>

<?php
$missing = array_filter($mine, static fn($m) => (int)$m['sent'] === 0);
if ($missing): ?>
<div class="panel">
  <h2>Още не са изпратили (<?= count($missing) ?>)</h2>
  <p class="small"><?= e(implode(', ', array_column($missing, 'name'))) ?></p>
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
        <td>
          <form method="post" data-confirm="Анализът се връща на учителя за поправка и изчезва от обобщението, докато не го изпрати отново."
                data-confirm-title="Връщане на анализа" data-confirm-ok="Върни">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn small ghost" name="action" value="return" type="submit">Върни</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php footer_html(); ?>
