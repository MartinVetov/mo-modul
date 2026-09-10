<?php
/**
 * Администрация на методическите обединения – модел v6 с РПП.
 *
 * Всяко МО има тип:
 *   - general      → обслужва общообразователни предмети;
 *   - professional → обслужва професии и специалности.
 *
 * Маршрутирането се извежда автоматично от типа и обхвата на МО.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('admin');
$isAjaxAdmin = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

function valid_user_id(?string $raw): ?int
{
    if ($raw === null || $raw === '') return null;
    $id = (int)$raw;
    return $id > 0 && one('SELECT id FROM users WHERE id=?', [$id]) ? $id : null;
}

function valid_department_type(?string $raw): string
{
    $type = (string)$raw;
    if (!in_array($type, ['general', 'professional'], true)) {
        throw new RuntimeException('Изберете валиден тип на методическото обединение.');
    }
    return $type;
}

function department_type_bg(string $type): string
{
    return $type === 'professional' ? 'Професионално' : 'Общообразователно';
}

function pill_picker_html(array $items, array $selected, string $inputName, string $placeholder = 'Добави…'): string
{
    $selected = array_values(array_unique(array_map('intval', $selected)));
    $itemIds = array_map(static fn($item) => (int)$item['id'], $items);
    $selected = array_values(array_intersect($selected, $itemIds));
    ob_start();
    ?>
    <div class="pill-picker" data-pill-picker data-input-name="<?= e($inputName) ?>">
      <div class="pill-selected" data-pill-selected>
        <?php $shown = 0; foreach ($items as $item): $iid=(int)$item['id']; if (!in_array($iid,$selected,true)) continue; $shown++; ?>
          <span class="selection-pill" data-pill-value="<?= $iid ?>">
            <span><?= e($item['name']) ?></span>
            <button type="button" class="pill-remove" data-pill-remove aria-label="Премахни <?= e($item['name']) ?>">×</button>
          </span>
        <?php endforeach; ?>
        <span class="pill-empty<?= $shown ? ' is-hidden' : '' ?>" data-pill-empty>Няма избрани</span>
      </div>
      <div data-pill-hidden>
        <?php foreach ($selected as $sid): ?>
          <input type="hidden" name="<?= e($inputName) ?>" value="<?= (int)$sid ?>" data-pill-input="<?= (int)$sid ?>">
        <?php endforeach; ?>
      </div>
      <select class="pill-add" data-pill-add aria-label="<?= e($placeholder) ?>">
        <option value=""><?= e($placeholder) ?></option>
        <?php foreach ($items as $item): $iid=(int)$item['id']; if (in_array($iid,$selected,true)) continue; ?>
          <option value="<?= $iid ?>"><?= e($item['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
    return (string)ob_get_clean();
}

function department_row(int $id): ?array
{
    return one('SELECT id,name,department_type,is_active FROM mo_departments WHERE id=?', [$id]);
}

/**
 * Записва професиите/специалностите на професионално МО.
 * Една програма има само едно отговорно професионално МО; при избор се мести.
 */
function save_department_programs(int $departmentId, array $programIds): void
{
    $dep = department_row($departmentId);
    if (!$dep || !(int)$dep['is_active']) throw new RuntimeException('Методическото обединение не е намерено или е скрито.');
    if (($dep['department_type'] ?? '') !== 'professional') {
        throw new RuntimeException('Професии и специалности могат да се задават само на професионално МО.');
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $programIds), static fn($x) => $x > 0)));
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = all("SELECT id FROM mo_programs WHERE id IN ($marks) AND is_active=1", $ids);
        $valid = array_map('intval', array_column($rows, 'id'));
        sort($valid); sort($ids);
        if ($valid !== $ids) throw new RuntimeException('Има избрана невалидна или скрита професия/специалност.');
    }

    q('DELETE FROM mo_department_programs WHERE department_id=?', [$departmentId]);
    foreach ($ids as $programId) {
        q('INSERT INTO mo_department_programs (department_id,program_id,is_active)
           VALUES (?,?,1)
           ON DUPLICATE KEY UPDATE department_id=VALUES(department_id), is_active=1',
          [$departmentId, $programId]);
    }
}

/**
 * Записва общообразователните предмети на общообразователно МО.
 * Допускат се само предмети, които имат общи компетентности и нямат
 * нито една активна програмно-специфична компетентност.
 */
