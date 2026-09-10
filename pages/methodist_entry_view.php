<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';

$u = require_user();
$id = (int)($_GET['id'] ?? 0);
$depFromQuery = (int)($_GET['dep'] ?? 0);
$termFromQuery = term_code($_GET['term'] ?? 'I');

if ($id < 1) {
    http_response_code(404);
    die('<p style="font-family:sans-serif">Анализът не е намерен.</p>');
}

$entry = one(
    'SELECT e.*,
            s.name AS subject_name,
            c.name AS class_name,
            c.grade_level,
            c.program_id,
            p.name AS program_name,
            p.kind AS program_kind,
            y.label AS year_label,
            d.name AS department_name,
            ' . user_name_sql('t') . ' AS teacher_name,
            t.email AS teacher_email,
            ' . user_name_sql('m') . ' AS methodist_name,
            m.email AS methodist_email
     FROM mo_entries e
     JOIN mo_subjects s ON s.id=e.subject_id
     JOIN mo_classes c ON c.id=e.class_id
     LEFT JOIN mo_programs p ON p.id=c.program_id
     JOIN mo_years y ON y.id=e.year_id
     LEFT JOIN mo_departments d ON d.id=e.department_id
     JOIN users t ON t.id=e.user_id
     LEFT JOIN users m ON m.id=e.methodist_id
     WHERE e.id=?
     LIMIT 1',
    [$id]
);

if (!$entry) {
    http_response_code(404);
    die('<p style="font-family:sans-serif">Анализът не е намерен.</p>');
}

$depId = (int)($entry['department_id'] ?? 0);
$allowed = has_role('admin', $u) || ($depId > 0 && department_role($depId, $u) !== null);
if (!$allowed) {
    http_response_code(403);
    die('<p style="font-family:sans-serif">Нямате права да преглеждате този анализ.</p>');
}

/* Методистът преглежда само реално подадени към МО анализи. */
if ((string)$entry['status'] !== 'sent') {
    http_response_code(409);
    die('<p style="font-family:sans-serif">Анализът вече не е в получените анализи. Възможно е да е върнат на учителя за редакция.</p>');
}

$manualRows = all(
    'SELECT id,title,state,sort_order
     FROM mo_entry_manual_competencies
     WHERE entry_id=?
     ORDER BY sort_order,id',
    [$id]
);
$isRpp = subject_is_rpp((int)$entry['subject_id']) || !empty($manualRows);

