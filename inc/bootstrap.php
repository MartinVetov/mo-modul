<?php
/**
 * Стартов файл на модула – включва се в началото на всяка страница.
 * Модулът няма собствен вход: потребителят идва от сесията на ВИС.
 */
declare(strict_types=1);

if (!file_exists(__DIR__ . '/../config.php')) {
    die('<p style="font-family:sans-serif">Липсва <code>config.php</code>. Копирайте '
      . '<code>config.sample.php</code> като <code>config.php</code> и попълнете настройките.</p>');
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/laravel_auth.php';

date_default_timezone_set(APP_TZ);
mb_internal_encoding('UTF-8');
ini_set('display_errors', DEV_MODE ? '1' : '0');
error_reporting(E_ALL);

// Собствена сесия само за CSRF и съобщения – НЕ пипа сесията на Laravel
session_name('MOMODUL');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/core.php';      // база, сесия, помощни
require_once __DIR__ . '/auth.php';      // потребител, роли, МО ръководство
require_once __DIR__ . '/domain.php';    // предмети, компетентности, срокове
require_once __DIR__ . '/routing.php';   // кое МО получава анализа
