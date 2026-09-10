<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_user();
if (!leads_department($u) && !has_role('deputy', $u) && !has_role('admin', $u)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif">Нямате права за документи на методическо обединение.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $d = one('SELECT * FROM mo_documents WHERE id = ? AND user_id = ?', [$id, $u['id']]);
    if ($d && ($_POST['action'] ?? '') === 'delete') {
        $p = docs_dir() . '/' . $d['filename'];
        if (is_file($p)) @unlink($p);
        q('DELETE FROM mo_documents WHERE id = ?', [$id]);
        flash('Документът е изтрит.');
    } elseif ($d && ($_POST['action'] ?? '') === 'rename') {
        $t = trim((string)($_POST['title'] ?? ''));
        if ($t !== '') { q('UPDATE mo_documents SET title = ? WHERE id = ?', [$t, $id]); flash('Заглавието е сменено.'); }
    }
    redirect(base_url('pages/methodist_docs.php'));
}

$docs = all('SELECT d.*, y.label AS year_label FROM mo_documents d
             JOIN mo_years y ON y.id = d.year_id
             WHERE d.user_id = ? ORDER BY d.created_at DESC', [$u['id']]);

header_html('Моите документи', 'docs');
section_title('Моите документи',
    '<a class="btn small primary" href="' . base_url('pages/methodist_summary.php') . '">Към обобщението</a>');
?>

<div class="panel">
  <p class="muted small">Всеки път, когато изготвите или изпратите обобщение, тук се запазва
     копие във формат Word (Times New Roman, 12pt двустранно подравнен текст, центрирани
     заглавия 16pt). „Преглед / PDF“ отваря документа в браузъра – оттам с <strong>Ctrl+P →
     Запази като PDF</strong> го получавате в PDF. За редакция изтеглете файла и го отворете в Word.</p>

  <?php if (!$docs): ?>
    <p class="muted">Още няма изготвени документи.</p>
  <?php else: ?>
  <div class="doc-list">
    <?php foreach ($docs as $d): ?>
      <div class="doc">
        <span class="ic">DOCX</span>
        <span class="meta">
          <strong><?= e($d['title']) ?></strong>
          <small><?= e($d['year_label']) ?> · <?= e(term_label($d['term'])) ?>
                 · <?= e(date('d.m.Y H:i', strtotime($d['created_at']))) ?>
                 · <?= round($d['size_bytes'] / 1024, 1) ?> KB</small>
        </span>
        <span class="acts">
          <a class="btn small" href="<?= base_url('pages/document.php?id=' . (int)$d['id'] . '&view=1') ?>">Преглед / PDF</a>
          <a class="btn small primary" href="<?= base_url('pages/document.php?id=' . (int)$d['id']) ?>">Изтегли</a>
          <form method="post" style="display:inline" data-danger
                data-confirm="Документът и файлът към него ще бъдат изтрити безвъзвратно."
                data-confirm-title="Изтриване на документ" data-confirm-ok="Изтрий">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn small danger" type="submit">Изтрий</button>
          </form>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php footer_html(); ?>
