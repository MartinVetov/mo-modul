<?php
/** Изтегляне на .docx файла или преглед в браузъра (за печат в PDF). */
require_once __DIR__ . '/../inc/bootstrap.php';

$u  = require_user();
$id = (int)($_GET['id'] ?? 0);

$d = one('SELECT d.*, y.label AS year_label, ' . user_name_sql('us') . ' AS author
          FROM mo_documents d
          JOIN mo_years y ON y.id = d.year_id
          JOIN users us ON us.id = d.user_id
          WHERE d.id = ?', [$id]);
if (!$d) { http_response_code(404); die('Документът не е намерен.'); }

/* достъп: авторът, зам-директорите и администраторът */
$mine = (int)$d['user_id'] === (int)$u['id'];
if (!$mine && !has_role('deputy') && !has_role('admin')) {
    http_response_code(403);
    die('Нямате достъп до този документ.');
}

/* ---------------- преглед в браузъра ---------------- */
if (!empty($_GET['view'])) {
    require_once __DIR__ . '/../inc/layout.php';
    header_html($d['title'], 'docs');
    ?>
    <div class="picker no-print">
      <button class="btn primary" type="button" onclick="window.print()">Печат / Запази като PDF</button>
      <a class="btn" href="<?= base_url('pages/document.php?id=' . (int)$d['id']) ?>">Изтегли Word</a>
      <a class="btn ghost" href="<?= base_url('pages/methodist_docs.php') ?>">Назад</a>
      <span class="muted small">За PDF: Ctrl+P → „Запази като PDF“.</span>
    </div>
    <article class="a4"><?= $d['html_snapshot'] ?: '<p>Няма запазено копие за преглед.</p>' ?></article>
    <?php
    footer_html();
    exit;
}

/* ---------------- изтегляне ---------------- */
$path = docs_dir() . '/' . $d['filename'];
if (!is_file($path)) {
    http_response_code(410);
    die('Файлът липсва на диска. Изгответе документа отново от „Обобщение“.');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . rawurlencode($d['filename']) . '"; '
     . 'filename*=UTF-8\'\'' . rawurlencode($d['filename']));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0');
readfile($path);
