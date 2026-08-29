<?php
/**
 * Въвеждане на анализ – по образец на „Добавяне на лекторски часове“ във ВИС.
 * Всеки ред = паралелка + предмет + група. Компетентностите се зареждат
 * с AJAX според избраните клас и предмет.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('teacher');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');

if (!$yid) { flash('Администраторът още не е създал учебна година.', 'err'); }

/* ------------------------------ запис ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'draft';
    $rows   = $_POST['row'] ?? [];
    $status = $action === 'send' ? 'sent' : 'draft';
    $mid    = methodist_of((int)$u['id'], $yid);

    if ($status === 'sent' && !$mid) {
        flash('Няма назначен методист за вас. Обърнете се към администрацията – анализът е запазен като чернова.', 'warn');
        $status = 'draft';
    }

    $saved = 0; $errors = [];
    db()->beginTransaction();
    try {
        foreach ($rows as $i => $r) {
            $classId = (int)($r['class_id'] ?? 0);
            $subjId  = (int)($r['subject_id'] ?? 0);
            if (!$classId || !$subjId) {
                if ($classId || $subjId) $errors[] = 'Ред ' . ((int)$i + 1) . ': изберете и паралелка, и предмет.';
                continue;
            }
            $group = in_array($r['group_no'] ?? '0', ['0', '1', '2'], true) ? $r['group_no'] : '0';

            $num = static fn($k) => max(0, (int)($r[$k] ?? 0));
            $params = [
                ':uid' => $u['id'], ':yid' => $yid, ':term' => $term,
                ':cid' => $classId, ':sid' => $subjId, ':grp' => $group,
                ':st'  => $num('students_count'),
                ':g2'  => $num('g2'), ':g3' => $num('g3'), ':g4' => $num('g4'),
                ':g5'  => $num('g5'), ':g6' => $num('g6'),
                ':note' => trim((string)($r['note'] ?? '')),
                ':status' => $status,
                ':mid' => $status === 'sent' ? $mid : null,
            ];
            q('INSERT INTO mo_entries
                 (user_id, year_id, term, class_id, subject_id, group_no,
                  students_count, g2,g3,g4,g5,g6, note, status, methodist_id, sent_at)
               VALUES (:uid,:yid,:term,:cid,:sid,:grp,:st,:g2,:g3,:g4,:g5,:g6,:note,:status,:mid,
                  CASE WHEN :status2 = "sent" THEN NOW() ELSE NULL END)
               ON DUPLICATE KEY UPDATE
                  students_count=VALUES(students_count), g2=VALUES(g2), g3=VALUES(g3),
                  g4=VALUES(g4), g5=VALUES(g5), g6=VALUES(g6), note=VALUES(note),
                  status=VALUES(status), methodist_id=VALUES(methodist_id),
                  sent_at = CASE WHEN VALUES(status)="sent" THEN NOW() ELSE sent_at END',
              $params + [':status2' => $status]);

            $eid = (int)(one('SELECT id FROM mo_entries WHERE user_id=? AND year_id=? AND term=?
                              AND class_id=? AND subject_id=? AND group_no=?',
                             [$u['id'], $yid, $term, $classId, $subjId, $group])['id'] ?? 0);
            if (!$eid) { $errors[] = 'Ред ' . ((int)$i + 1) . ': записът не бе намерен след запис.'; continue; }

            // отметките: пишат се само маркираните; липсата = прехвърля се за II срок
            q('DELETE FROM mo_entry_competencies WHERE entry_id = ?', [$eid]);
            $ins = db()->prepare('INSERT INTO mo_entry_competencies (entry_id, competency_id, state) VALUES (?,?,?)');
            foreach (($r['comp'] ?? []) as $cid => $state) {
                if (!isset(STATES[(string)$state])) continue;
                $ins->execute([$eid, (int)$cid, $state]);
            }
            $saved++;
        }
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        flash('Грешка при запис: ' . e($ex->getMessage()), 'err');
        redirect(base_url('pages/entry.php?term=' . $term));
    }

    $msg = $status === 'sent'
        ? "Изпратени са $saved анализа към методиста."
        : "Запазени са $saved реда като чернова.";
    if ($errors) $msg .= ' ' . e(implode(' ', $errors));
    flash($msg, $errors ? 'warn' : 'ok');
    redirect(base_url('pages/' . ($status === 'sent' ? 'my_entries.php' : 'entry.php') . '?term=' . $term));
}

/* ---------------------------- зареждане ---------------------------- */
$classes  = all('SELECT * FROM mo_classes WHERE is_active = 1 ORDER BY grade_level, letter');
$subjects = all('SELECT * FROM mo_subjects WHERE is_active = 1 ORDER BY name');

