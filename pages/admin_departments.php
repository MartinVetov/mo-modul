<?php
/**
 * Методически обединения: ръководство и разпределяне на предметите.
 *
 * Един предмет може да има различно МО според професията/специалността.
 * Правило с program_id=NULL означава „всички професии/специалности“, а
 * конкретно правило за program_id има приоритет пред общото.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_role('admin');

/** Запис/презапис на правило предмет + програма -> МО. */
$saveAssignment = static function (int $subjectId, ?int $programId, int $departmentId): void {
    if ($programId === null) {
        $old = one('SELECT id FROM mo_subject_program_departments
                    WHERE subject_id = ? AND program_id IS NULL LIMIT 1', [$subjectId]);
    } else {
        $old = one('SELECT id FROM mo_subject_program_departments
                    WHERE subject_id = ? AND program_id = ? LIMIT 1', [$subjectId, $programId]);
    }

    if ($old) {
        q('UPDATE mo_subject_program_departments
           SET department_id = ?, is_active = 1
           WHERE id = ?', [$departmentId, (int)$old['id']]);
    } else {
        q('INSERT INTO mo_subject_program_departments
           (subject_id, program_id, department_id, is_active)
           VALUES (?,?,?,1)', [$subjectId, $programId, $departmentId]);
    }

    /* Старото поле се пази синхронизирано само за общото правило,
       за да не се чупят стари справки/инсталации. */
    if ($programId === null) {
        q('UPDATE mo_subjects SET department_id = ? WHERE id = ?', [$departmentId, $subjectId]);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $did = (int)($_POST['id'] ?? 0);

    if ($act === 'dep_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash('Въведете име на методическото обединение.', 'err');
        } elseif (one('SELECT id FROM mo_departments WHERE name = ?', [$name])) {
            flash('Такова МО вече съществува.', 'err');
        } else {
            q('INSERT INTO mo_departments (name) VALUES (?)', [$name]);
            flash('Методическото обединение е добавено.');
        }
    } elseif ($act === 'dep_rename') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '') {
            q('UPDATE mo_departments SET name = ? WHERE id = ?', [$name, $did]);
            flash('Името е сменено.');
        }
    } elseif ($act === 'dep_heads') {
        $chair  = ($_POST['chair_id']  ?? '') !== '' ? (int)$_POST['chair_id']  : null;
        $deputy = ($_POST['deputy_id'] ?? '') !== '' ? (int)$_POST['deputy_id'] : null;
        if ($chair && $deputy && $chair === $deputy) {
            flash('Председателят и заместникът не може да са един и същ човек.', 'err');
        } else {
            q('UPDATE mo_departments SET chair_id = ?, deputy_id = ? WHERE id = ?', [$chair, $deputy, $did]);
            flash('Ръководството на МО е записано.');
        }
    } elseif ($act === 'dep_toggle') {
        q('UPDATE mo_departments SET is_active = 1 - is_active WHERE id = ?', [$did]);
    } elseif ($act === 'dep_delete') {
        $n = (int)(one('SELECT COUNT(*) n FROM mo_subject_program_departments
                        WHERE department_id = ? AND is_active = 1', [$did])['n'] ?? 0);
        if ($n > 0) {
            flash("Към това МО има $n активни назначения на предмети. Преместете или премахнете назначенията, преди да го изтриете.", 'err');
        } else {
            q('DELETE FROM mo_departments WHERE id = ?', [$did]);
            flash('Методическото обединение е изтрито.');
        }
    } elseif ($act === 'subject_add') {
        $name = normalize_practice_subject((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash('Въведете име на предмет.', 'err');
        } else {
            q('INSERT INTO mo_subjects (name, is_active) VALUES (?,1)
               ON DUPLICATE KEY UPDATE is_active = 1', [$name]);
            flash('Предметът е записан. Задайте по-долу конкретните професии/специалности и съответните им МО.');
        }
    } elseif ($act === 'assignment_save') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $depId = (int)($_POST['department_id'] ?? 0);
        $programId = (int)($_POST['program_id'] ?? 0);

        $subjectOk = $subjectId && one('SELECT id FROM mo_subjects WHERE id = ? AND is_active = 1', [$subjectId]);
        $depOk = $depId && one('SELECT id FROM mo_departments WHERE id = ? AND is_active = 1', [$depId]);
        $programOk = $programId && one('SELECT id FROM mo_programs WHERE id = ? AND is_active = 1', [$programId]);

        if (!$subjectOk || !$depOk || !$programOk) {
            flash('Изберете конкретна професия/специалност и валидно методическо обединение.', 'err');
        } else {
            $saveAssignment($subjectId, $programId, $depId);
            flash('МО за избраната професия/специалност е записано. Другите назначения на предмета са запазени.');
        }
    } elseif ($act === 'assignment_delete') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        $a = $aid ? one('SELECT subject_id, program_id FROM mo_subject_program_departments WHERE id = ?', [$aid]) : null;
        if ($a) {
            q('DELETE FROM mo_subject_program_departments WHERE id = ?', [$aid]);
            if ($a['program_id'] === null) {
                q('UPDATE mo_subjects SET department_id = NULL WHERE id = ?', [(int)$a['subject_id']]);
            }
            flash('Назначението е премахнато.');
        }
    } elseif ($act === 'fallback_save') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $depId = (int)($_POST['department_id'] ?? 0);
        $subjectOk = $subjectId && one('SELECT id FROM mo_subjects WHERE id = ? AND is_active = 1', [$subjectId]);
        $depOk = $depId && one('SELECT id FROM mo_departments WHERE id = ? AND is_active = 1', [$depId]);
        if (!$subjectOk || !$depOk) {
            flash('Невалиден предмет или методическо обединение.', 'err');
        } else {
            $saveAssignment($subjectId, null, $depId);
            flash('Резервното МО е записано. То ще се използва само за професии/специалности без конкретно правило.');
        }
    } elseif ($act === 'fallback_delete') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        q('DELETE FROM mo_subject_program_departments WHERE subject_id = ? AND program_id IS NULL', [$subjectId]);
        q('UPDATE mo_subjects SET department_id = NULL WHERE id = ?', [$subjectId]);
        flash('Резервното МО е премахнато. Конкретните назначения са запазени.');
    } elseif ($act === 'subject_common_bulk') {
        $dep = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['subjects'] ?? []))));
        if (!$ids) {
            flash('Изберете поне един предмет.', 'err');
        } elseif ($dep && !one('SELECT id FROM mo_departments WHERE id = ? AND is_active = 1', [$dep])) {
            flash('Избраното методическо обединение не е валидно.', 'err');
        } else {
            foreach ($ids as $sid) {
                if ($dep) {
                    $saveAssignment($sid, null, $dep);
                } else {
                    q('DELETE FROM mo_subject_program_departments WHERE subject_id = ? AND program_id IS NULL', [$sid]);
                    q('UPDATE mo_subjects SET department_id = NULL WHERE id = ?', [$sid]);
                }
            }
            flash($dep
                ? 'Общото правило е зададено за ' . count($ids) . ' предмета.'
                : 'Общото правило е премахнато за ' . count($ids) . ' предмета. Конкретните правила са запазени.');
        }
    }

    redirect(base_url('pages/admin_departments.php'));
}

