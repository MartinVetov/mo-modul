<?php
/**
 * Въвеждане на анализ – по образец на „Добавяне на лекторски часове“ във ВИС.
 * Всеки ред = паралелка + предмет + група. Компетентностите се зареждат
 * според предмета, класа И специалността на паралелката.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('teacher');
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');

if (!$yid) flash('Администраторът още не е създал учебна година.', 'err');

/* ------------------------------ запис ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'draft';
    $rows   = $_POST['row'] ?? [];
    $send   = $action === 'send';
    $mid    = methodist_of((int)$u['id'], $yid);

    $notice = '';
    if ($send && !$mid) {
        // предупреждението се пази и се долепя към крайното съобщение,
        // за да не бъде презаписано от него
        $notice = 'Няма назначен методист за вас, затова записахме анализа като чернова. '
                . 'Обърнете се към администрацията.';
        $send = false;
    }

    $errors = [];
    $clean  = [];
    $seen   = [];

    foreach ($rows as $i => $r) {
        $no      = (int)$i + 1;
        $classId = (int)($r['class_id'] ?? 0);
        $subjId  = (int)($r['subject_id'] ?? 0);

        if (!$classId && !$subjId) continue;
        if (!$classId || !$subjId) { $errors[] = "Ред $no: изберете и паралелка, и предмет."; continue; }

        $group = (string)($r['group_no'] ?? '0');
        if (!isset(GROUPS[$group])) $group = '0';

        /* повторение на същия ред във формата */
        $key = $classId . '-' . $subjId . '-' . $group;
        if (isset($seen[$key])) {
            $errors[] = "Ред $no: същата паралелка, предмет и група вече са въведени на ред {$seen[$key]}.";
            continue;
        }

        /* цяла паралелка и отделна група едновременно */
        $conflict = false;
        foreach (($group === '0' ? ['1', '2'] : ['0']) as $g) {
            if (isset($seen[$classId . '-' . $subjId . '-' . $g])) $conflict = true;
        }
        if ($conflict) {
            $errors[] = "Ред $no: не смесвайте „цяла паралелка“ и отделна група за един и същ предмет.";
            continue;
        }
        $seen[$key] = $no;

        /* изпратеният анализ е заключен */
        $exist = one('SELECT id, status FROM mo_entries
                      WHERE user_id=? AND year_id=? AND term=? AND class_id=? AND subject_id=? AND group_no=?',
                     [$u['id'], $yid, $term, $classId, $subjId, $group]);
        if ($exist && $exist['status'] === 'sent') {
            $errors[] = "Ред $no: анализът вече е изпратен. За промяна го върнете от „Моите анализи“.";
            continue;
        }

        $num      = static fn($k) => max(0, (int)($r[$k] ?? 0));
        $students = $num('students_count');
        $grades   = $num('g2') + $num('g3') + $num('g4') + $num('g5') + $num('g6');
        $measures = trim((string)($r['measures'] ?? ''));

        if ($send) {
            if ($students < 1) { $errors[] = "Ред $no: въведете броя ученици."; continue; }
            if ($grades === 0) { $errors[] = "Ред $no: количественият анализ е задължителен."; continue; }
            if ($grades !== $students) {
                $errors[] = "Ред $no: оценките са $grades, а учениците $students – броят трябва да съвпада.";
                continue;
            }
            if (mb_strlen($measures) < 10) {
                $errors[] = "Ред $no: попълнете мерките за подобряване на качеството.";
                continue;
            }
        } elseif ($students > 0 && $grades > 0 && $grades !== $students) {
            $errors[] = "Ред $no (чернова): оценките ($grades) не съвпадат с учениците ($students).";
        }

        /* отметки само по компетентности за този предмет, клас и специалност */
        $allowed = array_map('intval', array_column(competencies_for($subjId, $classId), 'id'));
        $marks = [];
        foreach (($r['comp'] ?? []) as $cid => $state) {
            $cid = (int)$cid;
            if (!isset(STATES[(string)$state])) continue;
            if (!in_array($cid, $allowed, true)) continue;
            $marks[$cid] = (string)$state;
        }

        $clean[] = [
            'classId' => $classId, 'subjId' => $subjId, 'group' => $group,
            'students' => $students, 'marks' => $marks, 'measures' => $measures,
            'g2' => $num('g2'), 'g3' => $num('g3'), 'g4' => $num('g4'),
            'g5' => $num('g5'), 'g6' => $num('g6'),
        ];
    }

    if ($send && $errors) {
        flash('Анализът не е изпратен. ' . e(implode(' ', $errors)), 'err');
        redirect(base_url('pages/entry.php?term=' . $term));
    }

    $saved = 0;
    db()->beginTransaction();
    try {
        foreach ($clean as $c) {
            $st = $send ? 'sent' : 'draft';
            q('INSERT INTO mo_entries
                 (user_id, year_id, term, class_id, subject_id, group_no,
                  students_count, g2,g3,g4,g5,g6, measures, status, methodist_id, sent_at)
               VALUES (:uid,:yid,:term,:cid,:sid,:grp,:st,:g2,:g3,:g4,:g5,:g6,:m,:status,:mid,
                  CASE WHEN :status2 = "sent" THEN NOW() ELSE NULL END)
               ON DUPLICATE KEY UPDATE
                  students_count=VALUES(students_count), g2=VALUES(g2), g3=VALUES(g3),
                  g4=VALUES(g4), g5=VALUES(g5), g6=VALUES(g6), measures=VALUES(measures),
                  status=VALUES(status), methodist_id=VALUES(methodist_id),
                  sent_at = CASE WHEN VALUES(status)="sent" THEN NOW() ELSE sent_at END',
              [':uid' => $u['id'], ':yid' => $yid, ':term' => $term,
               ':cid' => $c['classId'], ':sid' => $c['subjId'], ':grp' => $c['group'],
               ':st' => $c['students'], ':g2' => $c['g2'], ':g3' => $c['g3'], ':g4' => $c['g4'],
               ':g5' => $c['g5'], ':g6' => $c['g6'], ':m' => $c['measures'],
               ':status' => $st, ':mid' => $send ? $mid : null, ':status2' => $st]);

            $eid = (int)(one('SELECT id FROM mo_entries WHERE user_id=? AND year_id=? AND term=?
                              AND class_id=? AND subject_id=? AND group_no=?',
                             [$u['id'], $yid, $term, $c['classId'], $c['subjId'], $c['group']])['id'] ?? 0);
            if (!$eid) continue;

            q('DELETE FROM mo_entry_competencies WHERE entry_id = ?', [$eid]);
            $ins = db()->prepare('INSERT INTO mo_entry_competencies (entry_id, competency_id, state) VALUES (?,?,?)');
            foreach ($c['marks'] as $cid => $state) $ins->execute([$eid, $cid, $state]);
            $saved++;
        }
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        flash('Грешка при запис: ' . e($ex->getMessage()), 'err');
        redirect(base_url('pages/entry.php?term=' . $term));
    }

    $msg = $send ? "Изпратени са $saved анализа към методиста." : "Запазени са $saved реда като чернова.";
    if ($notice) $msg .= ' ' . e($notice);
    if ($errors) $msg .= ' Внимание: ' . e(implode(' ', $errors));
    flash($msg, ($errors || $notice) ? 'warn' : 'ok');
    redirect(base_url('pages/' . ($send ? 'my_entries.php' : 'entry.php') . '?term=' . $term));
}

