<?php
/**
 * Връща компетентностите за предмет + паралелка.
 * Ако има компетентности за точната професия/специалност на паралелката,
 * се използват те; иначе се използват общите за всички програми.
 * За II срок се отбелязват останалите неотбелязани от I срок.
 */
require_once __DIR__ . '/../inc/bootstrap.php';

$u = require_user();

$subjectId = (int)($_GET['subject'] ?? 0);
$classId   = (int)($_GET['class'] ?? 0);
$term      = term_code($_GET['term'] ?? 'I');
$group     = (string)($_GET['group'] ?? '0');
if (!isset(GROUPS[$group])) $group = '0';
$yid = current_year_id();
$entryId = (int)($_GET['entry_id'] ?? 0);
$copyMode = (string)($_GET['copy_mode'] ?? '0') === '1';

if (!$subjectId || !$classId) json_out(['ok' => false, 'error' => 'Липсва предмет или паралелка.'], 400);

/* При Преглед/Редакция използваме точния entry_id, вместо да разчитаме
   само на комбинацията година/срок/клас/предмет/група. Това е важно за
   РПП компетентностите и за snapshot-а на МО/методиста. Ако потребителят
   промени клас/предмет/група във формата, exact entry вече не се прилага. */
$entry = null;
if ($entryId > 0) {
    $candidate = one('SELECT id,user_id,year_id,term,class_id,subject_id,group_no,status,department_id,methodist_id
                      FROM mo_entries WHERE id=? AND user_id=?', [$entryId, $u['id']]);
    if ($candidate
        && (int)$candidate['class_id'] === $classId
        && (int)$candidate['subject_id'] === $subjectId
        && (string)$candidate['group_no'] === $group
        && (string)$candidate['term'] === $term) {
        $entry = $candidate;
        $yid = (int)$candidate['year_id'];
    }
}

$class = one('SELECT c.*, p.name AS program_name, p.kind AS program_kind
              FROM mo_classes c
              LEFT JOIN mo_programs p ON p.id = c.program_id
              WHERE c.id = ?', [$classId]);
if (!$class) json_out(['ok' => false, 'error' => 'Няма такава паралелка.'], 404);
if (!(int)$class['is_active']) json_out(['ok' => false, 'error' => 'Паралелката не е активна.'], 422);
if (!$entry && (int)$class['year_id'] !== $yid) json_out(['ok' => false, 'error' => 'Паралелката не е активна за избраната учебна година.'], 422);

/* За нов/променен избор търсим съществуващия анализ по уникалната комбинация. */
if (!$entry && !$copyMode) {
    $entry = one('SELECT id,user_id,year_id,term,class_id,subject_id,group_no,status,department_id,methodist_id
                  FROM mo_entries
                  WHERE user_id=? AND year_id=? AND term=? AND class_id=? AND subject_id=? AND group_no=?',
                 [$u['id'], $yid, $term, $classId, $subjectId, $group]);
}

$route = analysis_route($subjectId, $classId, $yid);
if (($route['code'] ?? '') === 'subject_class' && !$entry) {
    json_out([
        'ok' => false,
        'error' => $route['error'] ?? 'Този предмет не е валиден за избраната паралелка.'
    ], 422);
}

$assignment = $route['assignment'] ?? null;
$snapshotDepartment = null;
$snapshotMethodist = null;
if ($entry && $entry['department_id'] !== null) {
    $snapshotDepartment = one('SELECT id,name,chair_id FROM mo_departments WHERE id=?', [(int)$entry['department_id']]);
    if ($entry['methodist_id'] !== null) {
        $snapshotMethodist = one('SELECT id,' . user_name_sql('u') . ' AS full_name,u.email FROM users u WHERE u.id=?', [(int)$entry['methodist_id']]);
    } elseif ($snapshotDepartment && $snapshotDepartment['chair_id'] !== null) {
        /* Съвместимост със стари върнати записи, при които methodist_id
           е бил занулен: използваме текущия председател на запазеното МО. */
        $snapshotMethodist = one('SELECT id,' . user_name_sql('u') . ' AS full_name,u.email FROM users u WHERE u.id=?', [(int)$snapshotDepartment['chair_id']]);
    }
}
/* Липсващ/нееднозначен маршрут не блокира работата по чернова.
   Компетентностите се зареждат, а can_send=false показва причината.
   Само невалидната комбинация клас + предмет се отхвърля по-горе. */
if (!$assignment && $entry && $entry['department_id'] !== null) {
    $oldDep = one('SELECT d.name, d.chair_id, ' . user_name_sql('u') . ' AS chair_name, u.email AS chair_email
                   FROM mo_departments d LEFT JOIN users u ON u.id=d.chair_id WHERE d.id=?', [(int)$entry['department_id']]);
    $assignment = [
        'department_id' => (int)$entry['department_id'],
        'department_name' => $oldDep['name'] ?? '',
        'chair_id' => $oldDep['chair_id'] ?? null,
        'chair_name' => $oldDep['chair_name'] ?? '',
        'chair_email' => $oldDep['chair_email'] ?? '',
    ];
}

$isRpp = subject_is_rpp($subjectId);
/* Snapshot compatibility: ако към конкретния анализ вече има ръчни
   компетентности, той остава РПП за Преглед/Редакция независимо от
   последващи промени в административната РПП конфигурация. */
if (!$isRpp && $entry) {
    $isRpp = (bool)one('SELECT id FROM mo_entry_manual_competencies WHERE entry_id=? LIMIT 1', [(int)$entry['id']]);
}
$comps = $isRpp ? [] : ($entry ? competencies_for_existing_entry((int)$entry['id'], $subjectId, $classId) : competencies_for($subjectId, $classId));
$saved = [];
$manualItems = [];
if ($entry) {
    if ($isRpp) {
        foreach (all('SELECT id,title,state,sort_order FROM mo_entry_manual_competencies WHERE entry_id=? ORDER BY sort_order,id', [$entry['id']]) as $r) {
            $manualItems[] = [
                'id'=>(int)$r['id'], 'title'=>(string)$r['title'],
                'state'=>$r['state'] !== null ? (string)$r['state'] : '', 'carried'=>false
            ];
        }
    } else {
        foreach (all('SELECT competency_id, state FROM mo_entry_competencies WHERE entry_id = ?', [$entry['id']]) as $r) {
            $saved[(int)$r['competency_id']] = $r['state'];
        }
    }
}

/* прехвърлени от I срок: тези, които са останали неотбелязани */
$carried = [];
if ($term === 'II') {
    $first = one('SELECT id FROM mo_entries
                  WHERE user_id=? AND year_id=? AND term="I" AND class_id=? AND subject_id=? AND group_no=?',
                 [$u['id'], $yid, $classId, $subjectId, $group]);
    if ($first) {
        if ($isRpp) {
            if (!$manualItems) {
                foreach (all('SELECT title FROM mo_entry_manual_competencies WHERE entry_id=? AND state IS NULL ORDER BY sort_order,id', [$first['id']]) as $r) {
                    $manualItems[] = ['id'=>null,'title'=>(string)$r['title'],'state'=>'','carried'=>true];
                }
            }
        } else {
            $marked = array_map('intval', array_column(
                all('SELECT competency_id FROM mo_entry_competencies WHERE entry_id = ?', [$first['id']]),
                'competency_id'));
            foreach ($comps as $c) {
                if (!in_array((int)$c['id'], $marked, true)) $carried[] = (int)$c['id'];
            }
        }
    }
}

json_out([
    'ok'        => true,
    'class'     => $class['name'],
    'grade'     => (int)$class['grade_level'],
    'program' => $class['program_name'],
    'program_kind' => $class['program_kind'],
    'department_id' => $snapshotDepartment ? (int)$snapshotDepartment['id'] : ($assignment ? (int)$assignment['department_id'] : null),
    'department' => $snapshotDepartment['name'] ?? ($assignment['department_name'] ?? null),
    'can_send' => $snapshotDepartment ? true : !empty($route['ok']),
    'route_error' => $snapshotDepartment ? null : (empty($route['ok']) ? ($route['error'] ?? null) : null),
    'methodist_id' => $snapshotMethodist ? (int)$snapshotMethodist['id'] : (!empty($route['ok']) ? (int)$route['methodist_id'] : ($entry['methodist_id'] ?? null)),
    'methodist' => $snapshotMethodist['full_name'] ?? (!empty($route['ok']) ? $route['methodist_name'] : ($assignment['chair_name'] ?? null)),
    'methodist_email' => $snapshotMethodist['email'] ?? (!empty($route['ok']) ? $route['methodist_email'] : ($assignment['chair_email'] ?? null)),
    'route_mode' => $route['route_mode'] ?? null,
    'is_rpp' => $isRpp,
    'manual_items' => $manualItems,
    'locked'    => $entry && $entry['status'] === 'sent',
    'saved'     => $saved,
    'carried'   => $carried,
    'items'     => $comps,
]);