function save_department_subjects(int $departmentId, array $subjectIds): void
{
    $dep = department_row($departmentId);
    if (!$dep || !(int)$dep['is_active']) throw new RuntimeException('Методическото обединение не е намерено или е скрито.');
    if (($dep['department_type'] ?? '') !== 'general') {
        throw new RuntimeException('Общообразователни предмети могат да се задават само на общообразователно МО.');
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $subjectIds), static fn($x) => $x > 0)));
    foreach ($ids as $subjectId) {
        if (!one('SELECT id FROM mo_subjects WHERE id=? AND is_active=1', [$subjectId])) {
            throw new RuntimeException('Избран е невалиден или скрит предмет.');
        }
        if (subject_training_type($subjectId) !== 'general') {
            throw new RuntimeException('Избран е предмет, който не е общообразователен.');
        }
    }

    $oldIds = array_map('intval', array_column(
        all('SELECT subject_id FROM mo_subject_departments WHERE department_id=?', [$departmentId]),
        'subject_id'
    ));

    // Махаме стария обхват на това МО.
    q('DELETE FROM mo_subject_departments WHERE department_id=?', [$departmentId]);

    // Един общообразователен предмет принадлежи само на едно МО.
    foreach ($ids as $subjectId) {
        q('DELETE FROM mo_subject_departments WHERE subject_id=?', [$subjectId]);
        q('INSERT INTO mo_subject_departments (subject_id,department_id,is_active) VALUES (?,?,1)', [$subjectId, $departmentId]);
    }

    foreach (array_values(array_unique(array_merge($oldIds, $ids))) as $subjectId) {
        sync_subject_legacy_department((int)$subjectId);
    }
}

/** Смяна на типа и изчистване на несъвместимия обхват. */
function save_department_type(int $departmentId, string $type): void
{
    $dep = department_row($departmentId);
    if (!$dep) throw new RuntimeException('Методическото обединение не е намерено.');
    $type = valid_department_type($type);
    $old = (string)($dep['department_type'] ?? 'general');
    if ($old === $type) return;

    if ($type === 'general') {
        q('DELETE FROM mo_department_programs WHERE department_id=?', [$departmentId]);
    } else {
        $oldSubjects = array_map('intval', array_column(
            all('SELECT subject_id FROM mo_subject_departments WHERE department_id=?', [$departmentId]),
            'subject_id'
        ));
        q('DELETE FROM mo_subject_departments WHERE department_id=?', [$departmentId]);
        foreach ($oldSubjects as $subjectId) sync_subject_legacy_department($subjectId);
    }

    q('UPDATE mo_departments SET department_type=? WHERE id=?', [$type, $departmentId]);
}


/** Записва приложимостта на РПП предмет по класове и програми. */
function save_rpp_scope(int $subjectId, array $grades, array $programIds): void
{
    $grades = array_values(array_unique(array_filter(array_map('intval', $grades), static fn($g) => $g >= 8 && $g <= 12)));
    sort($grades);
    if (!$grades) throw new RuntimeException('Изберете поне един клас за РПП предмета.');

    $programIds = array_values(array_unique(array_filter(array_map('intval', $programIds), static fn($x) => $x > 0)));
    if (!$programIds) throw new RuntimeException('Изберете поне една професия или специалност за РПП предмета.');
    $marks = implode(',', array_fill(0, count($programIds), '?'));
    $valid = array_map('intval', array_column(all("SELECT id FROM mo_programs WHERE id IN ($marks) AND is_active=1", $programIds), 'id'));
    sort($valid); sort($programIds);
    if ($valid !== $programIds) throw new RuntimeException('Има избрана невалидна или скрита професия/специалност.');

    q('DELETE FROM mo_rpp_subject_grades WHERE subject_id=?', [$subjectId]);
    foreach ($grades as $grade) q('INSERT INTO mo_rpp_subject_grades (subject_id,grade_level) VALUES (?,?)', [$subjectId,$grade]);

    q('DELETE FROM mo_rpp_subject_programs WHERE subject_id=?', [$subjectId]);
    foreach ($programIds as $programId) q('INSERT INTO mo_rpp_subject_programs (subject_id,program_id) VALUES (?,?)', [$subjectId,$programId]);
}