/* ---------------------------- зареждане ---------------------------- */
$classes = all('SELECT c.*, p.name AS program_name, p.kind AS program_kind
                FROM mo_classes c
                LEFT JOIN mo_programs p ON p.id = c.program_id
                WHERE c.is_active = 1 AND c.year_id = ?
                ORDER BY c.grade_level, c.letter', [$yid]);
$subjects = all('SELECT * FROM mo_subjects WHERE is_active = 1 ORDER BY name');

$existing = $yid ? all(
    'SELECT e.*, c.grade_level FROM mo_entries e
     JOIN mo_classes c ON c.id = e.class_id
     WHERE e.user_id = ? AND e.year_id = ? AND e.term = ? AND e.status = "draft"
     ORDER BY c.grade_level, c.letter, e.subject_id', [$u['id'], $yid, $term]) : [];

$sentCount = $yid ? (int)(one('SELECT COUNT(*) n FROM mo_entries
                               WHERE user_id=? AND year_id=? AND term=? AND status="sent"',
                              [$u['id'], $yid, $term])['n'] ?? 0) : 0;

$methodist = methodist_of((int)$u['id'], $yid);
$mName = $methodist ? (one('SELECT ' . user_name_sql() . ' AS n FROM users u WHERE id = ?', [$methodist])['n'] ?? '') : '';

header_html('Въвеждане на анализ', 'entry');
section_title('Въвеждане на анализ по компетентности');
year_picker($term);
?>

<p class="muted small">
  Всеки ред е една паралелка и един предмет – както при лекторските часове.
  <?php if ($mName): ?>Анализите се изпращат до методист <strong><?= e($mName) ?></strong>.
  <?php else: ?><span class="danger">Все още нямате назначен методист – можете да пазите само чернови.</span><?php endif; ?>
  <?php if ($sentCount): ?><br>Имате <strong><?= $sentCount ?></strong> вече изпратени анализа – заключени са и се
    редактират само след „Върни за редакция“ в
    <a href="<?= base_url('pages/my_entries.php?term=' . $term) ?>">Моите анализи</a>.
  <?php endif; ?>
</p>

<div class="legend">
  <span><i class="n"></i> неотбелязана – <strong>прехвърля се за II срок</strong></span>
  <span><i class="g"></i> усвоена</span>
  <span><i class="y"></i> частично усвоена</span>
  <span><i class="r"></i> неусвоена</span>
</div>

<form method="post" id="entryForm">
  <?= csrf_field() ?>
  <input type="hidden" name="term" value="<?= e($term) ?>">

  <div id="rows">
    <?php
    $render = function (int $idx, array $data = []) use ($classes, $subjects) { ?>
      <section class="rowcard" data-row="<?= $idx ?>">
        <div class="rowhead">
          <b>Ред <span class="rn"><?= $idx + 1 ?></span></b>
          <?php if (!empty($data['id'])): ?><span class="badge warn">чернова</span><?php endif; ?>
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
                <option value="<?= (int)$c['id'] ?>" <?= (int)($data['class_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= e($c['name']) ?><?= $c['program_name'] ? ' · ' . e($c['program_name']) : '' ?></option>
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
              <input type="number" min="0" class="students" name="row[<?= $idx ?>][students_count]"
                     value="<?= (int)($data['students_count'] ?? 0) ?>">
            </label>
            <?php foreach ([['g2','2','Слаб'],['g3','3','Среден'],['g4','4','Добър'],
                            ['g5','5','Мн. добър'],['g6','6','Отличен']] as [$k,$num,$lab]): ?>
              <label title="<?= e($lab) ?> (<?= $num ?>)">
                <span class="g-num"><?= $num ?></span><?= e($lab) ?>
                <input type="number" min="0" class="gr" name="row[<?= $idx ?>][<?= $k ?>]"
                       value="<?= (int)($data[$k] ?? 0) ?>">
              </label>
            <?php endforeach; ?>
          </div>
          <p class="calcline small">Общо оценки: <b class="c-tot">–</b> · Среден успех: <b class="c-avg">–</b>
             <span class="c-warn danger"></span></p>
        </fieldset>

        <label class="inline-note">Предложете мерки за подобряване качеството на обучение
          <span class="req">задължително</span>
          <textarea name="row[<?= $idx ?>][measures]" rows="3"
            placeholder="Например: допълнителни консултации по темите с най-слаби резултати; повече практически упражнения; работа в малки групи с изоставащите"><?= e($data['measures'] ?? '') ?></textarea>
        </label>
      </section>
    <?php };

    if ($existing) { foreach ($existing as $i => $ex) $render($i, $ex); }
    else { $render(0); }
    ?>
  </div>

  <button type="button" class="addrow" id="addRow">➕ Добави ред</button>

  <div class="actions">
    <button class="btn" name="action" value="draft" type="submit">Запази чернова</button>
    <button class="btn primary" name="action" value="send" type="submit"
            data-confirm="Анализите ще бъдат изпратени към методиста и се заключват. За промяна ще трябва да ги върнете от „Моите анализи“."
            data-confirm-title="Изпращане на анализите" data-confirm-ok="Изпрати">
      Изпрати към методиста</button>
  </div>
</form>

<template id="rowTpl"><?php $render(999); ?></template>

<script>
  window.MO = {
    apiComps: <?= json_encode(base_url('api/competencies.php'), JSON_UNESCAPED_SLASHES) ?>,
    term: <?= json_encode($term) ?>
  };
</script>
<?php footer_html(); ?>
