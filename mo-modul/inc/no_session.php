<?php
/** Показва се, когато модулът не разпознае влязъл потребител от ВИС. */
$diag = (DEV_MODE || (isset($_GET['debug']) && $_GET['debug'] === '1'))
      ? LaravelAuth::diagnose() : null;
?><!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Нужно е влизане · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= asset_url('style.css') ?>">
</head>
<body class="plain">
<div class="nosession">
  <div class="panel">
    <h1>Влезте през системата на училището</h1>
    <p>Модулът „<?= e(APP_NAME) ?>“ е част от вътрешната информационна система и няма
       собствена страница за вход. Отворете ВИС, влезте с обичайните си данни и се
       върнете тук от менюто.</p>
    <p><a class="btn primary" href="<?= e(VIS_URL) ?>">Към вътрешната система</a></p>
    <p class="muted small">Ако вече сте влезли и виждате това съобщение, най-честата причина е,
       че модулът е на друг домейн или поддомейн и бисквитката на сесията не стига до него.
       Обърнете се към администратора.</p>

    <?php if ($diag): ?>
      <h2>Диагностика</h2>
      <table class="diag">
        <?php foreach ($diag as $k => $v): ?>
          <tr><td><?= e((string)$k) ?></td><td><?= e((string)$v) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
