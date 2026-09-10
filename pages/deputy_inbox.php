<?php
/**
 * Обобщенията от методическите обединения при зам-директора.
 *
 * Страницата дава три неща, които списъкът сам по себе си не дава:
 *   – картина за цялото училище (кой е изпратил, кой не, какви са числата);
 *   – действие: приемане или връщане на обобщението с бележка;
 *   – достъп до готовия Word документ, а не само печат от браузъра.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/summary_data.php';
require_once __DIR__ . '/../lib/DocxWriter.php';

$u = require_role('deputy', 'admin');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');
$open = (int)($_GET['open'] ?? 0);

/* Администратор без роля зам-директор вижда всичко; зам-директорът –
   изпратените до него, плюс тези без посочен получател, за да не увисват. */
$seeAll = has_role('admin', $u) && !has_role('deputy', $u);
$where  = $seeAll ? '' : ' AND (s.deputy_id = :d OR s.deputy_id IS NULL)';

/* ------------------------------ действия ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sid  = (int)($_POST['id'] ?? 0);
    $act  = $_POST['action'] ?? '';
    $note = trim((string)($_POST['deputy_note'] ?? ''));

    /* --- обобщение на училището --- */
    if (in_array($act, ['school_save', 'school_export', 'school_final'], true)) {
        q('INSERT IGNORE INTO mo_school_summaries (year_id, term, author_id) VALUES (?,?,?)',
          [$yid, $term, $u['id']]);
        $row = one('SELECT * FROM mo_school_summaries WHERE year_id=? AND term=?', [$yid, $term]);
        $schoolId = (int)$row['id'];

        $vals = [];
        foreach (['title', 'summary_text', 'strengths', 'improvements', 'measures', 'notes'] as $f) {
            $vals[$f] = trim((string)($_POST[$f] ?? ''));
        }
        $status = $act === 'school_final' ? 'final' : 'draft';
        q('UPDATE mo_school_summaries SET title=?, summary_text=?, strengths=?, improvements=?,
                  measures=?, notes=?, status=?, author_id=?,
                  finalized_at = CASE WHEN ?="final" THEN NOW() ELSE finalized_at END
           WHERE id=?',
          [$vals['title'], $vals['summary_text'], $vals['strengths'], $vals['improvements'],
           $vals['measures'], $vals['notes'], $status, $u['id'], $status, $schoolId]);

        if ($act === 'school_export' || $act === 'school_final') {
            try {
                $SD  = school_summary_data($yid, $term);
                $rep = one('SELECT * FROM mo_school_summaries WHERE id=?', [$schoolId]);
                $doc = build_school_doc($SD, $rep, $u, $term);

                $safe = preg_replace('/[^\p{L}\p{N}\-_ ]/u', '', $doc['title']) ?: 'obobshtenie';
                $name = mb_substr($safe, 0, 60) . ' - ' . term_label($term) . ' - ' . date('Y-m-d His') . '.docx';
                $size = $doc['docx']->save(docs_dir() . '/' . $name);

                q('INSERT INTO mo_documents (school_summary_id, user_id, year_id, term, title, filename, html_snapshot, size_bytes)
                   VALUES (?,?,?,?,?,?,?,?)',
                  [$schoolId, $u['id'], $yid, $term, $doc['title'], $name, $doc['html'], $size]);

                flash($act === 'school_final'
                    ? 'Обобщението на училището е приключено и документът е готов за изтегляне.'
                    : 'Документът е изготвен и е готов за изтегляне.');
            } catch (Throwable $ex) {
                flash('Документът не бе създаден: ' . e($ex->getMessage()), 'err');
            }
        } else {
            flash('Обобщението на училището е запазено.');
        }
        redirect(base_url('pages/deputy_inbox.php?term=' . $term));
    }

    $s = one('SELECT * FROM mo_summaries WHERE id = ? AND status = "sent"', [$sid]);
    if (!$s) {
        flash('Обобщението не е намерено.', 'err');
    } elseif (!$seeAll && $s['deputy_id'] !== null && (int)$s['deputy_id'] !== (int)$u['id']) {
        flash('Това обобщение е изпратено до друг зам-директор.', 'err');
    } elseif ($act === 'make_doc') {
        /* методистът не е изготвил документ – директорът може да го създаде сам */
        try {
            $D2  = summary_data((int)$s['department_id'], (int)$s['year_id'], (string)$s['term']);
            $dep2 = one('SELECT name FROM mo_departments WHERE id = ?', [$s['department_id']]);
            $doc2 = build_summary_doc($D2, $s, ['display_name' => $dep2['name'] ?? ''], (string)$s['term']);

            $safe = preg_replace('/[^\p{L}\p{N}\-_ ]/u', '', $doc2['title']) ?: 'obobshtenie';
            $name = mb_substr($safe, 0, 60) . ' - ' . term_label((string)$s['term']) . ' - ' . date('Y-m-d His') . '.docx';
            $size = $doc2['docx']->save(docs_dir() . '/' . $name);

            q('INSERT INTO mo_documents (summary_id, user_id, year_id, term, title, filename, html_snapshot, size_bytes)
               VALUES (?,?,?,?,?,?,?,?)',
              [$sid, $u['id'], $s['year_id'], $s['term'], $doc2['title'], $name, $doc2['html'], $size]);
            flash('Документът е изготвен и е готов за изтегляне.');
        } catch (Throwable $ex) {
            flash('Документът не бе създаден: ' . e($ex->getMessage()), 'err');
        }
        redirect(base_url('pages/deputy_inbox.php?term=' . $term . '&open=' . $sid));
    } elseif ($act === 'acknowledge') {
        q('UPDATE mo_summaries SET deputy_status="acknowledged", deputy_note=?, deputy_seen_at=NOW(),
                  deputy_acted_by=? WHERE id=?', [$note ?: null, $u['id'], $sid]);
        flash('Обобщението е прието. Методистът вижда, че е разгледано.');
    } elseif ($act === 'return') {
        if (mb_strlen($note) < 10) {
            flash('При връщане напишете какво трябва да се поправи – методистът вижда точно този текст.', 'err');
        } else {
            q('UPDATE mo_summaries SET status="draft", deputy_status="returned", deputy_note=?,
                      deputy_seen_at=NOW(), deputy_acted_by=?, sent_at=NULL WHERE id=?',
              [$note, $u['id'], $sid]);
            flash('Обобщението е върнато на методиста заедно с бележката.');
        }
    }
    /* след решение прегледът се затваря – списъкът остава отворен */
    redirect(base_url('pages/deputy_inbox.php?term=' . $term));
}