$deps = all('SELECT d.*,
                    ' . user_name_sql('c') . ' AS chair_name,
                    ' . user_name_sql('z') . ' AS deputy_name,
                    (SELECT COUNT(DISTINCT a.subject_id)
                       FROM mo_subject_program_departments a
                      WHERE a.department_id = d.id AND a.is_active = 1) AS n_subjects,
                    (SELECT COUNT(*) FROM mo_entries e
                      WHERE e.department_id = d.id AND e.status = "sent") AS n_entries
             FROM mo_departments d
             LEFT JOIN users c ON c.id = d.chair_id
             LEFT JOIN users z ON z.id = d.deputy_id
             ORDER BY d.name');

$subjects = all('SELECT s.id, s.name, s.is_active,
                        (SELECT COUNT(*) FROM mo_competencies k
                          WHERE k.subject_id = s.id AND k.is_active = 1) AS n_comp,
                        (SELECT COUNT(*) FROM mo_subject_program_departments a
                          WHERE a.subject_id = s.id AND a.is_active = 1) AS n_assign
                 FROM mo_subjects s
                 WHERE s.is_active = 1
                 ORDER BY s.name');

$assignmentRows = all('SELECT a.id, a.subject_id, a.program_id, a.department_id,
                              p.name AS program_name, p.kind AS program_kind,
                              d.name AS department_name
                       FROM mo_subject_program_departments a
                       LEFT JOIN mo_programs p ON p.id = a.program_id
                       JOIN mo_departments d ON d.id = a.department_id
                       WHERE a.is_active = 1
                       ORDER BY a.subject_id, (a.program_id IS NULL) DESC, p.kind, p.name');
$assignmentsBySubject = [];
foreach ($assignmentRows as $a) $assignmentsBySubject[(int)$a['subject_id']][] = $a;

$programs = all('SELECT p.id, p.name, p.kind,
                        (SELECT COUNT(*) FROM mo_classes c WHERE c.program_id = p.id AND c.is_active = 1) AS n_classes
                 FROM mo_programs p
                 WHERE p.is_active = 1
                 ORDER BY p.kind, p.name');

$people = all('SELECT id, ' . user_name_sql('u') . ' AS name, email FROM users u
               ORDER BY name LIMIT 400');
$orphans = array_values(array_filter($subjects, static fn($s) => (int)$s['n_assign'] === 0));

header_html('Методически обединения', 'adm_dep');
section_title('Методически обединения и предмети');
?>

<?php if ($orphans): ?>
<div class="flash warn">
  <strong><?= count($orphans) ?> предмета нямат зададено МО за нито една професия/специалност:</strong>
  <?= e(implode(', ', array_column($orphans, 'name'))) ?>.
  Те няма да се предлагат при създаване на анализ, докато не им зададете правило.
</div>
<?php endif; ?>

<div class="panel">
  <h2>Ново методическо обединение</h2>
  <form method="post" class="picker" data-ajax-admin data-refresh="departments">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="dep_add">
    <label>Наименование<input type="text" name="name" placeholder="МО „Природни науки“" required size="32"></label>
    <button class="btn primary" type="submit">Добави</button>
  </form>
</div>

<div class="panel" id="departmentsPanel" data-ajax-section="departments">
  <h2>Ръководство на МО</h2>
  <p class="muted small">Анализът се записва към МО, определено от комбинацията
     <strong>предмет + професия/специалност на паралелката</strong>. Председателят и заместникът
     на съответното МО виждат изпратения анализ. Старите изпратени анализи не се местят при
     последваща промяна на правилата.</p>

  <table class="grid">
    <thead><tr><th>Методическо обединение</th><th>Председател</th><th>Заместник</th>
               <th>Предмети</th><th>Анализи</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($deps as $d): ?>
      <tr class="<?= $d['is_active'] ? '' : 'off' ?>">
        <td>
          <form method="post" data-ajax-admin data-refresh="departments" class="rowform" style="display:inline-flex; gap:.3rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_rename">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input type="text" name="name" value="<?= e($d['name']) ?>" size="26" style="width:auto">
            <button class="btn small ghost" type="submit">Преименувай</button>
          </form>
        </td>
        <td colspan="2">
          <form method="post" data-ajax-admin data-refresh="departments" style="display:flex; gap:.3rem; flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_heads">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <select name="chair_id" style="width:auto; padding:.2rem .5rem">
              <option value="">– без председател –</option>
              <?php foreach ($people as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$d['chair_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name'] ?: $p['email']) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="deputy_id" style="width:auto; padding:.2rem .5rem">
              <option value="">– без заместник –</option>
              <?php foreach ($people as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$d['deputy_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name'] ?: $p['email']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn small primary" type="submit">Запиши</button>
          </form>
          <?php if (!$d['chair_id'] && !$d['deputy_id']): ?>
            <span class="small danger">Няма ръководство – анализите ще стоят без получател.</span>
          <?php endif; ?>
        </td>
        <td><?= (int)$d['n_subjects'] ?></td>
        <td><?= (int)$d['n_entries'] ?></td>
        <td class="acts">
          <form method="post" data-ajax-admin data-refresh="departments" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_toggle">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn small ghost" type="submit"><?= $d['is_active'] ? 'Скрий' : 'Върни' ?></button>
          </form>
          <form method="post" data-ajax-admin data-refresh="departments" style="display:inline" data-danger
                data-confirm-title="Изтриване на МО" data-confirm-ok="Изтрий"
                data-confirm="МО може да бъде изтрито само ако няма активни назначения на предмети към него.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dep_delete">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn small danger" type="submit">Изтрий</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$deps): ?><tr><td colspan="6" class="muted">Още няма създадени МО.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="panel" id="subjectsPanel" data-ajax-section="subjects">
  <h2>Предмети по професии/специалности и методически обединения</h2>
  <p class="muted small">
    <strong>„Всички професии/специалности“</strong> е общо правило. Ако за същия предмет добавите
    конкретна професия/специалност, конкретното правило има приоритет. Ако искате предметът да се
    изучава само в 2–3 програми, премахнете общото правило и добавете само тези програми.
  </p>

  <form method="post" data-ajax-admin data-refresh="subjects" class="picker">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="subject_add">
    <label>Нов предмет<input type="text" name="name" required size="32"></label>
    <button class="btn primary" type="submit">Добави предмет</button>
  </form>

  <div class="flash warn" style="margin-top:.7rem">
    <strong>Важно:</strong> конкретно МО се задава винаги за избрана професия/специалност.
    Така един и същ предмет може едновременно да бъде в различни МО.
    „Резервно МО“ е отделна настройка и не трябва да се използва вместо конкретните назначения.
  </div>

  <table class="grid">
    <thead><tr>
      <th style="width:22%">Предмет</th>
      <th>Конкретни назначения: професия/специалност → МО</th>
      <th style="width:31%">Добави / промени конкретно назначение</th>
      <th style="width:18%">Резервно МО</th>
      <th>Компетентности</th>
    </tr></thead>
    <tbody>
    <?php foreach ($subjects as $s):
        $sid = (int)$s['id'];
        $rows = $assignmentsBySubject[$sid] ?? [];
        $fallback = null;
        $specificRows = [];
        foreach ($rows as $a) {
            if ($a['program_id'] === null) $fallback = $a;
            else $specificRows[] = $a;
        } ?>
      <tr class="<?= !$rows ? 'off' : '' ?>">
        <td>
          <strong><?= e($s['name']) ?></strong>
          <?php if (!$rows): ?><div class="small danger">Няма зададено МО</div><?php endif; ?>
        </td>
        <td>
          <?php if (!$specificRows): ?>
            <span class="muted small">Няма конкретни назначения.</span>
          <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:.45rem">
            <?php foreach ($specificRows as $a): ?>
              <div style="display:flex; gap:.35rem; align-items:center; flex-wrap:wrap">
                <span class="small" style="min-width:210px">
                  <strong><?= e($a['program_name']) . ' (' . ($a['program_kind'] === 'profession' ? 'професия' : 'специалност') . ')' ?></strong>
                  →
                </span>
                <form method="post" data-ajax-admin data-refresh="subjects" style="display:inline-flex; gap:.3rem; align-items:center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="assignment_save">
                  <input type="hidden" name="subject_id" value="<?= $sid ?>">
                  <input type="hidden" name="program_id" value="<?= $a['program_id'] !== null ? (int)$a['program_id'] : '' ?>">
                  <select name="department_id" data-autosave style="width:auto; padding:.2rem .5rem">
                    <?php foreach ($deps as $d): if (!$d['is_active'] && (int)$d['id'] !== (int)$a['department_id']) continue; ?>
                      <option value="<?= (int)$d['id'] ?>" <?= (int)$a['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <span class="small muted ajax-save-state" aria-live="polite"></span>
                </form>
                <form method="post" data-ajax-admin data-refresh="subjects" style="display:inline" data-danger
                      data-confirm="Това назначение ще бъде премахнато. Старите изпратени анализи няма да бъдат променени.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="assignment_delete">
                  <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                  <button class="btn small danger" type="submit">✕</button>
                </form>
              </div>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <form method="post" data-ajax-admin data-refresh="subjects" style="display:flex; gap:.35rem; align-items:end; flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assignment_save">
            <input type="hidden" name="subject_id" value="<?= $sid ?>">
            <label class="small" style="margin:0">Обхват
              <select name="program_id" required style="min-width:220px; padding:.3rem .5rem">
                <option value="">– избери професия/специалност –</option>
                <?php foreach ($programs as $p): ?>
                  <option value="<?= (int)$p['id'] ?>">
                    <?= e($p['name']) ?> · <?= $p['kind'] === 'profession' ? 'професия' : 'специалност' ?>
                    <?= (int)$p['n_classes'] ? ' · ' . (int)$p['n_classes'] . ' кл.' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="small" style="margin:0">МО
              <select name="department_id" required style="min-width:180px; padding:.3rem .5rem">
                <option value="">– избери –</option>
                <?php foreach ($deps as $d): if (!$d['is_active']) continue; ?>
                  <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button class="btn small primary" type="submit">Добави / замени</button>
          </form>
        </td>
        <td>
          <?php if ($fallback): ?>
            <div class="small" style="margin-bottom:.35rem"><strong><?= e($fallback['department_name']) ?></strong></div>
            <form method="post" data-ajax-admin data-refresh="subjects" style="display:inline" data-danger
                  data-confirm="Ще се премахне само резервното МО. Конкретните назначения няма да бъдат променени.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="fallback_delete">
              <input type="hidden" name="subject_id" value="<?= $sid ?>">
              <button class="btn small danger" type="submit">Премахни резервното</button>
            </form>
          <?php else: ?>
            <span class="muted small">Няма</span>
          <?php endif; ?>
          <details style="margin-top:.45rem">
            <summary class="small">Промени резервното</summary>
            <form method="post" data-ajax-admin data-refresh="subjects" style="margin-top:.35rem">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="fallback_save">
              <input type="hidden" name="subject_id" value="<?= $sid ?>">
              <select name="department_id" required data-autosave style="max-width:210px">
                <option value="">– избери МО –</option>
                <?php foreach ($deps as $d): if (!$d['is_active']) continue; ?>
                  <option value="<?= (int)$d['id'] ?>" <?= $fallback && (int)$fallback['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="small muted ajax-save-state" aria-live="polite"></span>
            </form>
          </details>
        </td>
        <td><?= (int)$s['n_comp'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php footer_html(); ?>
