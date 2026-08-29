<?php
/**
 * Връща компетентностите за даден предмет и клас.
 * За II срок отбелязва кои са останали неотбелязани през I срок –
 * те се показват като „прехвърлена от I срок“.
 */
require_once __DIR__ . '/../inc/bootstrap.php';

$u = require_user();

$subjectId = (int)($_GET['subject'] ?? 0);
$classId   = (int)($_GET['class'] ?? 0);
$term      = term_code($_GET['term'] ?? 'I');
$group     = (string)($_GET['group'] ?? '0');
if (!isset(GROUPS[$group])) $group = '0';
$yid       = current_year_id();

if (!$subjectId || !$classId) json_out(['ok' => false, 'error' => 'Липсва предмет или паралелка.'], 400);

$class = one('SELECT * FROM mo_classes WHERE id = ?', [$classId]);
if (!$class) json_out(['ok' => false, 'error' => 'Няма такава паралелка.'], 404);

$comps = all(
    'SELECT id, code, title, source FROM mo_competencies
      WHERE subject_id = ? AND grade_level = ? AND is_active = 1
      ORDER BY sort_order, id',
    [$subjectId, $class['grade_level']]
);

/* вече запазени отметки за този ред */
$entry = one('SELECT id, status FROM mo_entries
              WHERE user_id=? AND year_id=? AND term=? AND class_id=? AND subject_id=? AND group_no=?',
             [$u['id'], $yid, $term, $classId, $subjectId, $group]);
$saved = [];
if ($entry) {
    foreach (all('SELECT competency_id, state FROM mo_entry_competencies WHERE entry_id = ?', [$entry['id']]) as $r) {
        $saved[(int)$r['competency_id']] = $r['state'];
    }
}

/* прехвърлени от I срок: маркирани няма, значи са останали за II */
$carried = [];
if ($term === 'II') {
    $first = one('SELECT id FROM mo_entries
                  WHERE user_id=? AND year_id=? AND term="I" AND class_id=? AND subject_id=? AND group_no=?',
                 [$u['id'], $yid, $classId, $subjectId, $group]);
    if ($first) {
        $markedInFirst = array_column(
            all('SELECT competency_id FROM mo_entry_competencies WHERE entry_id = ?', [$first['id']]),
            'competency_id'
        );
        $markedInFirst = array_map('intval', $markedInFirst);
        foreach ($comps as $c) {
            if (!in_array((int)$c['id'], $markedInFirst, true)) $carried[] = (int)$c['id'];
        }
    }
}

json_out([
    'ok'      => true,
    'class'   => $class['name'],
    'grade'   => (int)$class['grade_level'],
    'entry'   => $entry ? ['id' => (int)$entry['id'], 'status' => $entry['status']] : null,
    'saved'   => $saved,
    'carried' => $carried,
    'items'   => $comps,
]);
