<?php
/**
 * Връща компетентностите за предмет + паралелка.
 * Взимат се тези за специалността на паралелката плюс общите за всички
 * специалности. За II срок се отбелязват останалите неотбелязани от I срок.
 */
require_once __DIR__ . '/../inc/bootstrap.php';

$u = require_user();

$subjectId = (int)($_GET['subject'] ?? 0);
$classId   = (int)($_GET['class'] ?? 0);
$term      = term_code($_GET['term'] ?? 'I');
$group     = (string)($_GET['group'] ?? '0');
if (!isset(GROUPS[$group])) $group = '0';
$yid = current_year_id();

if (!$subjectId || !$classId) json_out(['ok' => false, 'error' => 'Липсва предмет или паралелка.'], 400);

$class = one('SELECT c.*, p.name AS program_name, p.kind AS program_kind
              FROM mo_classes c
              LEFT JOIN mo_programs p ON p.id = c.program_id
              WHERE c.id = ?', [$classId]);
if (!$class) json_out(['ok' => false, 'error' => 'Няма такава паралелка.'], 404);

$comps = competencies_for($subjectId, $classId);

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

/* прехвърлени от I срок: тези, които са останали неотбелязани */
$carried = [];
if ($term === 'II') {
    $first = one('SELECT id FROM mo_entries
                  WHERE user_id=? AND year_id=? AND term="I" AND class_id=? AND subject_id=? AND group_no=?',
                 [$u['id'], $yid, $classId, $subjectId, $group]);
    if ($first) {
        $marked = array_map('intval', array_column(
            all('SELECT competency_id FROM mo_entry_competencies WHERE entry_id = ?', [$first['id']]),
            'competency_id'));
        foreach ($comps as $c) {
            if (!in_array((int)$c['id'], $marked, true)) $carried[] = (int)$c['id'];
        }
    }
}

json_out([
    'ok'        => true,
    'class'     => $class['name'],
    'grade'     => (int)$class['grade_level'],
    'program' => $class['program_name'],
    'program_kind' => $class['program_kind'],
    'locked'    => $entry && $entry['status'] === 'sent',
    'saved'     => $saved,
    'carried'   => $carried,
    'items'     => $comps,
]);
