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
$isEntryAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/* ------------------------------ запис ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $send    = ($_POST['action'] ?? '') === 'send';
    $classId = (int)($_POST['class_id'] ?? 0);
    $subjId  = (int)($_POST['subject_id'] ?? 0);
    $group   = (string)($_POST['group_no'] ?? '0');
    if (!isset(GROUPS[$group])) $group = '0';

    /* При редакция на върнат анализ пазим workflow получателя.
       МО и методистът са snapshot на вече започналия процес и не трябва
       да се губят само защото статусът временно е върнат в draft. */
    $currentEntry = $eid > 0
        ? one('SELECT id,user_id,class_id,subject_id,group_no,status,department_id,methodist_id
               FROM mo_entries WHERE id=? AND user_id=?', [$eid, $u['id']])
        : null;

    $errors = [];
    if (!$classId || !$subjId) $errors[] = 'Изберете предмет и паралелка.';

    $classOk = $classId ? one('SELECT id FROM mo_classes WHERE id=? AND year_id=? AND is_active=1', [$classId, $yid]) : null;
    $subjectOk = $subjId ? one('SELECT id FROM mo_subjects WHERE id=? AND is_active=1', [$subjId]) : null;
    if ($classId && !$classOk) $errors[] = 'Избраната паралелка не е активна за тази учебна година.';
    if ($subjId && !$subjectOk) $errors[] = 'Избраният предмет не е активен.';
    $isRpp = $subjId && $subjectOk ? subject_is_rpp($subjId) : false;
    if ($classId && $subjId && $classOk && $subjectOk && !subject_available_for_class($subjId, $classId)) {
        $errors[] = 'Избраният предмет не е валиден за тази паралелка.';
    }

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

    $manualRows = [];
    if ($isRpp) {
        foreach ((array)($_POST['manual_comp'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') continue;
            if (mb_strlen($title) > 600) $title = mb_substr($title, 0, 600);
            $state = (string)($row['state'] ?? '');
            if ($state !== '' && !isset(STATES[$state])) continue;
            $manualRows[] = ['title'=>$title, 'state'=>$state !== '' ? $state : null];
            if (count($manualRows) >= 150) break;
        }
    }

    $route = null;
    if ($subjId && $classId && $classOk && $subjectOk) {
        try {
            $route = analysis_route($subjId, $classId, $yid);
        } catch (Throwable $routeEx) {
            $route = ['ok'=>false, 'code'=>'route_exception', 'error'=>'Грешка при маршрутизиране: ' . $routeEx->getMessage()];
        }
    }
    $sameWorkflowEntry = $currentEntry
        && (int)$currentEntry['class_id'] === $classId
        && (int)$currentEntry['subject_id'] === $subjId
        && (string)$currentEntry['group_no'] === $group;

    /* Съвместимост със записи, върнати от по-стара версия, която е
       занулявала methodist_id. Ако МО е запазено, използваме текущия му
       председател като възстановен workflow получател. */
    if ($sameWorkflowEntry
        && $currentEntry['department_id'] !== null
        && $currentEntry['methodist_id'] === null) {
        $legacyChair = one('SELECT chair_id FROM mo_departments WHERE id=?', [(int)$currentEntry['department_id']]);
        if ($legacyChair && $legacyChair['chair_id'] !== null) {
            $currentEntry['methodist_id'] = (int)$legacyChair['chair_id'];
        }
    }

    $hasWorkflowSnapshot = $sameWorkflowEntry
        && $currentEntry['department_id'] !== null
        && $currentEntry['methodist_id'] !== null;

    $depId = $route && !empty($route['department_id']) ? (int)$route['department_id'] : null;
    $methodistId = $send && $route && !empty($route['ok']) ? (int)$route['methodist_id'] : null;

    /* Върнатият за редакция анализ трябва да се върне при същия методист.
       Затова при непроменени клас/предмет/група пазим записаните
       department_id/methodist_id и при draft, и при повторно изпращане. */
    if ($hasWorkflowSnapshot) {
        $depId = (int)$currentEntry['department_id'];
        $methodistId = (int)$currentEntry['methodist_id'];

        if ($send) {
            $snapDep = one('SELECT id,name FROM mo_departments WHERE id=?', [$depId]);
            $snapMeth = one('SELECT id,' . user_name_sql('u') . ' AS full_name,u.email FROM users u WHERE u.id=?', [$methodistId]);
            if ($snapDep && $snapMeth) {
                $route = [
                    'ok' => true,
                    'department_id' => $depId,
                    'department_name' => (string)$snapDep['name'],
                    'methodist_id' => $methodistId,
                    'methodist_name' => (string)$snapMeth['full_name'],
                    'methodist_email' => (string)($snapMeth['email'] ?? ''),
                    'route_mode' => 'workflow_snapshot',
                ];
            }
        }
    }

    // Чернова може да се запази и при недовършено маршрутизиране. Изпращането – не.
    if ($send && (!$route || empty($route['ok']))) {
        $errors[] = $route['error'] ?? 'Не може да се определи получателят на анализа.';
    }
    if ($send) {
        if ($students < 1) $errors[] = 'Въведете броя ученици.';
        elseif ($grades === 0) $errors[] = 'Количественият анализ е задължителен.';
        elseif ($grades !== $students) $errors[] = "Оценките са $grades, а учениците $students – броят трябва да съвпада.";
        if (mb_strlen($measures) < 10) $errors[] = 'Попълнете мерките за подобряване на качеството.';
        if ($isRpp && !$manualRows) $errors[] = 'Добавете поне една компетентност за РПП предмета.';
    }

    if ($errors) {
        $errorMessage = ($send ? 'Анализът не е изпратен. ' : 'Записът не е направен. ') . implode(' ', $errors);
        if ($isEntryAjax) {
            json_out(['ok'=>false, 'action'=>$send ? 'send' : 'draft', 'message'=>$errorMessage], 422);
        }
        flash(e($errorMessage), 'err');
        redirect(base_url('pages/entry.php?term=' . $term . ($eid ? '&id=' . $eid : '')));
    }

    /* Отметки по официални компетентности се използват само за стандартните предмети. */
    $allowedSource = $isRpp ? [] : ($currentEntry && $sameWorkflowEntry
        ? competencies_for_existing_entry((int)$currentEntry['id'], $subjId, $classId)
        : competencies_for($subjId, $classId));
    $allowed = $isRpp ? [] : array_map('intval', array_column($allowedSource, 'id'));
    $compPayloadPresent = array_key_exists('comp', $_POST);
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
                   ':dep' => $depId, ':meth' => $methodistId];
        q('INSERT INTO mo_entries
             (user_id, year_id, term, class_id, subject_id, group_no,
              students_count, g2,g3,g4,g5,g6, measures, status, methodist_id, department_id, sent_at)
           VALUES (:uid,:yid,:term,:cid,:sid,:grp,:st,:g2,:g3,:g4,:g5,:g6,:m,:status,:meth,:dep,
              CASE WHEN :status2 = "sent" THEN NOW() ELSE NULL END)
           ON DUPLICATE KEY UPDATE
              students_count=VALUES(students_count), g2=VALUES(g2), g3=VALUES(g3),
              g4=VALUES(g4), g5=VALUES(g5), g6=VALUES(g6), measures=VALUES(measures),
              status=VALUES(status), methodist_id=VALUES(methodist_id), department_id=VALUES(department_id),
              sent_at = CASE WHEN VALUES(status)="sent" THEN NOW() ELSE NULL END',
          $params + [':status2' => $send ? 'sent' : 'draft']);

        $saved = one('SELECT id FROM mo_entries WHERE user_id=? AND year_id=? AND term=?
                      AND class_id=? AND subject_id=? AND group_no=?',
                     [$u['id'], $yid, $term, $classId, $subjId, $group]);
        $newId = (int)($saved['id'] ?? 0);

        if ($isRpp) {
            q('DELETE FROM mo_entry_competencies WHERE entry_id = ?', [$newId]);
            q('DELETE FROM mo_entry_manual_competencies WHERE entry_id = ?', [$newId]);
            $mins = db()->prepare('INSERT INTO mo_entry_manual_competencies (entry_id,title,state,sort_order) VALUES (?,?,?,?)');
            foreach ($manualRows as $i => $mr) $mins->execute([$newId, $mr['title'], $mr['state'], ($i + 1) * 10]);
        } else {
            q('DELETE FROM mo_entry_manual_competencies WHERE entry_id = ?', [$newId]);
            /* Ако браузърът изобщо не е изпратил comp[] (напр. API/JS
               проблем при редакция), не унищожаваме snapshot отметките
               на съществуващия анализ. Изрично „Изчисти“ все пак изпраща
               comp[] с празни стойности и тогава записът се нулира нормално. */
            if ($compPayloadPresent || !$currentEntry || !$sameWorkflowEntry) {
                q('DELETE FROM mo_entry_competencies WHERE entry_id = ?', [$newId]);
                $ins = db()->prepare('INSERT INTO mo_entry_competencies (entry_id, competency_id, state) VALUES (?,?,?)');
                foreach ($marks as $cid => $state) $ins->execute([$newId, $cid, $state]);
            }
        }

        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        $errorMessage = 'Грешка при запис: ' . $ex->getMessage();
        if ($isEntryAjax) {
            json_out(['ok'=>false, 'action'=>$send ? 'send' : 'draft', 'message'=>$errorMessage], 500);
        }
        flash('Грешка при запис: ' . e($ex->getMessage()), 'err');
        redirect(base_url('pages/entry.php?term=' . $term . ($eid ? '&id=' . $eid : '')));
    }

    if ($send) {
        $message = 'Анализът е изпратен към ' . (string)$route['department_name']
            . ' · получател: ' . (string)$route['methodist_name'] . '.';
        $redirectUrl = base_url('pages/my_entries.php?term=' . $term);
        if ($isEntryAjax) {
            json_out(['ok'=>true, 'action'=>'send', 'message'=>$message, 'redirect'=>$redirectUrl, 'entry_id'=>$newId]);
        }
        flash(e($message));
        redirect($redirectUrl);
    }
    $redirectUrl = base_url('pages/entry.php?term=' . $term . '&id=' . $newId);
    if ($isEntryAjax) {
        json_out(['ok'=>true, 'action'=>'draft', 'message'=>'Черновата е записана.', 'redirect'=>$redirectUrl, 'entry_id'=>$newId]);
    }
    flash('Черновата е записана.');
    redirect($redirectUrl);
}