function rpp_subject_row(int $subjectId): ?array
{
    return one('SELECT s.id,s.name,s.is_active,rs.is_active AS rpp_active
                FROM mo_subjects s JOIN mo_rpp_subjects rs ON rs.subject_id=s.id
                WHERE s.id=?', [$subjectId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');

    db()->beginTransaction();
    try {
        if ($act === 'dep_add') {
            $type = valid_department_type($_POST['department_type'] ?? null);
            $name = trim((string)($_POST['name'] ?? ''));
            $chairRaw = (string)($_POST['chair_id'] ?? '');
            $chair = valid_user_id($chairRaw);
            if ($chairRaw !== '' && !$chair) throw new RuntimeException('Избраният председател не е валиден потребител.');
            if ($name === '') throw new RuntimeException('Въведете име на методическото обединение.');
            if (one('SELECT id FROM mo_departments WHERE name=?', [$name])) throw new RuntimeException('Такова МО вече съществува.');
            q('INSERT INTO mo_departments (name,department_type,chair_id) VALUES (?,?,?)', [$name, $type, $chair]);
            sync_methodist_roles((int)$u['id']);
            flash('Методическото обединение е добавено.');

        } elseif ($act === 'dep_rename') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if (!$id || $name === '') throw new RuntimeException('Невалидно МО или празно име.');
            if (one('SELECT id FROM mo_departments WHERE name=? AND id<>?', [$name, $id])) throw new RuntimeException('Друго МО вече използва това име.');
            q('UPDATE mo_departments SET name=? WHERE id=?', [$name, $id]);
            flash('Името на МО е променено.');

        } elseif ($act === 'dep_type') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Липсва методическо обединение.');
            $type = valid_department_type($_POST['department_type'] ?? null);
            save_department_type($id, $type);
            flash('Типът на МО е записан. Несъвместимите стари връзки са премахнати.');

        } elseif ($act === 'dep_heads') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id || !one('SELECT id FROM mo_departments WHERE id=?', [$id])) throw new RuntimeException('Методическото обединение не е намерено.');
            $chairRaw = (string)($_POST['chair_id'] ?? '');
            $deputyRaw = (string)($_POST['deputy_id'] ?? '');
            $chair = valid_user_id($chairRaw);
            $deputy = valid_user_id($deputyRaw);
            if ($chairRaw !== '' && !$chair) throw new RuntimeException('Избраният председател не е валиден потребител.');
            if ($deputyRaw !== '' && !$deputy) throw new RuntimeException('Избраният заместник не е валиден потребител.');
            if ($chair && $deputy && $chair === $deputy) throw new RuntimeException('Председателят и заместникът трябва да са различни хора.');
            q('UPDATE mo_departments SET chair_id=?,deputy_id=? WHERE id=?', [$chair, $deputy, $id]);
            sync_methodist_roles((int)$u['id']);
            flash($chair ? 'Ръководството е записано.' : 'Ръководството е записано. Без председател нови анализи към това МО не могат да бъдат изпращани.', $chair ? 'ok' : 'warn');

        } elseif ($act === 'dep_programs') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Липсва методическо обединение.');
            save_department_programs($id, (array)($_POST['program_ids'] ?? []));
            flash('Професиите и специалностите на МО са записани.');

        } elseif ($act === 'dep_subjects') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Липсва методическо обединение.');
            save_department_subjects($id, (array)($_POST['subject_ids'] ?? []));
            flash('Общообразователните предмети на МО са записани.');

        } elseif ($act === 'rpp_add') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Въведете име на РПП предмета.');
            $subject = one('SELECT id,is_active FROM mo_subjects WHERE name=?', [$name]);
            if ($subject) {
                $sid = (int)$subject['id'];
                if (one('SELECT id FROM mo_competencies WHERE subject_id=? LIMIT 1', [$sid])) {
                    throw new RuntimeException('Предмет с това име вече има официални компетентности и не може да бъде добавен като РПП.');
                }
                if (one('SELECT subject_id FROM mo_rpp_subjects WHERE subject_id=?', [$sid])) {
                    throw new RuntimeException('Такъв РПП предмет вече съществува.');
                }
                q('UPDATE mo_subjects SET is_active=1,department_id=NULL WHERE id=?', [$sid]);
            } else {
                q('INSERT INTO mo_subjects (name,department_id,is_active) VALUES (?,NULL,1)', [$name]);
                $sid = (int)db()->lastInsertId();
            }
            q('INSERT INTO mo_rpp_subjects (subject_id,is_active) VALUES (?,1)', [$sid]);
            save_rpp_scope($sid, (array)($_POST['grade_levels'] ?? []), (array)($_POST['program_ids'] ?? []));
            flash('РПП предметът е добавен.');

        } elseif ($act === 'rpp_update') {
            $sid = (int)($_POST['subject_id'] ?? 0);
            $row = rpp_subject_row($sid);
            if (!$row) throw new RuntimeException('РПП предметът не е намерен.');
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Въведете име на РПП предмета.');
            if (one('SELECT id FROM mo_subjects WHERE name=? AND id<>?', [$name,$sid])) {
                throw new RuntimeException('Друг предмет вече използва това име.');
            }
            q('UPDATE mo_subjects SET name=? WHERE id=?', [$name,$sid]);
            save_rpp_scope($sid, (array)($_POST['grade_levels'] ?? []), (array)($_POST['program_ids'] ?? []));
            flash('РПП предметът е записан.');

        } elseif ($act === 'rpp_toggle') {
            $sid = (int)($_POST['subject_id'] ?? 0);
            $row = rpp_subject_row($sid);
            if (!$row) throw new RuntimeException('РПП предметът не е намерен.');
            $new = ((int)$row['rpp_active'] === 1 && (int)$row['is_active'] === 1) ? 0 : 1;
            q('UPDATE mo_rpp_subjects SET is_active=? WHERE subject_id=?', [$new,$sid]);
            q('UPDATE mo_subjects SET is_active=? WHERE id=?', [$new,$sid]);
            flash($new ? 'РПП предметът е активиран.' : 'РПП предметът е скрит.');

        } elseif ($act === 'rpp_delete') {
            $sid = (int)($_POST['subject_id'] ?? 0);
            $row = rpp_subject_row($sid);
            if (!$row) throw new RuntimeException('РПП предметът не е намерен.');
            $entries = (int)(one('SELECT COUNT(*) n FROM mo_entries WHERE subject_id=?', [$sid])['n'] ?? 0);
            $comps = (int)(one('SELECT COUNT(*) n FROM mo_competencies WHERE subject_id=?', [$sid])['n'] ?? 0);
            if ($entries || $comps) {
                throw new RuntimeException('РПП предметът вече се използва и не може да бъде изтрит. Скрийте го вместо това.');
            }
            q('DELETE FROM mo_rpp_subjects WHERE subject_id=?', [$sid]);
            q('DELETE FROM mo_subjects WHERE id=?', [$sid]);
            flash('РПП предметът е изтрит.');

        } elseif ($act === 'dep_toggle') {
            $id = (int)($_POST['id'] ?? 0);
            q('UPDATE mo_departments SET is_active=1-is_active WHERE id=?', [$id]);
            sync_methodist_roles((int)$u['id']);
            flash('Статусът на МО е променен.');

        } elseif ($act === 'dep_delete') {
            $id = (int)($_POST['id'] ?? 0);
            $subjectLinks = (int)(one('SELECT COUNT(*) n FROM mo_subject_departments WHERE department_id=?', [$id])['n'] ?? 0);
            $programLinks = (int)(one('SELECT COUNT(*) n FROM mo_department_programs WHERE department_id=?', [$id])['n'] ?? 0);
            $entries = (int)(one('SELECT COUNT(*) n FROM mo_entries WHERE department_id=?', [$id])['n'] ?? 0);
            $summaries = (int)(one('SELECT COUNT(*) n FROM mo_summaries WHERE department_id=?', [$id])['n'] ?? 0);
            if ($subjectLinks || $programLinks || $entries || $summaries) {
                throw new RuntimeException("МО не може да се изтрие: има $subjectLinks предметни връзки, $programLinks професии/специалности, $entries анализа и $summaries обобщения. Скрийте го вместо това.");
            }
            q('DELETE FROM mo_departments WHERE id=?', [$id]);
            sync_methodist_roles((int)$u['id']);
            flash('Методическото обединение е изтрито.');

        } else {
            throw new RuntimeException('Непознато действие.');
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash($e->getMessage(), 'err');
    }

    if ($isAjaxAdmin) {
        $f = flash() ?? ['msg' => 'Промяната е записана.', 'type' => 'ok'];
        $type = (string)($f['type'] ?? 'ok');
        $ok = $type !== 'err';
        json_out([
            'ok' => $ok,
            'type' => $type,
            'message' => (string)($f['msg'] ?? ''),
            'refresh' => 'all',
        ], $ok ? 200 : 422);
    }

    redirect(base_url('pages/admin_departments.php'));
}

