<?php
/**
 * Един анализ = един предмет за една паралелка (и група) за даден срок.
 * Отваря се от „Моите анализи“ – за нов или за редакция на съществуващ.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('teacher');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? $_POST['term'] ?? 'I');
$eid  = (int)($_GET['id'] ?? $_POST['entry_id'] ?? 0);

/* ------------------------------ запис ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $send    = ($_POST['action'] ?? '') === 'send';
    $classId = (int)($_POST['class_id'] ?? 0);
    $subjId  = (int)($_POST['subject_id'] ?? 0);
    $group   = (string)($_POST['group_no'] ?? '0');
    if (!isset(GROUPS[$group])) $group = '0';

    $errors = [];
    if (!$classId || !$subjId) $errors[] = 'Изберете предмет и паралелка.';

    /* същият анализ вече съществува (друг запис за същата комбинация) */
    $exist = $classId && $subjId
        ? one('SELECT id, status FROM mo_entries
               WHERE user_id=? AND year_id=? AND term=? AND class_id=? AND subject_id=? AND group_no=?',
              [$u['id'], $yid, $term, $classId, $subjId, $group])
        : null;
    if ($exist && (int)$exist['id'] !== $eid) {
        $errors[] = 'Вече имате анализ за този предмет, паралелка и група за този срок.';
    }
    if ($exist && (int)$exist['id'] === $eid && $exist['status'] === 'sent') {
        $errors[] = 'Анализът е изпратен и е заключен. Върнете го за редакция от „Моите анализи“.';
    }

    $num      = static fn($k) => max(0, (int)($_POST[$k] ?? 0));
    $students = $num('students_count');
    $grades   = $num('g2') + $num('g3') + $num('g4') + $num('g5') + $num('g6');
    $measures = trim((string)($_POST['measures'] ?? ''));

    $depId = $subjId ? department_of_subject($subjId) : null;
    if ($send && !$depId) {
        $errors[] = 'Предметът не е зачислен към методическо обединение. Обърнете се към администрацията.';
    }
    if ($send) {
        if ($students < 1) $errors[] = 'Въведете броя ученици.';
        elseif ($grades === 0) $errors[] = 'Количественият анализ е задължителен.';
        elseif ($grades !== $students) $errors[] = "Оценките са $grades, а учениците $students – броят трябва да съвпада.";
        if (mb_strlen($measures) < 10) $errors[] = 'Попълнете мерките за подобряване на качеството.';
    }

    if ($errors) {
        flash(($send ? 'Анализът не е изпратен. ' : 'Записът не е направен. ') . e(implode(' ', $errors)), 'err');
        redirect(base_url('pages/entry.php?term=' . $term . ($eid ? '&id=' . $eid : '')));
    }

    /* отметки само по компетентности за този предмет, клас и професия */
    $allowed = array_map('intval', array_column(competencies_for($subjId, $classId), 'id'));
    $marks = [];
    foreach (($_POST['comp'] ?? []) as $cid => $state) {
        $cid = (int)$cid;
        if (!isset(STATES[(string)$state]) || !in_array($cid, $allowed, true)) continue;
        $marks[$cid] = (string)$state;
    }

    db()->beginTransaction();
    try {
        $params = [':uid' => $u['id'], ':yid' => $yid, ':term' => $term,
                   ':cid' => $classId, ':sid' => $subjId, ':grp' => $group,
                   ':st' => $students, ':g2' => $num('g2'), ':g3' => $num('g3'),
                   ':g4' => $num('g4'), ':g5' => $num('g5'), ':g6' => $num('g6'),
                   ':m' => $measures, ':status' => $send ? 'sent' : 'draft',
                   ':dep' => $depId];
        q('INSERT INTO mo_entries
             (user_id, year_id, term, class_id, subject_id, group_no,
              students_count, g2,g3,g4,g5,g6, measures, status, department_id, sent_at)
           VALUES (:uid,:yid,:term,:cid,:sid,:grp,:st,:g2,:g3,:g4,:g5,:g6,:m,:status,:dep,
              CASE WHEN :status2 = "sent" THEN NOW() ELSE NULL END)
           ON DUPLICATE KEY UPDATE
              students_count=VALUES(students_count), g2=VALUES(g2), g3=VALUES(g3),
              g4=VALUES(g4), g5=VALUES(g5), g6=VALUES(g6), measures=VALUES(measures),
              status=VALUES(status), department_id=VALUES(department_id),
              sent_at = CASE WHEN VALUES(status)="sent" THEN NOW() ELSE sent_at END',
          $params + [':status2' => $send ? 'sent' : 'draft']);

        $saved = one('SELECT id FROM mo_entries WHERE user_id=? AND year_id=? AND term=?
                      AND class_id=? AND subject_id=? AND group_no=?',
                     [$u['id'], $yid, $term, $classId, $subjId, $group]);
        $newId = (int)($saved['id'] ?? 0);

        q('DELETE FROM mo_entry_competencies WHERE entry_id = ?', [$newId]);
        $ins = db()->prepare('INSERT INTO mo_entry_competencies (entry_id, competency_id, state) VALUES (?,?,?)');
        foreach ($marks as $cid => $state) $ins->execute([$newId, $cid, $state]);

        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        flash('Грешка при запис: ' . e($ex->getMessage()), 'err');
        redirect(base_url('pages/entry.php?term=' . $term . ($eid ? '&id=' . $eid : '')));
    }

    if ($send) {
        $dep = one('SELECT name FROM mo_departments WHERE id = ?', [$depId]);
        flash('Анализът е изпратен към ' . e($dep['name'] ?? 'методическото обединение')
            . ' – виждат го председателят и заместникът.');
        redirect(base_url('pages/my_entries.php?term=' . $term));
    }
    flash('Черновата е записана.');
    redirect(base_url('pages/entry.php?term=' . $term . '&id=' . $newId));
}