/* ---------------------------- зареждане ---------------------------- */
$entry = $eid ? one('SELECT * FROM mo_entries WHERE id = ? AND user_id = ?', [$eid, $u['id']]) : null;
if ($eid && !$entry) { http_response_code(404); die('Анализът не е намерен.'); }
if ($entry) {
    $term = $entry['term'];
    $yid = (int)$entry['year_id'];
}

/* Запазените състояния се подават и като server-side fallback към JS.
   Така Преглед/Редакция не зависят изцяло от повторното откриване на
   анализа през API, особено при РПП ръчните компетентности. */
$entryMarks = [];
$entryManual = [];
if ($entry) {
    foreach (all('SELECT competency_id,state FROM mo_entry_competencies WHERE entry_id=?', [(int)$entry['id']]) as $r) {
        $entryMarks[(int)$r['competency_id']] = (string)$r['state'];
    }
    foreach (all('SELECT title,state FROM mo_entry_manual_competencies WHERE entry_id=? ORDER BY sort_order,id', [(int)$entry['id']]) as $r) {
        $entryManual[] = ['title'=>(string)$r['title'], 'state'=>$r['state'] !== null ? (string)$r['state'] : ''];
    }
}

/* дублиране: отваряме нов анализ с отметките на друг */
$copyFrom = (int)($_GET['copy'] ?? 0);
$copyMarks = [];
$copyManual = [];
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
        foreach (all('SELECT title,state FROM mo_entry_manual_competencies WHERE entry_id=? ORDER BY sort_order,id', [$copyFrom]) as $r) {
            $copyManual[] = ['title'=>(string)$r['title'], 'state'=>$r['state'] !== null ? (string)$r['state'] : ''];
        }
    }
}
$copyIsRpp = $copySource && (
    subject_is_rpp((int)$copySource['subject_id']) || !empty($copyManual)
);