$existing = $yid ? all(
    'SELECT e.*, c.grade_level FROM mo_entries e
     JOIN mo_classes c ON c.id = e.class_id
     WHERE e.user_id = ? AND e.year_id = ? AND e.term = ?
     ORDER BY c.grade_level, c.letter, e.subject_id', [$u['id'], $yid, $term]) : [];

$marks = [];
foreach ($existing as $ex) {
    foreach (all('SELECT competency_id, state FROM mo_entry_competencies WHERE entry_id = ?', [$ex['id']]) as $m) {
        $marks[(int)$ex['id']][(int)$m['competency_id']] = $m['state'];
    }
}
$methodist = methodist_of((int)$u['id'], $yid);
$mName = $methodist ? (one('SELECT ' . user_name_sql() . ' AS n FROM users u WHERE id = ?', [$methodist])['n'] ?? '') : '';

header_html('Въвеждане на анализ', 'entry');
section_title('Въвеждане на анализ по компетентности');
year_picker($term);
?>

<p class="muted small">
  Всеки ред е една паралелка и един предмет – както при лекторските часове.
  <?php if ($mName): ?>Анализите се изпращат до методист <strong><?= e($mName) ?></strong>.
  <?php else: ?><span class="danger">Все още нямате назначен методист – можете да пазите чернови.</span><?php endif; ?>
</p>

<div class="legend">
  <span><i class="n"></i> неотбелязана – <strong>прехвърля се за II срок</strong></span>
  <span><i class="g"></i> отметка – усвоена</span>
  <span><i class="r"></i> червена отметка – неусвоена</span>
</div>

<form method="post" id="entryForm">
  <?= csrf_field() ?>
  <input type="hidden" name="term" value="<?= e($term) ?>">

  <div id="rows">
    <?php
    $render = function (int $idx, array $data = [], array $marked = []) use ($classes, $subjects) { ?>
      <section class="rowcard" data-row="<?= $idx ?>">
        <div class="rowhead">
          <b>Ред <span class="rn"><?= $idx + 1 ?></span></b>
          <?php if (!empty($data['status']) && $data['status'] === 'sent'): ?>
            <span class="badge ok">изпратен</span>
          <?php elseif (!empty($data['id'])): ?>
            <span class="badge warn">чернова</span>
          <?php endif; ?>
          <button type="button" class="del">🗑 Премахни ред</button>
        </div>

        <div class="fields three">
          <label>Предмет
            <select name="row[<?= $idx ?>][subject_id]" class="f-subject" required>
              <option value="">-- Избери предмет --</option>
              <?php foreach ($subjects as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= (int)($data['subject_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                  <?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Паралелка
            <select name="row[<?= $idx ?>][class_id]" class="f-class" required>
              <option value="">-- Клас --</option>
              <?php foreach ($classes as $c): ?>
                <option value="<?= (int)$c['id'] ?>" data-grade="<?= (int)$c['grade_level'] ?>"
                  <?= (int)($data['class_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Група
            <select name="row[<?= $idx ?>][group_no]">
              <?php foreach (GROUPS as $g => $lab): ?>
                <option value="<?= e($g) ?>" <?= (string)($data['group_no'] ?? '0') === $g ? 'selected' : '' ?>><?= e($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="comps" data-comps>
          <div class="chead">
            <span>Компетентности</span>
            <button type="button" class="btn small" data-bulk="mastered">Всички усвоени</button>
            <button type="button" class="btn small" data-bulk="clear">Изчисти</button>
            <span class="cnt"></span>
          </div>
          <div class="clist"><p class="muted small" style="padding:.7rem">Изберете предмет и паралелка.</p></div>
        </div>

        <details class="quant" <?= !empty($data['total_grades']) ? 'open' : '' ?>>
          <summary class="muted small">Количествен анализ (по избор)</summary>
          <div class="grades" style="margin-top:.5rem">
            <label>Ученици<input type="number" min="0" name="row[<?= $idx ?>][students_count]" value="<?= (int)($data['students_count'] ?? 0) ?>"></label>
            <?php foreach ([['g2','Слаб 2'],['g3','Среден 3'],['g4','Добър 4'],['g5','Мн. добър 5'],['g6','Отличен 6']] as [$k,$lab]): ?>
              <label><?= $lab ?><input type="number" min="0" class="gr" name="row[<?= $idx ?>][<?= $k ?>]" value="<?= (int)($data[$k] ?? 0) ?>"></label>
            <?php endforeach; ?>
          </div>
          <p class="muted small calcline">Общо: <b class="c-tot">–</b> · Среден успех: <b class="c-avg">–</b></p>
        </details>

        <label class="inline-note">Бележки
          <textarea name="row[<?= $idx ?>][note]" rows="3"
            placeholder="Причини, предприети мерки, работа с изоставащи, друго"><?= e($data['note'] ?? '') ?></textarea>
        </label>
      </section>
    <?php };

    if ($existing) {
        foreach ($existing as $i => $ex) $render($i, $ex, $marks[(int)$ex['id']] ?? []);
    } else {
        $render(0);
    }
    ?>
  </div>

  <button type="button" class="addrow" id="addRow">➕ Добави ред</button>

  <div class="actions">
    <button class="btn" name="action" value="draft" type="submit">Запази чернова</button>
    <button class="btn primary" name="action" value="send" type="submit"
            onclick="return confirm('Изпращане на анализите към методиста?')">Изпрати към методиста</button>
  </div>
</form>

<!-- образец за нов ред -->
<template id="rowTpl">
  <?php $render(999); ?>
</template>

<script>
  window.MO = {
    apiComps: <?= json_encode(base_url('api/competencies.php'), JSON_UNESCAPED_SLASHES) ?>,
    term: <?= json_encode($term) ?>,
    marks: <?= json_encode($marks, JSON_UNESCAPED_UNICODE) ?>,
    entryByRow: <?= json_encode(array_map(static fn($x) => (int)$x['id'], $existing)) ?>
  };
</script>
<?php footer_html(); ?>