$people = all('SELECT id,' . user_name_sql('u') . ' AS name,email FROM users u ORDER BY name,email');
$programs = programs(null, true);
$professions = array_values(array_filter($programs, static fn($p) => ($p['kind'] ?? '') === 'profession'));
$specialties = array_values(array_filter($programs, static fn($p) => ($p['kind'] ?? '') === 'specialty'));
$gradeItems = array_map(static fn($g) => ['id'=>$g,'name'=>$g . ' клас'], range(8,12));

$rppSubjects = all(
    'SELECT s.id,s.name,s.is_active,rs.is_active AS rpp_active,
            (SELECT COUNT(*) FROM mo_entries e WHERE e.subject_id=s.id) AS n_entries
     FROM mo_rpp_subjects rs
     JOIN mo_subjects s ON s.id=rs.subject_id
     ORDER BY s.name'
);
$rppGrades = [];
foreach (all('SELECT subject_id,grade_level FROM mo_rpp_subject_grades ORDER BY grade_level') as $r) {
    $rppGrades[(int)$r['subject_id']][] = (int)$r['grade_level'];
}
$rppPrograms = [];
foreach (all('SELECT subject_id,program_id FROM mo_rpp_subject_programs') as $r) {
    $rppPrograms[(int)$r['subject_id']][] = (int)$r['program_id'];
}