/* При дублиране на стандартен предмет пазим snapshot на списъка с
   компетентности от оригиналния анализ. Паралелката нарочно остава
   празна за нов избор, затова без този snapshot JavaScript няма откъде
   да възстанови редовете и отметките преди избора на нов клас. */
$copyStandardComps = [];
if ($copySource && !$copyIsRpp) {
    $copyStandardComps = competencies_for_existing_entry(
        $copyFrom,
        (int)$copySource['subject_id'],
        (int)$copySource['class_id']
    );
}

$classes = all('SELECT c.*, p.name AS program_name FROM mo_classes c
                LEFT JOIN mo_programs p ON p.id = c.program_id
                WHERE c.is_active = 1 AND c.year_id = ?
                ORDER BY c.grade_level, c.letter', [$yid]);

/* При дублиране на РПП анализ показваме само паралелки, за които този
   РПП предмет е административно разрешен едновременно по клас и по
   професия/специалност. Така учителят не може да избере несъвместима паралелка. */
if ($copyIsRpp && $copySource) {
    $copyRppSubjectId = (int)$copySource['subject_id'];
    $classes = array_values(array_filter($classes, static function (array $c) use ($copyRppSubjectId): bool {
        return rpp_subject_available_for_class($copyRppSubjectId, (int)$c['id']);
    }));
}

$subjects = all('SELECT s.id, s.name
                 FROM mo_subjects s
                 WHERE s.is_active = 1 ORDER BY s.name');

/* Карта паралелка -> валидни предмети.
   Валидността идва от реално въведените компетентности за съответния клас
   и неговата професия/специалност, а НЕ от маршрута към МО. */
$availabilityRows = all(
    "SELECT DISTINCT c.id AS class_id, k.subject_id
     FROM mo_classes c
     JOIN mo_competencies k
       ON k.grade_level = c.grade_level
      AND k.is_active = 1
     JOIN mo_subjects s ON s.id = k.subject_id AND s.is_active = 1
     WHERE c.year_id = ? AND c.is_active = 1
       AND (
         -- Изключение: ЧЕП е общообразователен като маршрут, но може да има
         -- компетентности за конкретната програма на паралелката.
         (
           s.name='Чужд език по професията'
           AND (k.program_id IS NULL OR k.program_id <=> c.program_id)
         )
         OR
         (
           s.name<>'Чужд език по професията'
           AND (
             (
               EXISTS (
                 SELECT 1 FROM mo_competencies sp
                 WHERE sp.subject_id=s.id AND sp.is_active=1 AND sp.program_id IS NOT NULL
               )
               AND k.program_id IS NOT NULL
               AND k.program_id <=> c.program_id
             )
             OR
             (
               NOT EXISTS (
                 SELECT 1 FROM mo_competencies sp
                 WHERE sp.subject_id=s.id AND sp.is_active=1 AND sp.program_id IS NOT NULL
               )
               AND k.program_id IS NULL
             )
           )
         )
       )
     ORDER BY c.id, k.subject_id",
    [$yid]
);
/* Добавяме РПП предметите, валидни по клас + професия/специалност. */
$rppAvailabilityRows = all(
    'SELECT DISTINCT c.id AS class_id, rs.subject_id
     FROM mo_classes c
     JOIN mo_rpp_subject_grades rg ON rg.grade_level=c.grade_level
     JOIN mo_rpp_subjects rs ON rs.subject_id=rg.subject_id AND rs.is_active=1
     JOIN mo_rpp_subject_programs rp ON rp.subject_id=rs.subject_id AND rp.program_id=c.program_id
     JOIN mo_subjects s ON s.id=rs.subject_id AND s.is_active=1
     WHERE c.year_id=? AND c.is_active=1 AND c.program_id IS NOT NULL
     ORDER BY c.id,rs.subject_id',
    [$yid]
);
$availabilityRows = array_merge($availabilityRows, $rppAvailabilityRows);
$subjectAvailability = [];
foreach ($availabilityRows as $a) {
    $cid = (string)(int)$a['class_id'];
    if (!isset($subjectAvailability[$cid])) $subjectAvailability[$cid] = [];
    if (!in_array((int)$a['subject_id'], $subjectAvailability[$cid], true)) $subjectAvailability[$cid][] = (int)$a['subject_id'];
}

/* Маршрутът към МО се изчислява от backend чрез analysis_route().
   В браузъра филтрираме само валидните предмети по компетентности. */

/* Паралелките се водят по учебна година. Ако за избраната година няма
   нито една, менюто щеше да е празно без обяснение – затова казваме къде има. */
$yearLabel  = one('SELECT label FROM mo_years WHERE id = ?', [$yid])['label'] ?? '';
$otherYears = !$classes ? all('SELECT y.label, y.id, COUNT(c.id) n
                               FROM mo_years y JOIN mo_classes c ON c.year_id = y.id AND c.is_active = 1
                               GROUP BY y.id, y.label HAVING n > 0 ORDER BY y.label DESC') : [];

$curSubject = (int)($entry['subject_id'] ?? $copySource['subject_id'] ?? 0);
$curClass   = (int)($entry['class_id'] ?? 0);
/* При дублиране групата винаги се занулява. Копираме предмета и компетентностите,
   но новият анализ започва без избрана група. */
$curGroup   = $entry ? (string)($entry['group_no'] ?? '0') : '0';
$locked     = $entry && $entry['status'] === 'sent';
/* За съществуващ анализ наличието на ръчни компетентности е достатъчно
   доказателство, че записът е РПП snapshot, дори ако по-късно РПП
   конфигурацията на предмета е била скрита/променена. */
$currentManualRows = $entry ? $entryManual : $copyManual;
$currentIsRpp = $curSubject > 0 && (
    subject_is_rpp($curSubject)
    || ($entry && !empty($entryManual))
    || (!$entry && $copySource && !empty($copyManual))
);

/* За стандартен съществуващ анализ държим server-side snapshot на
   компетентностите. Така Преглед/Редакция не започват с празен блок и
   не зависят изцяло от текущата административна класификация. */
$entryStandardComps = [];
if ($entry && !$currentIsRpp && $curSubject && $curClass) {
    $entryStandardComps = competencies_for_existing_entry((int)$entry['id'], $curSubject, $curClass);
}

/* МО е филтър, но се определя от предмет + професия/специалност на класа.
   При редакция пазим МО-то, записано в анализа. */
$curDepartment = '';
if ($entry && $entry['department_id'] !== null) {
    $curDepartment = (string)(int)$entry['department_id'];
} elseif ($curSubject && $curClass) {
    $resolved = department_of_subject_for_class($curSubject, $curClass);
    if ($resolved) $curDepartment = (string)$resolved;
}

/* Видими snapshot данни за Преглед/Редакция. Показваме точно МО и
   методиста, записани при първоначалното изпращане, включително когато
   анализът е върнат временно в draft за корекция. */
$entryDepartmentName = '';
$entryMethodistName = '';
$entryMethodistEmail = '';
$entryRouteReady = false;
if ($entry && $entry['department_id'] !== null) {
    $drow = one('SELECT name FROM mo_departments WHERE id=?', [(int)$entry['department_id']]);
    $entryDepartmentName = (string)($drow['name'] ?? '');
}
if ($entry && $entry['methodist_id'] !== null) {
    $mrow = one('SELECT ' . user_name_sql('u') . ' AS full_name,u.email FROM users u WHERE u.id=?', [(int)$entry['methodist_id']]);
    $entryMethodistName = (string)($mrow['full_name'] ?? '');
    $entryMethodistEmail = (string)($mrow['email'] ?? '');
} elseif ($entry && $entry['department_id'] !== null) {
    /* Старите върнати записи може вече да са с methodist_id=NULL.
       Показваме текущия председател на запазеното МО като fallback. */
    $mrow = one('SELECT ' . user_name_sql('u') . ' AS full_name,u.email
                 FROM mo_departments d JOIN users u ON u.id=d.chair_id
                 WHERE d.id=?', [(int)$entry['department_id']]);
    $entryMethodistName = (string)($mrow['full_name'] ?? '');
    $entryMethodistEmail = (string)($mrow['email'] ?? '');
}
$entryRouteReady = (bool)($entry && $entryDepartmentName !== '' && $entryMethodistName !== '');

header_html($entry ? 'Редакция на анализ' : 'Нов анализ', 'entry');
section_title($entry ? 'Редакция на анализ' : 'Нов анализ',
    '<a class="btn small ghost" href="' . base_url('pages/my_entries.php?term=' . $term) . '">Към моите анализи</a>');
if (!$entry) year_picker($term);
?>

<?php if ($locked): ?>
  <div class="flash warn">Анализът е изпратен и е заключен. За промяна го върнете за редакция от „Моите анализи“.</div>
<?php endif; ?>
<?php if ($copySource): ?>
  <div class="flash ok">„Дублирай“ отвори нов анализ със същия предмет и същите отметки по компетентностите.
     Изберете паралелка и въведете количествения анализ.<?php if ($copyIsRpp): ?> За РПП са показани само паралелките, за които предметът е разрешен.<?php endif; ?></div>
<?php endif; ?>

<?php if (!$classes): ?>
  <div class="flash err">
    За учебна година <strong><?= e($yearLabel) ?></strong> няма въведени паралелки, затова
    менюто е празно.
    <?php if ($otherYears): ?>
      Паралелки има за:
      <?php foreach ($otherYears as $oy): ?>
        <a href="<?= base_url('pages/entry.php?term=' . $term . '&year=' . (int)$oy['id']) ?>">
          <?= e($oy['label']) ?></a> (<?= (int)$oy['n'] ?>)<?= $oy === end($otherYears) ? '' : ',' ?>
      <?php endforeach; ?>.
      Изберете годината горе или помолете администрацията да прехвърли паралелките.
    <?php else: ?>
      Администрацията ги добавя от „Години и паралелки“.
    <?php endif; ?>
  </div>
<?php endif; ?>

<div id="entryAjaxStatus" class="flash" style="display:none" aria-live="polite"></div>
<form method="post" id="entryForm" data-ajax-entry="1">
  <?= csrf_field() ?>
  <input type="hidden" name="term" value="<?= e($term) ?>">
  <input type="hidden" name="entry_id" value="<?= (int)($entry['id'] ?? 0) ?>">

  <section class="rowcard analysis-card classic-analysis" data-row="0"
           data-entry-rpp="<?= $currentIsRpp ? '1' : '0' ?>"
           data-entry-standard="<?= ($entry && !$currentIsRpp) ? '1' : '0' ?>"
           data-entry-class="<?= (int)($entry['class_id'] ?? 0) ?>"
           data-entry-subject="<?= (int)($entry['subject_id'] ?? 0) ?>"
           data-entry-group="<?= e((string)($entry['group_no'] ?? '')) ?>"
           data-has-route-snapshot="<?= $entryRouteReady ? '1' : '0' ?>"
           <?= $currentIsRpp && $curSubject && $curClass ? ' data-loaded-subject="' . (int)$curSubject . '" data-loaded-class="' . (int)$curClass . '"' : '' ?>>
    <div class="fields analysis-top-fields">
      <label>Паралелка
        <select name="class_id" class="f-class" required <?= $locked ? 'disabled' : '' ?>>
          <option value="">-- Клас --</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
                    data-program="<?= $c['program_id'] !== null ? (int)$c['program_id'] : '' ?>"
                    <?= $curClass === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?><?= $c['program_name'] ? ' · ' . e($c['program_name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Предмет
        <select name="subject_id" class="f-subject" data-locked="<?= $locked ? '1' : '0' ?>" required <?= $locked ? 'disabled' : '' ?>>
          <option value="">-- Първо избери паралелка --</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $curSubject === (int)$s['id'] ? 'selected' : '' ?>>
              <?= e($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Група
        <select name="group_no" <?= $locked ? 'disabled' : '' ?>>
          <?php foreach (GROUPS as $g => $lab): ?>
            <option value="<?= e($g) ?>" <?= $curGroup === (string)$g ? 'selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

    </div>

    <div class="route-card route-card-wide" data-route-card data-route-ready="<?= $entryRouteReady ? '1' : '0' ?>">
      <span class="small muted route-label">Автоматичен маршрут</span>
      <strong data-route-department><?= $entryDepartmentName !== '' ? e($entryDepartmentName) : 'Изберете паралелка и предмет' ?></strong>
      <span class="small route-recipient" data-route-recipient><?php if ($entryMethodistName !== ''): ?>Получател: <?= e($entryMethodistName) ?><?= $entryMethodistEmail !== '' ? ' · ' . e($entryMethodistEmail) : '' ?><?php else: ?>Получателят ще бъде определен автоматично.<?php endif; ?></span>
    </div>

    <div class="legend">
      <span><i class="n"></i> неотбелязана – <strong>прехвърля се за II срок</strong></span>
      <span><i class="g"></i> усвоена ✓</span>
      <span><i class="y"></i> частично усвоена –</span>
      <span><i class="r"></i> неусвоена ✕</span>
    </div>

    <div class="comps<?= $currentIsRpp ? ' is-rpp' : '' ?>" data-comps>
      <div class="chead">
        <span data-comp-title><?= $currentIsRpp ? 'Компетентности РПП ' : 'Компетентности ' ?><em class="spec-name muted"></em></span>
        <div class="chead-actions">
          <button type="button" class="btn small" data-bulk="mastered"<?= $locked ? ' disabled' : '' ?>>Всички усвоени</button>
          <button type="button" class="btn small ghost" data-bulk="clear"<?= $locked ? ' disabled' : '' ?>>Изчисти</button>
          <button type="button" class="btn small ghost" data-add-manual<?= (!$currentIsRpp || $locked) ? ' hidden' : '' ?>>+ Добави компетентност</button>
        </div>
        <span class="cnt"></span>
      </div>
      <div class="clist">
        <?php if ($currentIsRpp && $currentManualRows): ?>
          <?php foreach ($currentManualRows as $mi => $mr):
            $mst = (string)($mr['state'] ?? '');
            $msym = $mst === 'mastered' ? '✓' : ($mst === 'partial' ? '–' : ($mst === 'not_mastered' ? '✕' : ''));
          ?>
            <div class="manual-comp-row" data-manual-index="<?= (int)$mi ?>" data-state="<?= e($mst) ?>">
              <button type="button" class="manual-state-box" data-manual-state aria-label="Състояние"<?= $locked ? ' disabled' : '' ?>><?= e($msym) ?></button>
              <div class="manual-comp-text">
                <input type="text" name="manual_comp[<?= (int)$mi ?>][title]" value="<?= e((string)$mr['title']) ?>" maxlength="600"<?= $locked ? ' disabled' : '' ?>>
              </div>
              <input type="hidden" name="manual_comp[<?= (int)$mi ?>][state]" value="<?= e($mst) ?>" data-manual-state-input>
              <?php if (!$locked): ?><button type="button" class="manual-comp-remove" data-manual-remove aria-label="Премахни компетентността">×</button><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php elseif ($currentIsRpp && !$locked): ?>
          <div class="manual-comp-row" data-manual-index="0" data-state="">
            <button type="button" class="manual-state-box" data-manual-state aria-label="Състояние"></button>
            <div class="manual-comp-text"><input type="text" name="manual_comp[0][title]" value="" placeholder="Въведете компетентност…" maxlength="600"></div>
            <input type="hidden" name="manual_comp[0][state]" value="" data-manual-state-input>
            <button type="button" class="manual-comp-remove" data-manual-remove aria-label="Премахни компетентността">×</button>
          </div>
        <?php elseif ($currentIsRpp): ?>
          <p class="muted small manual-empty">Няма записани РПП компетентности към този анализ.</p>
        <?php elseif (($entry && !$currentIsRpp && $entryStandardComps) || (!$entry && $copySource && !$currentIsRpp && $copyStandardComps)): ?>
          <?php
            $renderStdComps = $entry ? $entryStandardComps : $copyStandardComps;
            $renderStdMarks = $entry ? $entryMarks : $copyMarks;
            $lastStdSection = null;
            foreach ($renderStdComps as $sc):
            $sid = (int)$sc['id'];
            $sst = (string)($renderStdMarks[$sid] ?? '');
            $sec = (string)($sc['section'] ?? '');
            if ($sec !== $lastStdSection):
              $lastStdSection = $sec;
              if ($sec !== ''): ?>
                <div class="comp-section"><?= e($sec) ?></div>
              <?php endif;
            endif; ?>
            <div class="comp" data-state="<?= e($sst) ?>" role="button" tabindex="0">
              <span class="box"></span>
              <span class="txt">
                <?php if (!empty($sc['code'])): ?><span class="code"><?= e((string)$sc['code']) ?></span><?php endif; ?>
                <?= e((string)$sc['title']) ?>
                <?php if (!empty($sc['program_name'])): ?><span class="tag spec"><?= e((string)$sc['program_name']) ?></span><?php endif; ?>
                <?php if (!empty($sc['source'])): ?><span class="src"><?= e((string)$sc['source']) ?></span><?php endif; ?>
              </span>
              <input type="hidden" name="comp[<?= $sid ?>]" value="<?= e($sst) ?>">
            </div>
          <?php endforeach; ?>
        <?php elseif ($entry && !$currentIsRpp): ?>
          <p class="muted small comp-empty">Няма намерени компетентности към този записан анализ.</p>
        <?php else: ?>
          <p class="muted small comp-empty">Изберете предмет и паралелка.</p>
        <?php endif; ?>
      </div>
    </div>

    <fieldset class="quant">
      <legend>Количествен анализ <span class="req">задължително</span></legend>
      <div class="grades">
        <label title="Брой ученици в паралелката">
          <span class="g-num">&#8721;</span>Ученици
          <input type="number" min="0" class="students" name="students_count"
                 value="<?= $copySource ? '' : (int)($entry['students_count'] ?? 0) ?>" <?= $locked ? 'disabled' : '' ?>>
        </label>
        <?php foreach ([['g2','2','Слаб'],['g3','3','Среден'],['g4','4','Добър'],
                        ['g5','5','Мн. добър'],['g6','6','Отличен']] as [$k,$n,$lab]): ?>
          <label title="<?= e($lab) ?> (<?= $n ?>)">
            <span class="g-num"><?= $n ?></span><?= e($lab) ?>
            <input type="number" min="0" class="gr" name="<?= $k ?>"
                   value="<?= $copySource ? '' : (int)($entry[$k] ?? 0) ?>" <?= $locked ? 'disabled' : '' ?>>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="calcline small">Общо оценки: <b class="c-tot">–</b> · Среден успех: <b class="c-avg">–</b>
         <span class="c-warn danger"></span></p>
    </fieldset>

    <label class="inline-note">Предложете мерки за подобряване качеството на обучение
      <span class="req">задължително</span>
      <textarea name="measures" rows="5" <?= $locked ? 'disabled' : '' ?>
        placeholder="Например: допълнителни консултации по темите с най-слаби резултати; повече практически упражнения"><?= e($entry['measures'] ?? $copySource['measures'] ?? '') ?></textarea>
    </label>
  </section>

  <?php if (!$locked): ?>
  <div class="actions">
    <button class="btn" name="action" value="draft" type="submit">Запази чернова</button>
    <button class="btn primary" name="action" value="send" type="submit"
            data-confirm="Анализът ще бъде изпратен директно към председателя на методическото обединение, определено автоматично според типа на предмета и професията/специалността на паралелката, и ще се заключи."
            data-confirm-title="Изпращане на анализа" data-confirm-ok="Изпрати">Изпрати към методиста</button>
  </div>
  <?php endif; ?>
</form>

<script>
  window.MO = window.MO || {};
  window.MO.apiComps = <?= json_encode(base_url('api/competencies.php'), JSON_UNESCAPED_SLASHES) ?>;
  window.MO.myEntries = <?= json_encode(base_url('pages/my_entries.php?term=' . $term), JSON_UNESCAPED_SLASHES) ?>;
  window.MO.term = <?= json_encode($term) ?>;
  window.MO.preset = <?= json_encode((object)($entry ? $entryMarks : $copyMarks), JSON_UNESCAPED_UNICODE) ?>;
  window.MO.manualPreset = <?= json_encode($entry ? $entryManual : $copyManual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.MO.manualPresetSubject = <?= json_encode((string)($entry['subject_id'] ?? $copySource['subject_id'] ?? '')) ?>;
  window.MO.entryId = <?= json_encode((string)($entry['id'] ?? 0)) ?>;
  window.MO.entryIsRpp = <?= json_encode($currentIsRpp ? '1' : '0') ?>;
  window.MO.copyMode = <?= json_encode($copySource ? '1' : '0') ?>;
  window.MO.copySubjectId = <?= json_encode((string)($copySource['subject_id'] ?? '')) ?>;
  window.MO.copyIsRpp = <?= json_encode($copyIsRpp ? '1' : '0') ?>;
  window.MO.copyStandardItems = <?= json_encode(array_map(static function (array $c) use ($copyMarks): array {
      $id = (int)$c['id'];
      return [
          'id' => $id,
          'code' => (string)($c['code'] ?? ''),
          'title' => (string)($c['title'] ?? ''),
          'state' => (string)($copyMarks[$id] ?? ''),
      ];
  }, $copyStandardComps), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.MO.subjectAvailability = <?= json_encode((object)$subjectAvailability, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php footer_html(); ?>
