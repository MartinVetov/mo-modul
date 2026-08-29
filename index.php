<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_user();
if (isset($_GET['year'])) $_SESSION['year_id'] = (int)$_GET['year'];
$yid  = current_year_id();
$term = term_code($_GET['term'] ?? 'I');

$btns = [];
if (has_role('teacher'))   $btns[] = ['url' => base_url('pages/entry.php'), 'label' => 'Въвеждане на анализ', 'sub' => 'по компетентности, по паралелки'];
if (has_role('methodist')) $btns[] = ['url' => base_url('pages/methodist_inbox.php'), 'label' => 'Получени анализи', 'sub' => 'от учителите към мен'];
if (has_role('deputy'))    $btns[] = ['url' => base_url('pages/deputy_inbox.php'), 'label' => 'Обобщения от МО', 'sub' => 'изпратени от методистите'];
if (has_role('admin'))     $btns[] = ['url' => base_url('pages/admin_competencies.php'), 'label' => 'Компетентности', 'sub' => 'зареждане от Excel'];

$stats = [];
if (has_role('teacher') && $yid) {
    $s = one('SELECT COUNT(*) n, SUM(status="sent") sent FROM mo_entries WHERE user_id=? AND year_id=? AND term=?',
             [$u['id'], $yid, $term]);
    $stats[] = ['k' => (int)($s['sent'] ?? 0) . '/' . (int)($s['n'] ?? 0), 'l' => 'изпратени от въведените'];
}
if (has_role('methodist') && $yid) {
    $s = one('SELECT COUNT(*) n, COUNT(DISTINCT user_id) t FROM mo_entries
              WHERE methodist_id=? AND status="sent" AND year_id=? AND term=?', [$u['id'], $yid, $term]);
    $stats[] = ['k' => (int)($s['n'] ?? 0), 'l' => 'получени анализа'];
    $stats[] = ['k' => (int)($s['t'] ?? 0), 'l' => 'учители са изпратили'];
}
if (has_role('deputy') && $yid) {
    $s = one('SELECT COUNT(*) n FROM mo_summaries WHERE status="sent" AND year_id=? AND term=?', [$yid, $term]);
    $stats[] = ['k' => (int)($s['n'] ?? 0), 'l' => 'получени обобщения'];
}
if (has_role('admin')) {
    $s = one('SELECT COUNT(*) n FROM mo_competencies WHERE is_active=1');
    $stats[] = ['k' => (int)($s['n'] ?? 0), 'l' => 'компетентности в базата'];
}

header_html('Начало', 'home');
if ($btns) top_buttons($btns);
section_title('Здравейте, ' . $u['display_name']);
year_picker($term);
?>

<div class="cards">
  <?php foreach ($stats as $s): ?>
    <div class="stat"><span class="k"><?= e((string)$s['k']) ?></span><span class="l"><?= e($s['l']) ?></span></div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <h2>Как работи модулът</h2>
  <ol class="small">
    <li><strong>Учителят</strong> избира паралелка и предмет, отмята компетентностите и пише бележки.
        Неотбелязаните компетентности автоматично се смятат за прехвърлени към II срок.</li>
    <li><strong>Методистът</strong> получава изпратените анализи, вижда обобщените числа и съставя
        обобщение – ръчно или с AI чернова.</li>
    <li><strong>Зам-директорът</strong> получава готовите обобщения по МО.</li>
  </ol>
  <p class="muted small">Правата ви в модула: <?= e(roles_bg($u['roles'])) ?>.
     Ако нещо липсва, администрацията го задава от „Роли и методисти“.</p>
</div>
<?php footer_html(); ?>