/* ------------------------------ данни ------------------------------ */
$P = [':y' => $yid, ':t' => $term];
if (!$seeAll) $P[':d'] = $u['id'];

$list = all(
    'SELECT s.*, d.name AS department_name,
            ' . user_name_sql('m') . ' AS methodist_name,
            ' . user_name_sql('f') . ' AS finalized_name,
            (SELECT COUNT(*) FROM mo_summary_entries se WHERE se.summary_id = s.id) AS n_entries,
            (SELECT COUNT(*) FROM mo_documents doc WHERE doc.summary_id = s.id)     AS n_docs
     FROM mo_summaries s
     LEFT JOIN mo_departments d ON d.id = s.department_id
     LEFT JOIN users m ON m.id = s.methodist_id
     LEFT JOIN users f ON f.id = s.finalized_by
     WHERE s.status = "sent" AND s.year_id = :y AND s.term = :t' . $where . '
     ORDER BY (s.deputy_status = "new") DESC, s.sent_at DESC', $P);

/* МО, които още не са изпратили – заради тях се чака */
$pending = all(
    'SELECT d.id, d.name,
            ' . user_name_sql('c') . ' AS chair_name,
            (SELECT COUNT(*) FROM mo_entries e
              WHERE e.department_id = d.id AND e.year_id = :y AND e.term = :t AND e.status = "sent") AS n_entries
     FROM mo_departments d
     LEFT JOIN users c ON c.id = d.chair_id
     WHERE d.is_active = 1
       AND NOT EXISTS (SELECT 1 FROM mo_summaries s
                        WHERE s.department_id = d.id AND s.year_id = :y2 AND s.term = :t2 AND s.status = "sent")
     ORDER BY d.name',
    [':y' => $yid, ':t' => $term, ':y2' => $yid, ':t2' => $term]);

/* обща картина за училището */
$tot = one(
    'SELECT COUNT(*) n_sum,
            SUM(s.deputy_status = "new") n_new,
            (SELECT COUNT(*) FROM mo_summary_entries se
              JOIN mo_summaries x ON x.id = se.summary_id
              WHERE x.year_id = :y AND x.term = :t AND x.status = "sent") n_entries
     FROM mo_summaries s WHERE s.status = "sent" AND s.year_id = :y2 AND s.term = :t2',
    [':y' => $yid, ':t' => $term, ':y2' => $yid, ':t2' => $term]);

$school = one(
    'SELECT COUNT(*) n, AVG(avg_grade) avg, COALESCE(SUM(c_mastered),0) ok,
            COALESCE(SUM(c_partial),0) part, COALESCE(SUM(c_failed),0) no_
     FROM mo_v_entries WHERE year_id = :y AND term = :t AND status = "sent"',
    [':y' => $yid, ':t' => $term]);

/* ------------------------------ отворено обобщение ------------------------------ */
$doc = null; $rows = []; $bySubject = []; $docs = [];
if ($open) {
    $doc = one('SELECT s.*, d.name AS department_name, y.label AS year_label,
                       ' . user_name_sql('m') . ' AS methodist_name,
                       ' . user_name_sql('f') . ' AS finalized_name
                FROM mo_summaries s
                LEFT JOIN mo_departments d ON d.id = s.department_id
                LEFT JOIN users m ON m.id = s.methodist_id
                LEFT JOIN users f ON f.id = s.finalized_by
                JOIN mo_years y ON y.id = s.year_id
                WHERE s.id = ? AND s.status = "sent"', [$open]);

    if ($doc && !$seeAll && $doc['deputy_id'] !== null && (int)$doc['deputy_id'] !== (int)$u['id']) {
        $doc = null;
        flash('Това обобщение е изпратено до друг зам-директор.', 'warn');
    }
    if ($doc) {
        $rows = all('SELECT v.* FROM mo_summary_entries se JOIN mo_v_entries v ON v.id = se.entry_id
                     WHERE se.summary_id = ? ORDER BY v.subject_name, v.grade_level, v.class_name', [$open]);
        $bySubject = all('SELECT v.subject_name, COUNT(*) n, AVG(v.avg_grade) avg,
                                 SUM(v.c_mastered) ok, SUM(v.c_partial) part, SUM(v.c_failed) no_
                          FROM mo_summary_entries se JOIN mo_v_entries v ON v.id = se.entry_id
                          WHERE se.summary_id = ? GROUP BY v.subject_name ORDER BY v.subject_name', [$open]);
        $docs = all('SELECT * FROM mo_documents WHERE summary_id = ? ORDER BY id DESC', [$open]);

        /* първото отваряне се отбелязва, за да се вижда че е разгледано */
        if ($doc['deputy_seen_at'] === null && !$seeAll) {
            q('UPDATE mo_summaries SET deputy_seen_at = NOW() WHERE id = ?', [$open]);
            $doc['deputy_seen_at'] = date('Y-m-d H:i:s');
        }
    }
}

/* обобщението на училището и последният му документ */
$SD       = school_summary_data($yid, $term);
$school_s = one('SELECT s.*, ' . user_name_sql('a') . ' AS author_name
                 FROM mo_school_summaries s LEFT JOIN users a ON a.id = s.author_id
                 WHERE s.year_id = ? AND s.term = ?', [$yid, $term]) ?? [];
$schoolDoc = !empty($school_s['id'])
    ? one('SELECT * FROM mo_documents WHERE school_summary_id = ? ORDER BY id DESC LIMIT 1', [$school_s['id']])
    : null;

function deputy_badge(string $st): string
{
    return [
        'new'          => '<span class="badge warn">за преглед</span>',
        'acknowledged' => '<span class="badge ok">прието</span>',
        'returned'     => '<span class="badge red">върнато</span>',
    ][$st] ?? '';
}

header_html('Обобщения от МО', 'deputy');
section_title('Обобщения от методическите обединения');
year_picker($term, $open ? ['open' => $open] : []);
?>

<div class="cards no-print">
  <div class="stat"><span class="k"><?= (int)$tot['n_sum'] ?>/<?= (int)$tot['n_sum'] + count($pending) ?></span>
    <span class="l">получени обобщения</span></div>
  <div class="stat"><span class="k"><?= (int)$tot['n_new'] ?></span><span class="l">чакат преглед</span></div>
  <div class="stat"><span class="k"><?= (int)$school['n'] ?></span><span class="l">анализа в училището</span></div>
  <div class="stat"><span class="k"><?= fmt_avg($school['avg']) ?></span><span class="l">среден успех</span></div>
  <div class="stat"><span class="k"><?= (int)$school['no_'] ?></span><span class="l">неусвоени компетентности</span></div>
</div>

<?php if ($pending): ?>
<div class="panel no-print">
  <h2>Още не са изпратили обобщение (<?= count($pending) ?>)</h2>
  <table class="grid">
    <thead><tr><th>Методическо обединение</th><th>Председател</th><th>Получени анализи</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $p): ?>
      <tr>
        <td><strong><?= e($p['name']) ?></strong></td>
        <td><?= e($p['chair_name'] ?: '—') ?><?= $p['chair_name'] ? '' : ' <span class="badge red">без председател</span>' ?></td>
        <td><?= (int)$p['n_entries'] ?>
            <?= (int)$p['n_entries'] ? '<span class="small muted">има какво да обобщи</span>'
                                     : '<span class="small muted">още няма анализи</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel no-print">
  <h2>Получени обобщения</h2>
  <table class="grid">
    <thead><tr><th>Методическо обединение</th><th>Изготвил</th><th>Анализи</th>
               <th>Изпратено</th><th>Състояние</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
      <tr class="<?= $open === (int)$r['id'] ? 'row-open' : '' ?>">
        <td><strong><?= e($r['department_name'] ?: '—') ?></strong>
            <br><small class="muted"><?= e($r['title'] ?: 'Обобщен анализ · ' . term_label($r['term'])) ?></small></td>
        <td class="small"><?= e($r['finalized_name'] ?: $r['methodist_name'] ?: '—') ?></td>
        <td><?= (int)$r['n_entries'] ?></td>
        <td class="small"><?= $r['sent_at'] ? e(date('d.m.Y H:i', strtotime($r['sent_at']))) : '' ?></td>
        <td><?= deputy_badge((string)$r['deputy_status']) ?>
            <?php if ($r['deputy_seen_at'] && $r['deputy_status'] === 'new'): ?>
              <br><small class="muted">отворено</small>
            <?php endif; ?></td>
        <td class="acts">
          <a class="btn small" href="<?= base_url('pages/deputy_inbox.php?term=' . $term . '&open=' . (int)$r['id']) ?>">Отвори</a>
          <?php if ((int)$r['n_docs']):
              $d1 = one('SELECT id FROM mo_documents WHERE summary_id = ? ORDER BY id DESC LIMIT 1', [$r['id']]); ?>
            <a class="btn small ghost" href="<?= base_url('pages/document.php?id=' . (int)$d1['id']) ?>">Изтегли</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?>
      <tr><td colspan="6" class="muted">Няма изпратени обобщения за този срок.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<section class="panel no-print" id="schoolSummary">
  <div class="sec-title">
    <h1 style="font-size:1.1rem">Обобщение на училището · <?= e(term_label($term)) ?></h1>
    <div class="right">
      <?php if ($schoolDoc): ?>
        <a class="btn small primary" href="<?= base_url('pages/document.php?id=' . (int)$schoolDoc['id']) ?>">
          Изтегли последния документ</a>
        <span class="small muted"><?= e(date('d.m.Y H:i', strtotime($schoolDoc['created_at']))) ?></span>
      <?php endif; ?>
      <?php if (($school_s['status'] ?? '') === 'final'): ?>
        <span class="badge ok">приключено</span>
      <?php endif; ?>
    </div>
  </div>

  <p class="muted small">Събира анализите на всички методически обединения за срока.
     Числата се смятат автоматично, а текстовете пишете вие.
     <?php if (!empty($school_s['author_name'])): ?>
       Последно записано от <strong><?= e($school_s['author_name']) ?></strong>.
     <?php endif; ?>
  </p>

  <div class="cards">
    <div class="stat"><span class="k"><?= (int)$SD['sum']['n'] ?></span><span class="l">анализа от <?= (int)$SD['sum']['teachers'] ?> учители</span></div>
    <div class="stat"><span class="k"><?= (int)$SD['sum']['deps'] ?></span><span class="l">МО с получени анализи</span></div>
    <div class="stat"><span class="k"><?= fmt_avg($SD['sum']['avg']) ?></span><span class="l">среден успех</span></div>
    <div class="stat"><span class="k"><?= (int)$SD['sum']['ok'] ?></span><span class="l">усвоени</span></div>
    <div class="stat"><span class="k"><?= (int)$SD['sum']['no'] ?></span><span class="l">неусвоени</span></div>
  </div>

  <table class="grid">
    <thead><tr><th>Методическо обединение</th><th>Председател</th><th>Анализи</th>
               <th>Ср. успех</th><th>Неусвоени</th><th>Обобщение</th></tr></thead>
    <tbody>
    <?php foreach ($SD['byDepartment'] as $r): ?>
      <tr>
        <td><strong><?= e($r['department_name']) ?></strong></td>
        <td class="small"><?= e($r['chair_name'] ?: '—') ?></td>
        <td><?= (int)$r['n'] ?></td>
        <td><?= fmt_avg($r['avg']) ?></td>
        <td class="<?= (int)$r['no'] ? 'danger' : '' ?>"><?= (int)$r['no'] ?></td>
        <td><?= $r['summary_id'] ? deputy_badge((string)$r['deputy_status']) : '<span class="badge">няма</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" id="schoolForm" data-no-entries="<?= ((int)$SD['sum']['n'] === 0) ? '1' : '0' ?>">
    <?= csrf_field() ?>
    <label>Заглавие
      <input type="text" name="title" value="<?= e($school_s['title'] ?? '') ?>"
             placeholder="Обобщен анализ на училището за <?= e(term_label($term)) ?>">
    </label>
    <label>Обща оценка за срока
      <textarea name="summary_text" rows="4"><?= e($school_s['summary_text'] ?? '') ?></textarea></label>
    <label>Силни страни
      <textarea name="strengths" rows="3"><?= e($school_s['strengths'] ?? '') ?></textarea></label>
    <label>Области за подобрение
      <textarea name="improvements" rows="3"><?= e($school_s['improvements'] ?? '') ?></textarea></label>
    <label>Мерки за следващия период
      <textarea name="measures" rows="3"><?= e($school_s['measures'] ?? '') ?></textarea></label>
    <label>Бележки
      <textarea name="notes" rows="2"><?= e($school_s['notes'] ?? '') ?></textarea></label>

    <div class="actions">
      <button class="btn" name="action" value="school_save" type="submit">Запази</button>
      <button class="btn" name="action" value="school_export" type="submit">Изготви Word документ</button>
      <button class="btn primary" name="action" value="school_final" type="submit"
              data-confirm="Обобщението се отбелязва като приключено и се изготвя Word документ. Може да го редактирате и след това."
              data-confirm-title="Приключване на обобщението" data-confirm-ok="Приключи">Приключи</button>
    </div>
  </form>
</section>

<?php if ($doc): ?>
<article class="panel">
  <div class="sec-title">
    <h1><?= e($doc['title'] ?: 'Обобщен анализ на МО') ?></h1>
    <div class="right no-print">
      <?php if ($docs): $dc = $docs[0]; ?>
        <a class="btn small primary" href="<?= base_url('pages/document.php?id=' . (int)$dc['id']) ?>">Изтегли Word</a>
        <a class="btn small ghost" href="<?= base_url('pages/document.php?id=' . (int)$dc['id'] . '&view=1') ?>">Преглед</a>
      <?php else: ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <button class="btn small" name="action" value="make_doc" type="submit">Изготви Word документ</button>
        </form>
        <span class="small muted">методистът още не е изготвил документ</span>
      <?php endif; ?>
    </div>
  </div>

  <p class="muted small">
    <strong><?= e($doc['department_name'] ?: '') ?></strong> ·
    изготвил: <?= e($doc['finalized_name'] ?: $doc['methodist_name'] ?: '—') ?> ·
    <?= e($doc['year_label']) ?> · <?= e(term_label($doc['term'])) ?> ·
    изпратено <?= e(date('d.m.Y', strtotime($doc['sent_at']))) ?>
    <?= deputy_badge((string)$doc['deputy_status']) ?>
  </p>

  <?php if (trim((string)$doc['deputy_note']) !== ''): ?>
    <div class="flash <?= $doc['deputy_status'] === 'returned' ? 'warn' : 'ok' ?>">
      <strong>Бележка от зам-директора:</strong> <?= nl2br(e($doc['deputy_note'])) ?>
    </div>
  <?php endif; ?>

  <?php if (trim((string)$doc['summary_text']) !== ''): ?>
    <h2>Обобщение на МО</h2><p><?= nl2br(e($doc['summary_text'])) ?></p>
  <?php endif; ?>
  <h2>Силни страни</h2><p><?= nl2br(e($doc['strengths'])) ?: '<span class="muted">не е попълнено</span>' ?></p>
  <h2>Области за подобрение</h2><p><?= nl2br(e($doc['improvements'])) ?: '<span class="muted">не е попълнено</span>' ?></p>
  <h2>Мерки</h2><p><?= nl2br(e($doc['measures'])) ?: '<span class="muted">не е попълнено</span>' ?></p>
  <?php if (trim((string)$doc['notes']) !== ''): ?>
    <h2>Бележки на методиста</h2><p><?= nl2br(e($doc['notes'])) ?></p>
  <?php endif; ?>
  <?php if (trim((string)$doc['other']) !== ''): ?>
    <h2>Други</h2><p><?= nl2br(e($doc['other'])) ?></p>
  <?php endif; ?>

  <?php if ($bySubject): ?>
    <h2>По предмет</h2>
    <table class="grid">
      <thead><tr><th>Предмет</th><th>Анализи</th><th>Ср. успех</th>
                 <th>Усвоени</th><th>Частично</th><th>Неусвоени</th></tr></thead>
      <tbody>
      <?php foreach ($bySubject as $b): ?>
        <tr><td><?= e($b['subject_name']) ?></td><td><?= (int)$b['n'] ?></td>
            <td><?= fmt_avg($b['avg']) ?></td><td><?= (int)$b['ok'] ?></td>
            <td><?= (int)$b['part'] ?></td>
            <td class="<?= (int)$b['no_'] ? 'danger' : '' ?>"><?= (int)$b['no_'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>Анализи в основата на обобщението (<?= count($rows) ?>)</h2>
  <table class="grid">
    <thead><tr><th>Учител</th><th>Паралелка</th><th>Предмет</th><th>Усвоени</th>
               <th>Частично</th><th>Неусвоени</th><th>Ср. успех</th><th>Мерки</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['teacher_name']) ?></td><td><?= e($r['class_name']) ?></td>
          <td><?= e($r['subject_name']) ?></td><td><?= (int)$r['c_mastered'] ?></td>
          <td><?= (int)$r['c_partial'] ?></td>
          <td class="<?= (int)$r['c_failed'] ? 'danger' : '' ?>"><?= (int)$r['c_failed'] ?></td>
          <td><?= fmt_avg($r['avg_grade']) ?></td>
          <td class="small"><?= e(mb_strimwidth((string)$r['measures'], 0, 70, '…')) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Обобщението не сочи към конкретни анализи.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <form method="post" class="no-print" id="deputyForm" style="margin-top:1.2rem">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
    <h2>Решение</h2>
    <label>Бележка към методиста
      <textarea name="deputy_note" rows="3"
        placeholder="При връщане напишете какво да се поправи. При приемане бележката е по желание."><?= e($doc['deputy_note'] ?? '') ?></textarea>
    </label>
    <div class="actions">
      <button class="btn primary" name="action" value="acknowledge" type="submit"
              data-confirm="Обобщението се отбелязва като прието. Методистът ще види, че е разгледано."
              data-confirm-title="Приемане на обобщението" data-confirm-ok="Приеми">Приеми</button>
      <button class="btn danger" name="action" value="return" type="submit" data-danger
              data-confirm="Обобщението се връща на методиста за доработка заедно с бележката ви и излиза от списъка ви, докато не го изпрати отново."
              data-confirm-title="Връщане за доработка" data-confirm-ok="Върни">Върни за доработка</button>
    </div>
  </form>
</article>
<?php endif; ?>
<?php footer_html(); ?>
