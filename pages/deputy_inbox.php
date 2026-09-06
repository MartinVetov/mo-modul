<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('deputy', 'admin');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');
$open = (int)($_GET['open'] ?? 0);

$where = has_role('admin', $u) && !has_role('deputy', $u) ? '' : ' AND s.deputy_id = :d';
$P = [':y' => $yid, ':t' => $term];
if ($where !== '') $P[':d'] = $u['id'];

$list = all(
    'SELECT s.*, ' . user_name_sql('m') . ' AS methodist_name,
            (SELECT COUNT(*) FROM mo_summary_entries se WHERE se.summary_id = s.id) AS n_entries
     FROM mo_summaries s JOIN users m ON m.id = s.methodist_id
     WHERE s.status = "sent" AND s.year_id = :y AND s.term = :t' . $where . '
     ORDER BY s.sent_at DESC', $P);

$doc = null; $rows = [];
if ($open) {
    $doc = one('SELECT s.*, ' . user_name_sql('m') . ' AS methodist_name, y.label AS year_label
                FROM mo_summaries s JOIN users m ON m.id = s.methodist_id
                JOIN mo_years y ON y.id = s.year_id
                WHERE s.id = ? AND s.status = "sent"', [$open]);
    if ($doc) {
        $rows = all('SELECT v.* FROM mo_summary_entries se JOIN mo_v_entries v ON v.id = se.entry_id
                     WHERE se.summary_id = ? ORDER BY v.teacher_name, v.grade_level, v.class_name', [$open]);
    }
}

header_html('Обобщения от МО', 'deputy');
section_title('Обобщения от методическите обединения');
year_picker($term);
?>

<div class="panel">
  <table class="grid">
    <thead><tr><th>Методист</th><th>Заглавие</th><th>Анализи</th><th>Изпратено</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
      <tr>
        <td><?= e($r['methodist_name']) ?></td>
        <td><?= e($r['title'] ?: 'Обобщен анализ · ' . term_label($r['term'])) ?></td>
        <td><?= (int)$r['n_entries'] ?></td>
        <td class="small"><?= $r['sent_at'] ? e(date('d.m.Y H:i', strtotime($r['sent_at']))) : '' ?></td>
        <td><a class="btn small" href="<?= base_url('pages/deputy_inbox.php?term=' . $term . '&open=' . (int)$r['id']) ?>">Отвори</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="5" class="muted">Няма изпратени обобщения за този срок.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($doc): ?>
<article class="panel">
  <div class="sec-title">
    <h1><?= e($doc['title'] ?: 'Обобщен анализ на МО') ?></h1>
    <div class="right no-print"><button class="btn small" type="button" onclick="window.print()">Печат / PDF</button></div>
  </div>
  <p class="muted small">Методист: <?= e($doc['methodist_name']) ?> · <?= e($doc['year_label']) ?>
     · <?= e(term_label($doc['term'])) ?> · изпратено <?= e(date('d.m.Y', strtotime($doc['sent_at']))) ?></p>

  <h2>Силни страни</h2>       <p><?= nl2br(e($doc['strengths'])) ?></p>
  <h2>Области за подобрение</h2><p><?= nl2br(e($doc['improvements'])) ?></p>
  <?php if (trim((string)$doc['summary_text']) !== ''): ?>
    <h2>Обобщение на МО</h2><p><?= nl2br(e($doc['summary_text'])) ?></p>
  <?php endif; ?>
  <h2>Мерки</h2><p><?= nl2br(e($doc['measures'])) ?></p>
  <?php if (trim((string)$doc['notes']) !== ''): ?>
    <h2>Бележки на методиста</h2><p><?= nl2br(e($doc['notes'])) ?></p>
  <?php endif; ?>
  <?php if (trim((string)$doc['other']) !== ''): ?>
    <h2>Други</h2><p><?= nl2br(e($doc['other'])) ?></p>
  <?php endif; ?>

  <h2>Анализи в основата на обобщението (<?= count($rows) ?>)</h2>
  <table class="grid">
    <thead><tr><th>Учител</th><th>Паралелка</th><th>Предмет</th><th>Усвоени</th><th>Частично</th><th>Неусвоени</th><th>Ср. успех</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['teacher_name']) ?></td><td><?= e($r['class_name']) ?></td>
          <td><?= e($r['subject_name']) ?></td><td><?= (int)$r['c_mastered'] ?></td><td><?= (int)$r['c_partial'] ?></td>
          <td class="<?= (int)$r['c_failed'] ? 'danger' : '' ?>"><?= (int)$r['c_failed'] ?></td>
          <td><?= fmt_avg($r['avg_grade']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</article>
<?php endif; ?>
<?php footer_html(); ?>
