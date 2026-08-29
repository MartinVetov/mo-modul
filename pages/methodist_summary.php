<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../lib/Ai.php';

$u = require_role('methodist');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? $_POST['term'] ?? 'I');
$P = [':m' => $u['id'], ':y' => $yid, ':t' => $term];

/* ------------------------- данни за обобщението ------------------------- */
$sum = one('SELECT COUNT(*) n, COUNT(DISTINCT user_id) teachers, AVG(avg_grade) avg,
                   COALESCE(SUM(total_grades),0) grades, COALESCE(SUM(g2),0) weak,
                   COALESCE(SUM(c_mastered),0) ok, COALESCE(SUM(c_failed),0) no_
            FROM mo_v_entries WHERE methodist_id=:m AND status="sent" AND year_id=:y AND term=:t', $P);

$bySubject = all('SELECT subject_name, COUNT(*) n, AVG(avg_grade) avg,
                         SUM(c_mastered) ok, SUM(c_failed) no_
                  FROM mo_v_entries WHERE methodist_id=:m AND status="sent" AND year_id=:y AND term=:t
                  GROUP BY subject_name ORDER BY subject_name', $P);

$byClass = all('SELECT class_name, grade_level, COUNT(*) n, AVG(avg_grade) avg, SUM(c_failed) no_
                FROM mo_v_entries WHERE methodist_id=:m AND status="sent" AND year_id=:y AND term=:t
                GROUP BY class_name, grade_level ORDER BY grade_level, class_name', $P);

$failed = all('SELECT k.title, k.code, s.name subject_name, c.grade_level, SUM(ec.state="not_mastered") failed
               FROM mo_entry_competencies ec
               JOIN mo_competencies k ON k.id = ec.competency_id
               JOIN mo_subjects s ON s.id = k.subject_id
               JOIN mo_entries e ON e.id = ec.entry_id
               JOIN mo_classes c ON c.id = e.class_id
               WHERE e.methodist_id=:m AND e.status="sent" AND e.year_id=:y AND e.term=:t
               GROUP BY k.id, k.title, k.code, s.name, c.grade_level
               HAVING failed > 0 ORDER BY failed DESC LIMIT 15', $P);

$notes = all('SELECT teacher_name, subject_name, class_name, note FROM mo_v_entries
              WHERE methodist_id=:m AND status="sent" AND year_id=:y AND term=:t
                AND note IS NOT NULL AND note <> ""', $P);

$deputies = all('SELECT u.id, ' . user_name_sql('u') . ' AS name FROM mo_user_roles r
                 JOIN users u ON u.id = r.user_id WHERE r.role = "deputy" ORDER BY name');

/* ------------------------------ действия ------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    q('INSERT IGNORE INTO mo_summaries (methodist_id, year_id, term) VALUES (?,?,?)', [$u['id'], $yid, $term]);

    if ($action === 'ai') {
        try {
            if ((int)$sum['n'] === 0) throw new RuntimeException('Няма получени анализи за този срок.');
            $g = max(1, (int)$sum['grades']);
            $ctx = [
                'department' => 'МО с методист ' . $u['display_name'],
                'year'  => one('SELECT label FROM mo_years WHERE id=?', [$yid])['label'] ?? '',
                'term'  => term_label($term),
                'records' => (int)$sum['n'],
                'grades'  => (int)$sum['grades'],
                'avg'     => fmt_avg($sum['avg']),
                'weak'    => (int)$sum['weak'],
                'weak_pct' => fmt_pct((int)$sum['weak'] / $g),
                'top'     => (int)$sum['ok'],
                'top_pct' => 'усвоени компетентности: ' . (int)$sum['ok'] . ', неусвоени: ' . (int)$sum['no_'],
                'by_subject' => array_map(static fn($r) => [
                    'subject_name' => $r['subject_name'], 'avg' => fmt_avg($r['avg']),
                    'weak_pct' => 'неусвоени ' . (int)$r['no_'], 'records' => (int)$r['n']], $bySubject),
                'topics' => array_map(static fn($n) => [
                    'subject_name' => $n['subject_name'], 'class_name' => $n['class_name'],
                    'weakest_topic' => mb_strimwidth((string)$n['note'], 0, 200, '…'),
                    'reason' => '', 'measure' => ''], $notes),
                'competencies' => array_map(static fn($f) => [
                    'title' => $f['title'] . ' (' . $f['subject_name'] . ', ' . (int)$f['grade_level'] . ' кл.)',
                    'ok' => 0, 'part' => 0, 'no' => (int)$f['failed']], $failed),
            ];
            $text = Ai::summarize(Ai::buildPrompt($ctx));
            q('UPDATE mo_summaries SET ai_draft=?, ai_generated_at=NOW() WHERE methodist_id=? AND year_id=? AND term=?',
              [$text, $u['id'], $yid, $term]);
            flash('Готова е AI чернова. Прочетете я и я поправете, преди да я изпратите.');
        } catch (Throwable $ex) {
            flash('AI: ' . e($ex->getMessage()), 'err');
        }
    } else {
        $status = $action === 'send' ? 'sent' : 'draft';
        $dep = ($_POST['deputy_id'] ?? '') !== '' ? (int)$_POST['deputy_id'] : null;
        if ($status === 'sent' && !$dep) {
            flash('Изберете зам-директор, до когото да изпратите обобщението.', 'err');
        } else {
            q('UPDATE mo_summaries SET title=?, strengths=?, improvements=?, measures=?, other=?,
                     status=?, deputy_id=?, sent_at = CASE WHEN ?="sent" THEN NOW() ELSE sent_at END
               WHERE methodist_id=? AND year_id=? AND term=?',
              [trim((string)($_POST['title'] ?? '')), trim((string)($_POST['strengths'] ?? '')),
               trim((string)($_POST['improvements'] ?? '')), trim((string)($_POST['measures'] ?? '')),
               trim((string)($_POST['other'] ?? '')), $status, $dep, $status,
               $u['id'], $yid, $term]);

            // кои анализи влизат в обобщението
            $sid = (int)one('SELECT id FROM mo_summaries WHERE methodist_id=? AND year_id=? AND term=?',
                            [$u['id'], $yid, $term])['id'];
            q('DELETE FROM mo_summary_entries WHERE summary_id = ?', [$sid]);
            q('INSERT INTO mo_summary_entries (summary_id, entry_id)
               SELECT ?, id FROM mo_entries WHERE methodist_id=? AND status="sent" AND year_id=? AND term=?',
              [$sid, $u['id'], $yid, $term]);

            flash($status === 'sent' ? 'Обобщението е изпратено до зам-директора.' : 'Обобщението е запазено.');
        }
    }
    redirect(base_url('pages/methodist_summary.php?term=' . $term));
}

$rep = one('SELECT * FROM mo_summaries WHERE methodist_id=? AND year_id=? AND term=?', [$u['id'], $yid, $term]) ?? [];

header_html('Обобщение', 'sum');
section_title('Обобщение на методиста',
    '<button class="btn small" type="button" onclick="window.print()">Печат / PDF</button>');
year_picker($term);
?>

<div class="cards">
  <div class="stat"><span class="k"><?= (int)$sum['n'] ?></span><span class="l">анализа от <?= (int)$sum['teachers'] ?> учители</span></div>
  <div class="stat"><span class="k"><?= fmt_avg($sum['avg']) ?></span><span class="l">среден успех</span></div>
  <div class="stat"><span class="k"><?= (int)$sum['ok'] ?></span><span class="l">усвоени компетентности</span></div>
  <div class="stat"><span class="k"><?= (int)$sum['no_'] ?></span><span class="l">неусвоени компетентности</span></div>
</div>

<div class="panel">
  <h2>По предмет</h2>
  <table class="grid">
    <thead><tr><th>Предмет</th><th>Анализи</th><th>Ср. успех</th><th>Усвоени</th><th>Неусвоени</th></tr></thead>
    <tbody>
    <?php foreach ($bySubject as $r): ?>
      <tr><td><?= e($r['subject_name']) ?></td><td><?= (int)$r['n'] ?></td>
          <td><?= fmt_avg($r['avg']) ?></td><td><?= (int)$r['ok'] ?></td>
          <td class="<?= (int)$r['no_'] ? 'danger' : '' ?>"><?= (int)$r['no_'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$bySubject): ?><tr><td colspan="5" class="muted">Няма получени анализи.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h2>По паралелка</h2>
  <table class="grid">
    <thead><tr><th>Паралелка</th><th>Анализи</th><th>Ср. успех</th><th>Неусвоени компетентности</th></tr></thead>
    <tbody>
    <?php foreach ($byClass as $r): ?>
      <tr><td><strong><?= e($r['class_name']) ?></strong></td><td><?= (int)$r['n'] ?></td>
          <td><?= fmt_avg($r['avg']) ?></td>
          <td class="<?= (int)$r['no_'] ? 'danger' : '' ?>"><?= (int)$r['no_'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($failed): ?>
<div class="panel">
  <h2>Най-проблемни компетентности</h2>
  <table class="grid">
    <thead><tr><th>Компетентност</th><th>Предмет</th><th>Клас</th><th>Неусвоена</th></tr></thead>
    <tbody>
    <?php foreach ($failed as $f): ?>
      <tr><td><?= e($f['title']) ?></td><td><?= e($f['subject_name']) ?></td>
          <td><?= (int)$f['grade_level'] ?></td><td class="danger"><?= (int)$f['failed'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($notes): ?>
<div class="panel">
  <h2>Бележки от учителите</h2>
  <ul class="small">
    <?php foreach ($notes as $n): ?>
      <li><strong><?= e($n['teacher_name']) ?></strong> (<?= e($n['subject_name']) ?>, <?= e($n['class_name']) ?>):
          <?= e($n['note']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" id="summaryForm" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="term" value="<?= e($term) ?>">
  <h2>Текст на обобщението</h2>
  <label>Заглавие
    <input type="text" name="title" value="<?= e($rep['title'] ?? '') ?>"
           placeholder="Обобщен анализ на МО … за <?= e(term_label($term)) ?>">
  </label>
  <label>Силни страни<textarea name="strengths" rows="4"><?= e($rep['strengths'] ?? '') ?></textarea></label>
  <label>Области за подобрение<textarea name="improvements" rows="4"><?= e($rep['improvements'] ?? '') ?></textarea></label>
  <label>Мерки за следващия период<textarea name="measures" rows="4"><?= e($rep['measures'] ?? '') ?></textarea></label>
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
    <p class="small danger">Няма назначени зам-директори. Администрацията ги задава от „Роли и методисти“.</p>
  <?php endif; ?>

  <div class="actions">
    <button class="btn" name="action" value="save" type="submit">Запази</button>
    <button class="btn primary" name="action" value="send" type="submit"
            onclick="return confirm('Изпращане на обобщението до зам-директора?')">Изпрати</button>
    <?php if (Ai::available()): ?>
      <button class="btn" name="action" value="ai" type="submit"
              onclick="return confirm('Изпращане на обобщените числа и бележките към AI услугата?')">Генерирай AI чернова</button>
    <?php else: ?>
      <span class="muted small">AI черновата е изключена в config.php.</span>
    <?php endif; ?>
    <?php if (($rep['status'] ?? '') === 'sent'): ?>
      <span class="badge ok">изпратено на <?= e(date('d.m.Y', strtotime($rep['sent_at']))) ?></span>
    <?php endif; ?>
  </div>
</form>

<?php if (!empty($rep['ai_draft'])): ?>
<div class="panel no-print">
  <h2>AI чернова <span class="muted small">от <?= e(date('d.m.Y H:i', strtotime($rep['ai_generated_at']))) ?></span></h2>
  <p class="muted small">Помощно средство. Отговорността за текста остава на методиста.</p>
  <pre id="aiText" style="white-space:pre-wrap; font:inherit; background:#f8fafc; padding:.9rem; border-radius:8px"><?= e($rep['ai_draft']) ?></pre>
  <button class="btn small" type="button" id="copyAi">Копирай в полетата</button>
</div>
<script>
document.getElementById('copyAi').addEventListener('click', function () {
  var t = document.getElementById('aiText').textContent;
  var f = document.getElementById('summaryForm');
  var parts = t.split(/\n(?=\s*[1-4]\s*[.)])/);
  ['strengths','improvements','measures','other'].forEach(function (name, i) {
    if (!parts[i] || !f.elements[name]) return;
    f.elements[name].value = parts[i].replace(/^\s*[1-4]\s*[.)]\s*[^\n]*\n?/, '').trim();
  });
  alert('Черновата е прехвърлена. Прегледайте я и натиснете „Запази“.');
});
</script>
<?php endif; ?>
<?php footer_html(); ?>