// Общoобразователни предмети според компетентностите.
// Изключение: „Чужд език по професията“ се предлага тук независимо от
// програмно-специфичните си компетентности.
$generalSubjects = all(
    "SELECT s.id,s.name
     FROM mo_subjects s
     WHERE s.is_active=1
       AND (
         (
           s.name='Чужд език по професията'
           AND EXISTS (SELECT 1 FROM mo_competencies x WHERE x.subject_id=s.id AND x.is_active=1)
         )
         OR
         (
           EXISTS (
             SELECT 1 FROM mo_competencies k
             WHERE k.subject_id=s.id AND k.is_active=1 AND k.program_id IS NULL
           )
           AND NOT EXISTS (
             SELECT 1 FROM mo_competencies k
             WHERE k.subject_id=s.id AND k.is_active=1 AND k.program_id IS NOT NULL
           )
         )
       )
     ORDER BY s.name"
);

$deps = all(
    'SELECT d.*,
            (SELECT COUNT(*) FROM mo_subject_departments sd WHERE sd.department_id=d.id AND sd.is_active=1) n_subjects,
            (SELECT COUNT(*) FROM mo_department_programs dp WHERE dp.department_id=d.id AND dp.is_active=1) n_programs,
            (SELECT COUNT(*) FROM mo_entries e WHERE e.department_id=d.id AND e.status="sent") n_entries
     FROM mo_departments d
     ORDER BY FIELD(d.department_type,"general","professional"), d.name'
);

$depPrograms = [];
foreach (all('SELECT department_id,program_id FROM mo_department_programs WHERE is_active=1') as $r) {
    $depPrograms[(int)$r['department_id']][] = (int)$r['program_id'];
}
$depSubjects = [];
foreach (all('SELECT department_id,subject_id FROM mo_subject_departments WHERE is_active=1') as $r) {
    $depSubjects[(int)$r['department_id']][] = (int)$r['subject_id'];
}

$unassignedGeneralSubjects = (int)(one(
    "SELECT COUNT(*) n
     FROM mo_subjects s
     WHERE s.is_active=1
       AND (
         (
           s.name='Чужд език по професията'
           AND EXISTS (SELECT 1 FROM mo_competencies x WHERE x.subject_id=s.id AND x.is_active=1)
         )
         OR
         (
           EXISTS (SELECT 1 FROM mo_competencies k WHERE k.subject_id=s.id AND k.is_active=1 AND k.program_id IS NULL)
           AND NOT EXISTS (SELECT 1 FROM mo_competencies k WHERE k.subject_id=s.id AND k.is_active=1 AND k.program_id IS NOT NULL)
         )
       )
       AND NOT EXISTS (
           SELECT 1
           FROM mo_subject_departments sd
           JOIN mo_departments d ON d.id=sd.department_id
           WHERE sd.subject_id=s.id AND sd.is_active=1 AND d.is_active=1 AND d.department_type='general'
       )"
)['n'] ?? 0);

$programsWithoutDep = (int)(one(
    'SELECT COUNT(*) n
     FROM mo_programs p
     WHERE p.is_active=1
       AND NOT EXISTS (
           SELECT 1
           FROM mo_department_programs dp
           JOIN mo_departments d ON d.id=dp.department_id
           WHERE dp.program_id=p.id AND dp.is_active=1 AND d.is_active=1 AND d.department_type="professional"
       )'
)['n'] ?? 0);