/* ---------------------------- зареждане ---------------------------- */
$entry = $eid ? one('SELECT * FROM mo_entries WHERE id = ? AND user_id = ?', [$eid, $u['id']]) : null;
if ($eid && !$entry) { http_response_code(404); die('Анализът не е намерен.'); }
if ($entry) $term = $entry['term'];

/* дублиране: отваряме нов анализ с отметките на друг */
$copyFrom = (int)($_GET['copy'] ?? 0);
$copyMarks = [];
$copySource = null;
if (!$entry && $copyFrom) {
    $copySource = one('SELECT e.*, s.name AS subject_name, c.name AS class_name
                       FROM mo_entries e JOIN mo_subjects s ON s.id = e.subject_id
                       JOIN mo_classes c ON c.id = e.class_id
                       WHERE e.id = ? AND e.user_id = ?', [$copyFrom, $u['id']]);
    if ($copySource) {
        foreach (all('SELECT competency_id, state FROM mo_entry_competencies WHERE entry_id = ?', [$copyFrom]) as $r) {
            $copyMarks[(int)$r['competency_id']] = $r['state'];
        }
    }
}

$classes = all('SELECT c.*, p.name AS program_name FROM mo_classes c
                LEFT JOIN mo_programs p ON p.id = c.program_id
                WHERE c.is_active = 1 AND c.year_id = ?
                ORDER BY c.grade_level, c.letter', [$yid]);
$subjects = all('SELECT s.*, d.name AS department_name FROM mo_subjects s
                 LEFT JOIN mo_departments d ON d.id = s.department_id
                 WHERE s.is_active = 1 ORDER BY s.name');

$curSubject = (int)($entry['subject_id'] ?? $copySource['subject_id'] ?? 0);
$curClass   = (int)($entry['class_id'] ?? 0);
$curGroup   = (string)($entry['group_no'] ?? $copySource['group_no'] ?? '0');
$locked     = $entry && $entry['status'] === 'sent';

header_html($entry ? 'Редакция на анализ' : 'Нов анализ', 'entry');
section_title($entry ? 'Редакция на анализ' : 'Нов анализ',
    '<a class="btn small ghost" href="' . base_url('pages/my_entries.php?term=' . $term) . '">Към моите анализи</a>');
?>

<?php if ($locked): ?>
  <div class="flash warn">Анализът е изпратен и е заключен. За промяна го върнете за редакция от „Моите анализи“.</div>
<?php endif; ?>
<?php if ($copySource): ?>
  <div class="flash ok">Дублиране на анализа по <strong><?= e($copySource['subject_name']) ?></strong>
     от <strong><?= e($copySource['class_name']) ?></strong>: отметките по компетентностите и мерките са
     пренесени. Изберете новата паралелка и попълнете броя оценки.</div>
<?php endif; ?>

<form method="post" id="entryForm">
  <?= csrf_field() ?>
  <input type="hidden" name="term" value="<?= e($term) ?>">
  <input type="hidden" name="entry_id" value="<?= (int)($entry['id'] ?? 0) ?>">

  <section class="rowcard" data-row="0">
    <div class="fields three">
      <label>Предмет
        <select name="subject_id" class="f-subject" required <?= $locked ? 'disabled' : '' ?>>
          <option value="">-- Избери предмет --</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $curSubject === (int)$s['id'] ? 'selected' : '' ?>>
              <?= e($s['name']) ?><?= $s['department_name'] ? ' · ' . e($s['department_name']) : ' · без МО' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Паралелка
        <select name="class_id" class="f-class" required <?= $locked ? 'disabled' : '' ?>>
          <option value="">-- Клас --</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $curClass === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?><?= $c['program_name'] ? ' · ' . e($c['program_name']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Група
        <select name="group_no" <?= $locked ? 'disabled' : '' ?>>
          <?php foreach (GROUPS as $g => $lab): ?>
            <option value="<?= e($g) ?>" <?= $curGroup === $g ? 'selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div class="legend">
      <span><i class="n"></i> неотбелязана – <strong>прехвърля се за II срок</strong></span>
      <span><i class="g"></i> усвоена</span>
      <span><i class="y"></i> частично усвоена</span>
      <span><i class="r"></i> неусвоена</span>
    </div>

    <div class="comps" data-comps>
      <div class="chead">
        <span>Компетентности <em class="spec-name muted"></em></span>
        <button type="button" class="btn small" data-bulk="mastered">Всички усвоени</button>
        <button type="button" class="btn small" data-bulk="clear">Изчисти</button>
        <span class="cnt"></span>
      </div>
      <div class="clist"><p class="muted small" style="padding:.7rem">Изберете предмет и паралелка.</p></div>
    </div>

    <fieldset class="quant">
      <legend>Количествен анализ <span class="req">задължително</span></legend>
      <div class="grades">
        <label title="Брой ученици в паралелката">
          <span class="g-num">&#8721;</span>Ученици
          <input type="number" min="0" class="students" name="students_count"
                 value="<?= (int)($entry['students_count'] ?? 0) ?>" <?= $locked ? 'disabled' : '' ?>>
        </label>
        <?php foreach ([['g2','2','Слаб'],['g3','3','Среден'],['g4','4','Добър'],
                        ['g5','5','Мн. добър'],['g6','6','Отличен']] as [$k,$n,$lab]): ?>
          <label title="<?= e($lab) ?> (<?= $n ?>)">
            <span class="g-num"><?= $n ?></span><?= e($lab) ?>
            <input type="number" min="0" class="gr" name="<?= $k ?>"
                   value="<?= (int)($entry[$k] ?? 0) ?>" <?= $locked ? 'disabled' : '' ?>>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="calcline small">Общо оценки: <b class="c-tot">–</b> · Среден успех: <b class="c-avg">–</b>
         <span class="c-warn danger"></span></p>
    </fieldset>

    <label class="inline-note">Предложете мерки за подобряване качеството на обучение
      <span class="req">задължително</span>
      <textarea name="measures" rows="3" <?= $locked ? 'disabled' : '' ?>
        placeholder="Например: допълнителни консултации по темите с най-слаби резултати; повече практически упражнения"><?= e($entry['measures'] ?? $copySource['measures'] ?? '') ?></textarea>
    </label>
  </section>

  <?php if (!$locked): ?>
  <div class="actions">
    <button class="btn" name="action" value="draft" type="submit">Запази чернова</button>
    <button class="btn primary" name="action" value="send" type="submit"
            data-confirm="Анализът ще бъде изпратен към методическото обединение на предмета и се заключва. За промяна ще трябва да го върнете от „Моите анализи“."
            data-confirm-title="Изпращане на анализа" data-confirm-ok="Изпрати">Изпрати към МО</button>
  </div>
  <?php endif; ?>
</form>

<script>
  window.MO = window.MO || {};
  window.MO.apiComps = <?= json_encode(base_url('api/competencies.php'), JSON_UNESCAPED_SLASHES) ?>;
  window.MO.term = <?= json_encode($term) ?>;
  window.MO.preset = <?= json_encode((object)$copyMarks, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php footer_html(); ?>
