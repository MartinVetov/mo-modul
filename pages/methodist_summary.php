<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../lib/Ai.php';
require_once __DIR__ . '/../lib/DocxWriter.php';
require_once __DIR__ . '/../inc/summary_data.php';

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
$depId  = (int)($_GET['dep'] ?? $_POST['dep'] ?? 0);
if (!in_array($depId, $depIds, true)) {
    $depId = $ledIds[0] ?? $depIds[0];   // първо своето, чак после чуждо
}
$dep    = one('SELECT * FROM mo_departments WHERE id = ?', [$depId]);
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? $_POST['term'] ?? 'I');

$D = summary_data($depId, $yid, $term);

$deputies = all('SELECT u.id, ' . user_name_sql('u') . ' AS name FROM mo_user_roles r
                 JOIN users u ON u.id = r.user_id WHERE r.role = "deputy" ORDER BY name');

/* ------------------------------ действия ------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    q('INSERT IGNORE INTO mo_summaries (department_id, methodist_id, year_id, term) VALUES (?,?,?,?)',
      [$depId, $u['id'], $yid, $term]);
    $sid = (int)one('SELECT id FROM mo_summaries WHERE department_id=? AND year_id=? AND term=?',
                    [$depId, $yid, $term])['id'];

    if ($action === 'ai') {
        try {
            if ($D['sum']['n'] == 0) throw new RuntimeException('Няма получени анализи за този срок.');
            $text = Ai::summarize(Ai::buildPrompt(ai_context($D, $dep['name'], $term)));
            q('UPDATE mo_summaries SET ai_draft=?, ai_generated_at=NOW() WHERE id=?', [$text, $sid]);
            flash('Готова е AI чернова. Прегледайте я и я поправете, преди да я използвате.');
        } catch (Throwable $ex) {
            flash('AI: ' . e($ex->getMessage()), 'err');
        }
        redirect(base_url('pages/methodist_summary.php?term=' . $term . '&dep=' . $depId));
    }

    /* запис на текстовете */
    $fields = ['title', 'summary_text', 'strengths', 'improvements', 'measures', 'other', 'notes'];
    $vals = [];
    foreach ($fields as $f) $vals[$f] = trim((string)($_POST[$f] ?? ''));

    $deputyId = ($_POST['deputy_id'] ?? '') !== '' ? (int)$_POST['deputy_id'] : null;
    $status = $action === 'send' ? 'sent' : 'draft';

    if ($status === 'sent' && !$deputyId) {
        flash('Изберете зам-директор, до когото да изпратите обобщението.', 'err');
        redirect(base_url('pages/methodist_summary.php?term=' . $term . '&dep=' . $depId));
    }

    q('UPDATE mo_summaries SET title=?, summary_text=?, strengths=?, improvements=?, measures=?,
              other=?, notes=?, status=?, deputy_id=?, finalized_by=' . (int)$u['id'] . ',
              sent_at = CASE WHEN ?="sent" THEN NOW() ELSE sent_at END
       WHERE id=?',
      [$vals['title'], $vals['summary_text'], $vals['strengths'], $vals['improvements'],
       $vals['measures'], $vals['other'], $vals['notes'], $status, $deputyId, $status, $sid]);

    q('DELETE FROM mo_summary_entries WHERE summary_id = ?', [$sid]);
    q('INSERT INTO mo_summary_entries (summary_id, entry_id)
       SELECT ?, id FROM mo_entries WHERE department_id=? AND status="sent" AND year_id=? AND term=?',
      [$sid, $depId, $yid, $term]);

    /* --- експорт в Word --- */
    if ($action === 'export' || $action === 'send') {
        try {
            $rep = one('SELECT * FROM mo_summaries WHERE id = ?', [$sid]);
            $doc = build_summary_doc($D, $rep, $u, $term);

            $safe = preg_replace('/[^\p{L}\p{N}\-_ ]/u', '', $doc['title']) ?: 'obobshtenie';
            $name = mb_substr($safe, 0, 60) . ' - ' . term_label($term) . ' - ' . date('Y-m-d His') . '.docx';
            $path = docs_dir() . '/' . $name;
            $size = $doc['docx']->save($path);

            q('INSERT INTO mo_documents (summary_id, user_id, year_id, term, title, filename, html_snapshot, size_bytes)
               VALUES (?,?,?,?,?,?,?,?)',
              [$sid, $u['id'], $yid, $term, $doc['title'], $name, $doc['html'], $size]);

            flash(($action === 'send' ? 'Обобщението е изпратено до зам-директора. ' : '')
                . 'Документът е изготвен и е в „Моите документи“.');
        } catch (Throwable $ex) {
            flash('Документът не бе създаден: ' . e($ex->getMessage()), 'err');
        }
    } else {
        flash('Обобщението е запазено.');
    }
    redirect(base_url('pages/methodist_summary.php?term=' . $term . '&dep=' . $depId));
}

