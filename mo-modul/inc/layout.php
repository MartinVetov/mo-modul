<?php
/** Общ изглед на модула, съобразен с интерфейса на ВИС. */

function nav_items(array $u): array
{
    $n = [['url' => base_url('index.php'), 'icon' => '⌂', 'label' => 'Начало', 'key' => 'home']];

    if (has_role('teacher', $u)) {
        $n[] = ['url' => base_url('pages/entry.php'),     'icon' => '✎', 'label' => 'Въвеждане на анализ', 'key' => 'entry'];
        $n[] = ['url' => base_url('pages/my_entries.php'), 'icon' => '≡', 'label' => 'Моите анализи',       'key' => 'mine'];
    }
    if (leads_department($u) || has_role('admin', $u)) {
        $n[] = ['url' => base_url('pages/methodist_inbox.php'), 'icon' => '⇩', 'label' => 'Получени анализи', 'key' => 'inbox'];
        $n[] = ['url' => base_url('pages/methodist_summary.php'), 'icon' => '▤', 'label' => 'Обобщение',      'key' => 'sum'];
        $n[] = ['url' => base_url('pages/methodist_docs.php'),    'icon' => '📄', 'label' => 'Моите документи', 'key' => 'docs'];
    }
    if (has_role('deputy', $u)) {
        $n[] = ['url' => base_url('pages/deputy_inbox.php'), 'icon' => '★', 'label' => 'Обобщения от МО', 'key' => 'deputy'];
    }
    if (has_role('admin', $u)) {
        $n[] = ['url' => base_url('pages/admin_departments.php'), 'icon' => '🏛', 'label' => 'Методически обединения', 'key' => 'adm_dep'];
        $n[] = ['url' => base_url('pages/admin_competencies.php'), 'icon' => '☑', 'label' => 'Компетентности', 'key' => 'adm_comp'];
        $n[] = ['url' => base_url('pages/admin_roles.php'),        'icon' => '👥', 'label' => 'Роли и методисти', 'key' => 'adm_roles'];
        $n[] = ['url' => base_url('pages/admin_setup.php'),        'icon' => '⚙', 'label' => 'Години и паралелки', 'key' => 'adm_setup'];
    }
    return $n;
}

function header_html(string $title, string $active = ''): void
{
    $u = current_user();
    $f = flash();
    $y = current_year_id() ? one('SELECT * FROM mo_years WHERE id = ?', [current_year_id()]) : null;
    ?><!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= asset_url('style.css') ?>">
</head>
<body>
<header class="vis-top no-print">
  <button class="burger" type="button" aria-label="Меню" onclick="document.body.classList.toggle('nav-open')">☰</button>
  <div class="vis-title"><span class="ico">🏫</span> <?= e(SCHOOL_NAME) ?></div>
  <div class="vis-user">
    <span class="uname"><?= e($u['display_name'] ?? '') ?></span>
    <span class="avatar" title="<?= e(roles_bg($u['roles'] ?? [])) ?>">
      <?= e(mb_substr($u['display_name'] ?? '?', 0, 1)) ?>
    </span>
  </div>
</header>

<div class="vis-body">
  <aside class="vis-side no-print">
    <div class="side-head">
      <div class="mark">МО</div>
      <div>
        <strong><?= e(APP_NAME) ?></strong>
        <small>модул към ВИС</small>
      </div>
    </div>
    <nav>
      <?php foreach (nav_items($u ?? ['roles' => []]) as $it): ?>
        <a class="<?= $active === $it['key'] ? 'on' : '' ?>" href="<?= e($it['url']) ?>">
          <span class="i"><?= $it['icon'] ?></span><?= e($it['label']) ?>
        </a>
      <?php endforeach; ?>
      <a class="back" href="<?= e(VIS_URL) ?>"><span class="i">↩</span> Към ВИС</a>
    </nav>
    <div class="side-foot">
      <?= $y ? 'Учебна година: <strong>' . e($y['label']) . '</strong>' : 'Няма учебна година' ?><br>
      <?= e(roles_bg($u['roles'] ?? [])) ?>
    </div>
  </aside>

  <main>
  <?php if ($f): ?>
    <div class="flash <?= e($f['type']) ?> no-print"><?= $f['msg'] ?></div>
  <?php endif; ?>
<?php
}

function footer_html(): void
{
    ?>
  </main>
</div>
<script src="<?= asset_url('app.js') ?>"></script>
</body>
</html>
<?php
}

/** Заглавие на секция в стила на ВИС: син текст с линия отдолу. */
function section_title(string $text, string $right = ''): void
{
    echo '<div class="sec-title"><h1>' . e($text) . '</h1>'
       . ($right !== '' ? '<div class="right">' . $right . '</div>' : '') . '</div>';
}

/** Лентата с бутони най-горе, както в началния екран на ВИС. */
function top_buttons(array $buttons): void
{
    echo '<div class="topbtns no-print">';
    foreach ($buttons as $b) {
        $cls = $b['muted'] ?? false ? 'topbtn muted' : 'topbtn';
        echo '<a class="' . $cls . '" href="' . e($b['url']) . '">' . e($b['label'])
           . (isset($b['sub']) ? '<small>' . e($b['sub']) . '</small>' : '') . '</a>';
    }
    echo '</div>';
}

function year_picker(string $term, array $hidden = []): void
{
    $years = all('SELECT * FROM mo_years ORDER BY label DESC');
    ?>
  <form class="picker no-print" method="get">
    <?php foreach ($hidden as $k => $v): ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
    <?php endforeach; ?>
    <label>Учебна година
      <select name="year" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y['id'] ?>" <?= current_year_id() === (int)$y['id'] ? 'selected' : '' ?>><?= e($y['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Срок
      <select name="term" onchange="this.form.submit()">
        <?php foreach (TERMS as $k => $lab): ?>
          <option value="<?= e($k) ?>" <?= $term === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
    <?php
}