$depsWithoutChair = (int)(one('SELECT COUNT(*) n FROM mo_departments WHERE is_active=1 AND chair_id IS NULL')['n'] ?? 0);
$mixedSubjects = (int)(one(
    "SELECT COUNT(*) n FROM mo_subjects s
     WHERE s.is_active=1
       AND s.name <> 'Чужд език по професията'
       AND EXISTS (SELECT 1 FROM mo_competencies a WHERE a.subject_id=s.id AND a.is_active=1 AND a.program_id IS NULL)
       AND EXISTS (SELECT 1 FROM mo_competencies b WHERE b.subject_id=s.id AND b.is_active=1 AND b.program_id IS NOT NULL)"
)['n'] ?? 0);

$adminPostUrl = base_url('pages/admin_departments.php');

header_html('Методически обединения', 'admin_departments');
section_title('Методически обединения', '<a class="btn small ghost" href="' . base_url('pages/admin_setup.php') . '">Години, класове и програми</a>');
?>

<div id="routingStatus" class="panel route-health" data-admin-endpoint="<?= e($adminPostUrl) ?>">
  <h2>Състояние</h2>
  <div class="stats">
    <div class="stat"><span class="k"><?= $unassignedGeneralSubjects ?></span><span>общообразователни предмета без МО</span></div>
    <div class="stat"><span class="k"><?= $programsWithoutDep ?></span><span>професии/специалности без МО</span></div>
    <div class="stat"><span class="k"><?= $depsWithoutChair ?></span><span>активни МО без председател</span></div>
    <div class="stat"><span class="k"><?= count($rppSubjects) ?></span><span>РПП предмета</span></div>
  </div>
  <?php if ($mixedSubjects): ?>
    <p class="small danger"><strong><?= $mixedSubjects ?></strong> предмета имат едновременно общи и програмно-специфични компетентности. Те се третират като професионална подготовка и не се предлагат в общообразователните МО. „Чужд език по професията“ е изключение.</p>
  <?php else: ?>
    <p class="muted small">Общообразователните МО се свързват с предмети. Професионалните МО се свързват с професии и специалности. Получател на анализа е председателят на съответното МО.</p>
  <?php endif; ?>
</div>

<div class="panel classic-admin-create">
  <h2>Ново методическо обединение</h2>
  <form action="<?= e($adminPostUrl) ?>" method="post" class="department-create-v5" data-ajax-admin data-refresh="all">
    <?= csrf_field() ?><input type="hidden" name="action" value="dep_add">
    <label>Тип
      <select name="department_type" required>
        <option value="general">Общообразователно</option>
        <option value="professional">Професионално</option>
      </select>
    </label>
    <label>Име
      <input type="text" name="name" placeholder="МО „...“" required>
    </label>
    <label>Председател
      <select name="chair_id">
        <option value="">– може и по-късно –</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name'] ?: $p['email']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn primary" type="submit">Добави МО</button>
  </form>
</div>