$rep = one('SELECT s.*, ' . user_name_sql('f') . ' AS finalized_name
            FROM mo_summaries s LEFT JOIN users f ON f.id = s.finalized_by
            WHERE s.department_id=? AND s.year_id=? AND s.term=?', [$depId, $yid, $term]) ?? [];
$docs = all('SELECT * FROM mo_documents WHERE year_id=? AND term=? AND summary_id=?
             ORDER BY id DESC LIMIT 3', [$yid, $term, (int)($rep['id'] ?? 0)]);

header_html('Обобщение', 'sum');
$myRole = department_role_bg(department_role($depId, $u));
section_title('Обобщение · ' . ($dep['name'] ?? '') . ($myRole ? ' (' . $myRole . ')' : ''),
    '<a class="btn small" href="' . base_url('pages/methodist_docs.php') . '">Моите документи</a>');
year_picker($term, ['dep' => $depId]);

/* Меню за смяна на МО – показва се, когато човекът ръководи повече от едно
   (или е администратор и вижда всички). Досега МО-то се сменяше само чрез
   ръчна промяна на адреса. */
if (count($myDeps) > 1): ?>
  <form class="picker no-print" method="get">
    <input type="hidden" name="term" value="<?= e($term) ?>">
    <label>Методическо обединение
      <select name="dep" onchange="this.form.submit()">
        <?php foreach ($myDeps as $d):
            $role = department_role_bg(department_role((int)$d['id'], $u));
            $n = (int)(one('SELECT COUNT(*) n FROM mo_entries
                            WHERE department_id=? AND year_id=? AND term=? AND status="sent"',
                           [$d['id'], $yid, $term])['n'] ?? 0); ?>
          <option value="<?= (int)$d['id'] ?>" <?= $depId === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e($d['name']) ?><?= $role ? ' · ' . e($role) : '' ?> · <?= $n ?> анализа
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
<?php endif;
?>

<div class="cards">
  <div class="stat"><span class="k"><?= (int)$D['sum']['n'] ?></span><span class="l">анализа от <?= (int)$D['sum']['teachers'] ?> учители</span></div>
  <div class="stat"><span class="k"><?= fmt_avg($D['sum']['avg']) ?></span><span class="l">среден успех</span></div>
  <div class="stat"><span class="k"><?= (int)$D['sum']['ok'] ?></span><span class="l">усвоени</span></div>
  <div class="stat"><span class="k"><?= (int)$D['sum']['partial'] ?></span><span class="l">частично усвоени</span></div>
  <div class="stat"><span class="k"><?= (int)$D['sum']['no'] ?></span><span class="l">неусвоени</span></div>
</div>

<div class="panel">
  <h2>По предмет и специалност</h2>
  <table class="grid">
    <thead><tr><th>Предмет</th><th>Професия/специалност</th><th>Анализи</th><th>Ср. успех</th>
               <th>Усвоени</th><th>Частично</th><th>Неусвоени</th></tr></thead>
    <tbody>
    <?php foreach ($D['bySubject'] as $r): ?>
      <tr><td><?= e($r['subject_name']) ?></td><td class="small muted"><?= e($r['program_name'] ?: '—') ?></td>
          <td><?= (int)$r['n'] ?></td><td><?= fmt_avg($r['avg']) ?></td>
          <td><?= (int)$r['ok'] ?></td><td><?= (int)$r['partial'] ?></td>
          <td class="<?= (int)$r['no'] ? 'danger' : '' ?>"><?= (int)$r['no'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$D['bySubject']): ?><tr><td colspan="7" class="muted">Няма получени анализи.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($D['failed']): ?>
<div class="panel">
  <h2>Най-проблемни компетентности</h2>
  <table class="grid">
    <thead><tr><th>Компетентност</th><th>Предмет</th><th>Клас</th><th>Частично</th><th>Неусвоена</th></tr></thead>
    <tbody>
    <?php foreach ($D['failed'] as $f): ?>
      <tr><td><?= e($f['title']) ?></td><td><?= e($f['subject_name']) ?></td>
          <td><?= (int)$f['grade_level'] ?></td><td><?= (int)$f['partial'] ?></td>
          <td class="danger"><?= (int)$f['failed'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($D['measures']): ?>
<div class="panel">
  <h2>Мерки, предложени от учителите</h2>
  <ul class="small">
    <?php foreach ($D['measures'] as $m): ?>
      <li><strong><?= e($m['teacher_name']) ?></strong> (<?= e($m['subject_name']) ?>, <?= e($m['class_name']) ?>):
          <?= e($m['measures']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (($rep['deputy_status'] ?? '') === 'returned' && trim((string)($rep['deputy_note'] ?? '')) !== ''): ?>
  <div class="flash warn">
    <strong>Върнато за доработка от зам-директора</strong>
    <?= !empty($rep['deputy_seen_at']) ? ' на ' . e(date('d.m.Y', strtotime($rep['deputy_seen_at']))) : '' ?>:
    <br><?= nl2br(e($rep['deputy_note'])) ?>
    <br><span class="small">Поправете обобщението и го изпратете отново.</span>
  </div>
<?php elseif (($rep['deputy_status'] ?? '') === 'acknowledged'): ?>
  <div class="flash ok">
    Обобщението е прието от зам-директора<?= !empty($rep['deputy_seen_at']) ? ' на ' . e(date('d.m.Y', strtotime($rep['deputy_seen_at']))) : '' ?>.
    <?php if (trim((string)($rep['deputy_note'] ?? '')) !== ''): ?>
      <br><strong>Бележка:</strong> <?= nl2br(e($rep['deputy_note'])) ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" id="summaryForm" class="panel" data-no-entries="<?= ((int)$D['sum']['n'] === 0) ? '1' : '0' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="term" value="<?= e($term) ?>">
  <input type="hidden" name="dep" value="<?= (int)$depId ?>">
  <h2>Текст на обобщението</h2>
  <?php if (!empty($rep['finalized_name'])): ?>
    <p class="muted small">Последно записано от <strong><?= e($rep['finalized_name']) ?></strong>.
       Председателят и заместникът работят по един и същ доклад.</p>
  <?php endif; ?>
  <label>Заглавие
    <input type="text" name="title" value="<?= e($rep['title'] ?? '') ?>"
           placeholder="Обобщен анализ на МО … за <?= e(term_label($term)) ?>">
  </label>
  <label>Обобщение на цялото методическо обединение
    <textarea name="summary_text" rows="5"><?= e($rep['summary_text'] ?? '') ?></textarea></label>
  <label>Силни страни<textarea name="strengths" rows="4"><?= e($rep['strengths'] ?? '') ?></textarea></label>
  <label>Области за подобрение<textarea name="improvements" rows="4"><?= e($rep['improvements'] ?? '') ?></textarea></label>
  <label>Мерки на методическото обединение<textarea name="measures" rows="4"><?= e($rep['measures'] ?? '') ?></textarea></label>
  <label>Бележки на методиста<textarea name="notes" rows="3"><?= e($rep['notes'] ?? '') ?></textarea></label>
  <label>Други<textarea name="other" rows="3"><?= e($rep['other'] ?? '') ?></textarea></label>

  <label>Изпрати до зам-директор
    <select name="deputy_id">
      <option value="">– избери –</option>
      <?php foreach ($deputies as $d): ?>
        <option value="<?= (int)$d['id'] ?>" <?= (int)($rep['deputy_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
          <?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if (!$deputies): ?>
    <p class="small danger">Няма назначени зам-директори. Администрацията ги задава от „Роли и права“.</p>
  <?php endif; ?>

  <div class="actions">
    <button class="btn" name="action" value="save" type="submit">Запази</button>
    <button class="btn" name="action" value="export" type="submit">Изготви Word документ</button>
    <button class="btn primary" name="action" value="send" type="submit"
            data-confirm="Обобщението се изпраща до избрания зам-директор. Word документът се създава автоматично и остава в „Моите документи“."
            data-confirm-title="Изпращане на обобщението" data-confirm-ok="Изпрати">
      Изпрати</button>
    <?php if (Ai::available()): ?>
      <button class="btn" name="action" value="ai" type="submit">Генерирай AI чернова</button>
    <?php endif; ?>
    <?php if (($rep['status'] ?? '') === 'sent'): ?>
      <span class="badge ok">изпратено на <?= e(date('d.m.Y', strtotime($rep['sent_at']))) ?></span>
    <?php endif; ?>
  </div>
</form>

<?php if ($docs): ?>
<div class="panel">
  <h2>Последни документи за този срок</h2>
  <div class="doc-list">
    <?php foreach ($docs as $d): ?>
      <div class="doc">
        <span class="ic">DOCX</span>
        <span class="meta"><strong><?= e($d['title']) ?></strong>
          <small><?= e(date('d.m.Y H:i', strtotime($d['created_at']))) ?> · <?= round($d['size_bytes'] / 1024, 1) ?> KB</small></span>
        <span class="acts">
          <a class="btn small ghost" href="<?= base_url('pages/document.php?id=' . (int)$d['id'] . '&view=1') ?>">Преглед</a>
          <a class="btn small primary" href="<?= base_url('pages/document.php?id=' . (int)$d['id']) ?>">Изтегли</a>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($rep['ai_draft'])): ?>
<div class="panel no-print">
  <h2>AI чернова <span class="muted small">от <?= e(date('d.m.Y H:i', strtotime($rep['ai_generated_at']))) ?></span></h2>
  <pre id="aiText" style="white-space:pre-wrap;font:inherit;background:#f8fafc;padding:.9rem;border-radius:8px"><?= e($rep['ai_draft']) ?></pre>
  <button class="btn small" type="button" id="copyAi">Копирай в полетата</button>
</div>
<script>
document.getElementById('copyAi').addEventListener('click', function () {
  var t = document.getElementById('aiText').textContent;
  var f = document.getElementById('summaryForm');
  var parts = t.split(/\n(?=\s*[1-4]\s*[.)])/);
  ['strengths','improvements','measures','other'].forEach(function (name, i) {
    if (parts[i] && f.elements[name]) {
      f.elements[name].value = parts[i].replace(/^\s*[1-4]\s*[.)]\s*[^\n]*\n?/, '').trim();
    }
  });
  MO.alert({ title: 'Готово', text: 'Черновата е прехвърлена в полетата. Прегледайте я, поправете я и натиснете „Запази“.' });
});
</script>
<?php endif; ?>
<?php footer_html(); ?>