$competencies = [];
if ($isRpp) {
    foreach ($manualRows as $r) {
        $competencies[] = [
            'code' => 'РПП',
            'title' => (string)$r['title'],
            'section' => '',
            'state' => $r['state'] !== null ? (string)$r['state'] : '',
        ];
    }
} else {
    $marks = [];
    foreach (all('SELECT competency_id,state FROM mo_entry_competencies WHERE entry_id=?', [$id]) as $r) {
        $marks[(int)$r['competency_id']] = (string)$r['state'];
    }

    $official = competencies_for_existing_entry($id, (int)$entry['subject_id'], (int)$entry['class_id']);
    foreach ($official as $r) {
        $cid = (int)$r['id'];
        $competencies[] = [
            'code' => (string)($r['code'] ?? ''),
            'title' => (string)($r['title'] ?? ''),
            'section' => (string)($r['section'] ?? ''),
            'state' => (string)($marks[$cid] ?? ''),
        ];
    }

    /* Ако официалната конфигурация е променена след подаването, пазим
       видими поне компетентностите, по които учителят реално е отбелязал
       състояние в анализа. */
    if (!$official && $marks) {
        $saved = all(
            'SELECT k.id,k.code,k.title,k.section,ec.state
             FROM mo_entry_competencies ec
             JOIN mo_competencies k ON k.id=ec.competency_id
             WHERE ec.entry_id=?
             ORDER BY ' . section_order_sql('k') . ',k.sort_order,k.id',
            [$id]
        );
        foreach ($saved as $r) {
            $competencies[] = [
                'code' => (string)($r['code'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'section' => (string)($r['section'] ?? ''),
                'state' => (string)($r['state'] ?? ''),
            ];
        }
    }
}

$counts = ['mastered'=>0, 'partial'=>0, 'not_mastered'=>0, 'unmarked'=>0];
$markedCompetencies = [];
foreach ($competencies as $c) {
    $st = (string)($c['state'] ?? '');
    if (isset($counts[$st])) {
        $counts[$st]++;
        $markedCompetencies[] = $c;
    } else {
        $counts['unmarked']++;
    }
}

$gradeTotal = (int)$entry['g2'] + (int)$entry['g3'] + (int)$entry['g4'] + (int)$entry['g5'] + (int)$entry['g6'];
$backDep = $depFromQuery > 0 ? $depFromQuery : $depId;
$backTerm = term_code((string)$entry['term'] ?: $termFromQuery);
$backUrl = base_url('pages/methodist_inbox.php?term=' . urlencode($backTerm) . '&dep=' . $backDep . '&year=' . (int)$entry['year_id']);

header_html('Преглед на анализ', 'inbox');
section_title(
    'Преглед на анализ',
    '<a class="btn small ghost" href="' . e($backUrl) . '">← Към получени анализи</a>'
);
?>

<div class="panel">
  <h2><?= e($entry['subject_name']) ?></h2>
  <div class="cards">
    <div class="stat"><span class="k"><?= e($entry['class_name']) ?></span><span class="l">паралелка · <?= e(GROUPS[(string)$entry['group_no']] ?? '') ?></span></div>
    <div class="stat"><span class="k"><?= e(term_label($entry['term'])) ?></span><span class="l"><?= e($entry['year_label']) ?></span></div>
    <div class="stat"><span class="k"><?= fmt_avg($entry['avg_grade']) ?></span><span class="l">среден успех</span></div>
    <div class="stat"><span class="k"><?= (int)$entry['students_count'] ?></span><span class="l">ученици</span></div>
  </div>
</div>

<div class="panel">
  <h2>Данни за анализа</h2>
  <table class="grid">
    <tbody>
      <tr><th style="width:190px">Учител</th><td><?= e($entry['teacher_name']) ?><?= $entry['teacher_email'] ? ' · ' . e($entry['teacher_email']) : '' ?></td></tr>
      <tr><th>Паралелка</th><td><strong><?= e($entry['class_name']) ?></strong> · <?= e(GROUPS[(string)$entry['group_no']] ?? '') ?><?= $entry['program_name'] ? ' · ' . e($entry['program_name']) : '' ?></td></tr>
      <tr><th>Предмет</th><td><?= e($entry['subject_name']) ?><?= $isRpp ? ' · <span class="badge">РПП</span>' : '' ?></td></tr>
      <tr><th>Методическо обединение</th><td><?= $entry['department_name'] ? e($entry['department_name']) : '<span class="danger">—</span>' ?></td></tr>
      <tr><th>Методист</th><td><?= $entry['methodist_name'] ? e($entry['methodist_name']) . ($entry['methodist_email'] ? ' · ' . e($entry['methodist_email']) : '') : '<span class="muted">—</span>' ?></td></tr>
      <tr><th>Изпратен</th><td><?= $entry['sent_at'] ? e(date('d.m.Y H:i', strtotime($entry['sent_at']))) : '—' ?></td></tr>
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>Компетентности</h2>
  <div class="legend">
    <span><i class="n"></i> неотбелязана</span>
    <span><i class="g"></i> усвоена ✓</span>
    <span><i class="y"></i> частично усвоена –</span>
    <span><i class="r"></i> неусвоена ✕</span>
  </div>

  <?php if (!$competencies): ?>
    <p class="muted">Няма записани компетентности към този анализ.</p>
  <?php else: ?>
    <div class="cards">
      <div class="stat"><span class="k"><?= (int)$counts['mastered'] ?></span><span class="l">усвоени</span></div>
      <div class="stat"><span class="k"><?= (int)$counts['partial'] ?></span><span class="l">частично</span></div>
      <div class="stat"><span class="k"><?= (int)$counts['not_mastered'] ?></span><span class="l">неусвоени</span></div>
      <div class="stat"><span class="k"><?= (int)$counts['unmarked'] ?></span><span class="l">неотбелязани</span></div>
    </div>

    <?php if (!$markedCompetencies): ?>
      <p class="muted">Няма отбелязани компетентности. Неотбелязаните са показани само като брой по-горе.</p>
    <?php else: ?>
      <table class="grid">
        <thead><tr><th style="width:130px">Код / раздел</th><th>Компетентност</th><th style="width:190px">Състояние</th></tr></thead>
        <tbody>
        <?php foreach ($markedCompetencies as $c):
            $st = (string)($c['state'] ?? '');
            $stateLabel = isset(STATES[$st]) ? STATES[$st] : '';
            $stateClass = $st === 'mastered' ? 'ok' : ($st === 'partial' ? 'warn' : 'red');
            $stateSymbol = $st === 'mastered' ? '✓' : ($st === 'partial' ? '–' : '✕');
        ?>
          <tr>
            <td class="small"><?php if ($c['code'] !== ''): ?><span class="badge"><?= e($c['code']) ?></span><?php endif; ?><?php if ($c['section'] !== ''): ?><br><span class="muted"><?= e($c['section']) ?></span><?php endif; ?></td>
            <td><?= e($c['title']) ?></td>
            <td><span class="badge <?= e($stateClass) ?>"><?= e($stateSymbol . ' ' . $stateLabel) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Количествен анализ</h2>
  <table class="grid">
    <thead><tr><th>Ученици</th><th>Слаб 2</th><th>Среден 3</th><th>Добър 4</th><th>Мн. добър 5</th><th>Отличен 6</th><th>Общо оценки</th><th>Среден успех</th></tr></thead>
    <tbody><tr>
      <td><strong><?= (int)$entry['students_count'] ?></strong></td>
      <td><?= (int)$entry['g2'] ?></td><td><?= (int)$entry['g3'] ?></td><td><?= (int)$entry['g4'] ?></td>
      <td><?= (int)$entry['g5'] ?></td><td><?= (int)$entry['g6'] ?></td>
      <td><?= $gradeTotal ?></td><td><strong><?= fmt_avg($entry['avg_grade']) ?></strong></td>
    </tr></tbody>
  </table>
</div>

<div class="panel">
  <h2>Мерки за подобряване качеството на обучение</h2>
  <p style="white-space:pre-wrap"><?= e((string)$entry['measures']) ?></p>
</div>

<div class="actions no-print">
  <a class="btn ghost" href="<?= e($backUrl) ?>">← Към получени анализи</a>
</div>

<?php footer_html(); ?>