<div class="panel classic-admin-panel department-admin-v5" id="departmentsPanel">
  <h2>Методически обединения</h2>
  <p class="muted small">Изберете типа на МО, ръководството и неговия обхват. Настройките за обхвата се променят автоматично според типа.</p>

  <div class="department-v5-list">
    <?php foreach ($deps as $d):
      $depId = (int)$d['id'];
      $depType = (string)($d['department_type'] ?? 'general');
      $selectedPrograms = $depPrograms[$depId] ?? [];
      $selectedSubjects = $depSubjects[$depId] ?? [];
    ?>
      <section class="department-v5-item <?= $d['is_active'] ? '' : 'off' ?>">
        <div class="department-v5-name">
          <form action="<?= e($adminPostUrl) ?>" method="post" class="department-name-v5" data-ajax-admin data-refresh="all">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_rename">
            <input type="hidden" name="id" value="<?= $depId ?>">
            <input type="text" name="name" value="<?= e($d['name']) ?>" aria-label="Име на методическото обединение">
            <button class="btn small" type="submit">Запиши име</button>
          </form>
        </div>

        <div class="department-v5-meta">
          <form action="<?= e($adminPostUrl) ?>" method="post" class="department-type-v5" data-ajax-admin data-refresh="all">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_type">
            <input type="hidden" name="id" value="<?= $depId ?>">
            <label>Тип на МО
              <select name="department_type">
                <option value="general" <?= $depType==='general'?'selected':'' ?>>Общообразователно</option>
                <option value="professional" <?= $depType==='professional'?'selected':'' ?>>Професионално</option>
              </select>
            </label>
            <button class="btn small" type="submit">Запиши тип</button>
          </form>
          <div class="department-v5-status">
            <span class="badge <?= $depType==='professional'?'info':'ok' ?>"><?= e(department_type_bg($depType)) ?></span>
            <?= $d['is_active'] ? '<span class="badge ok">активно</span>' : '<span class="badge">скрито</span>' ?>
            <?php if (!$d['chair_id']): ?><span class="small danger">Няма председател</span><?php endif; ?>
          </div>
        </div>

        <form action="<?= e($adminPostUrl) ?>" method="post" class="department-heads-v5" data-ajax-admin data-refresh="all">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="dep_heads">
          <input type="hidden" name="id" value="<?= $depId ?>">
          <label>Председател
            <select name="chair_id">
              <option value="">– без председател –</option>
              <?php foreach ($people as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$d['chair_id']===(int)$p['id']?'selected':'' ?>><?= e($p['name'] ?: $p['email']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Заместник
            <select name="deputy_id">
              <option value="">– без заместник –</option>
              <?php foreach ($people as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$d['deputy_id']===(int)$p['id']?'selected':'' ?>><?= e($p['name'] ?: $p['email']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn small primary" type="submit">Запиши ръководството</button>
        </form>

        <div class="department-v5-scope">
          <?php if ($depType === 'general'): ?>
            <div class="scope-v5-head">
              <div>
                <h3>Общообразователни предмети</h3>
                <p class="muted small">Показват се само общообразователни предмети. „Чужд език по професията“ е включен като изрично изключение.</p>
              </div>
              <span class="scope-count"><?= count($selectedSubjects) ?> избрани</span>
            </div>
            <form action="<?= e($adminPostUrl) ?>" method="post" class="scope-v5-form" data-ajax-admin data-refresh="all">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="dep_subjects">
              <input type="hidden" name="id" value="<?= $depId ?>">
              <?= pill_picker_html($generalSubjects, $selectedSubjects, 'subject_ids[]', 'Добави предмет…') ?>
              <button class="btn small primary" type="submit">Запиши предметите</button>
            </form>
          <?php else: ?>
            <div class="scope-v5-head">
              <div>
                <h3>Професии и специалности</h3>
                <p class="muted small">Изберете кои професии и специалности се обслужват от това МО.</p>
              </div>
              <span class="scope-count"><?= count($selectedPrograms) ?> избрани</span>
            </div>
            <form action="<?= e($adminPostUrl) ?>" method="post" class="scope-v5-form professional-scope-v5" data-ajax-admin data-refresh="all">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="dep_programs">
              <input type="hidden" name="id" value="<?= $depId ?>">
              <div class="scope-pill-columns">
                <div class="scope-pill-group">
                  <span class="field-caption">Професии</span>
                  <?= pill_picker_html($professions, $selectedPrograms, 'program_ids[]', 'Добави професия…') ?>
                </div>
                <div class="scope-pill-group">
                  <span class="field-caption">Специалности</span>
                  <?= pill_picker_html($specialties, $selectedPrograms, 'program_ids[]', 'Добави специалност…') ?>
                </div>
              </div>
              <button class="btn small primary" type="submit">Запиши обхвата</button>
            </form>
          <?php endif; ?>
        </div>

        <div class="department-v5-footer">
          <div class="admin-stats-inline horizontal">
            <?php if ($depType === 'general'): ?>
              <span><strong><?= (int)$d['n_subjects'] ?></strong> предмета</span>
            <?php else: ?>
              <span><strong><?= (int)$d['n_programs'] ?></strong> професии/специалности</span>
            <?php endif; ?>
            <span><strong><?= (int)$d['n_entries'] ?></strong> изпратени анализа</span>
          </div>
          <div class="leadership-actions">
            <form action="<?= e($adminPostUrl) ?>" method="post" data-ajax-admin data-refresh="all">
              <?= csrf_field() ?><input type="hidden" name="action" value="dep_toggle"><input type="hidden" name="id" value="<?= $depId ?>">
              <button class="btn small ghost" type="submit"><?= $d['is_active']?'Скрий':'Върни' ?></button>
            </form>
            <form action="<?= e($adminPostUrl) ?>" method="post" data-ajax-admin data-refresh="all" data-danger data-confirm="Изтриването е разрешено само за напълно празно МО.">
              <?= csrf_field() ?><input type="hidden" name="action" value="dep_delete"><input type="hidden" name="id" value="<?= $depId ?>">
              <button class="btn small danger" type="submit">Изтрий</button>
            </form>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
    <?php if (!$deps): ?><p class="muted">Още няма създадени методически обединения.</p><?php endif; ?>
  </div>
</div>

<div class="panel rpp-admin-panel" id="rppPanel">
  <div class="rpp-panel-head">
    <div>
      <h2>Разширена професионална подготовка (РПП)</h2>
      <p class="muted small">Тук се добавят РПП предмети без предварително заредени компетентности. Изберете класовете и професиите/специалностите, за които предметът е валиден. В „Нов анализ“ учителят въвежда компетентностите ръчно.</p>
    </div>
    <span class="badge info"><?= count($rppSubjects) ?> предмета</span>
  </div>

  <form action="<?= e($adminPostUrl) ?>" method="post" class="rpp-create-form" data-ajax-admin data-refresh="all">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="rpp_add">
    <label class="rpp-name-field">Име на РПП предмет
      <input type="text" name="name" placeholder="Напр. РПП – Разработка на мобилни приложения" required>
    </label>
    <div class="rpp-scope-grid">
      <div class="scope-pill-group">
        <span class="field-caption">Класове</span>
        <?= pill_picker_html($gradeItems, [], 'grade_levels[]', 'Добави клас…') ?>
      </div>
      <div class="scope-pill-group">
        <span class="field-caption">Професии</span>
        <?= pill_picker_html($professions, [], 'program_ids[]', 'Добави професия…') ?>
      </div>
      <div class="scope-pill-group">
        <span class="field-caption">Специалности</span>
        <?= pill_picker_html($specialties, [], 'program_ids[]', 'Добави специалност…') ?>
      </div>
    </div>
    <div class="rpp-create-actions"><button class="btn primary" type="submit">Добави РПП предмет</button></div>
  </form>

  <div class="rpp-list">
    <?php foreach ($rppSubjects as $rs): $sid=(int)$rs['id']; $selGrades=$rppGrades[$sid]??[]; $selPrograms=$rppPrograms[$sid]??[]; ?>
      <section class="rpp-item <?= ((int)$rs['is_active'] && (int)$rs['rpp_active']) ? '' : 'off' ?>">
        <form action="<?= e($adminPostUrl) ?>" method="post" class="rpp-edit-form" data-ajax-admin data-refresh="all">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="rpp_update">
          <input type="hidden" name="subject_id" value="<?= $sid ?>">
          <div class="rpp-title-row">
            <label>Име
              <input type="text" name="name" value="<?= e($rs['name']) ?>" required>
            </label>
            <span class="badge <?= ((int)$rs['is_active'] && (int)$rs['rpp_active']) ? 'ok' : '' ?>"><?= ((int)$rs['is_active'] && (int)$rs['rpp_active']) ? 'активен' : 'скрит' ?></span>
          </div>
          <div class="rpp-scope-grid">
            <div class="scope-pill-group"><span class="field-caption">Класове</span><?= pill_picker_html($gradeItems,$selGrades,'grade_levels[]','Добави клас…') ?></div>
            <div class="scope-pill-group"><span class="field-caption">Професии</span><?= pill_picker_html($professions,$selPrograms,'program_ids[]','Добави професия…') ?></div>
            <div class="scope-pill-group"><span class="field-caption">Специалности</span><?= pill_picker_html($specialties,$selPrograms,'program_ids[]','Добави специалност…') ?></div>
          </div>
          <div class="rpp-item-actions">
            <span class="small muted"><?= (int)$rs['n_entries'] ?> анализа</span>
            <button class="btn small primary" type="submit">Запиши</button>
          </div>
        </form>
        <div class="rpp-secondary-actions">
          <form action="<?= e($adminPostUrl) ?>" method="post" data-ajax-admin data-refresh="all">
            <?= csrf_field() ?><input type="hidden" name="action" value="rpp_toggle"><input type="hidden" name="subject_id" value="<?= $sid ?>">
            <button class="btn small ghost" type="submit"><?= ((int)$rs['is_active'] && (int)$rs['rpp_active']) ? 'Скрий' : 'Активирай' ?></button>
          </form>
          <form action="<?= e($adminPostUrl) ?>" method="post" data-ajax-admin data-refresh="all" data-danger data-confirm="Изтриването е възможно само ако РПП предметът още не е използван в анализ.">
            <?= csrf_field() ?><input type="hidden" name="action" value="rpp_delete"><input type="hidden" name="subject_id" value="<?= $sid ?>">
            <button class="btn small danger" type="submit">Изтрий</button>
          </form>
        </div>
      </section>
    <?php endforeach; ?>
    <?php if (!$rppSubjects): ?><p class="muted">Още няма добавени РПП предмети.</p><?php endif; ?>
  </div>
</div>

<?php footer_html(); ?>
