<?php
/**
 * Разпознаване на влезлия потребител през сесията на ВИС (Laravel).
 * Модулът НЯМА собствен вход. Реда на проверките:
 *
 *   1. бисквитката със сесията  -> дешифрира се с APP_KEY -> файл в
 *      storage/framework/sessions/<id> -> ключът login_web_<sha1>
 *   2. ако липсва: бисквитката „запомни ме“ -> id|token|... ->
 *      сверява се с users.remember_token
 *
 * Нищо не се записва в сесията на Laravel – модулът само чете.
 */
final class LaravelAuth
{
    /** Ключът, под който Laravel пази id-то на влезлия потребител. */
    private const GUARD_KEY = 'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d';
    private const REMEMBER_COOKIE = 'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d';

    private static ?array $env = null;

    /** @return int|null id на потребителя от `users` или null */
    public static function userId(): ?int
    {
        $id = self::fromSession();
        if ($id !== null) return $id;
        return self::fromRemember();
    }

    /** Диагностика за страницата за проверка на връзката с ВИС. */
    public static function diagnose(): array
    {
        $d = [
            'env_файл'           => self::envPath() ?: 'не е намерен',
            'APP_KEY'            => self::key() ? 'намерен' : 'ЛИПСВА',
            'папка със сесии'    => self::sessionDir(),
            'сесийна бисквитка'  => self::sessionCookieName(),
            'бисквитката е тук'  => isset($_COOKIE[self::sessionCookieName()]) ? 'да' : 'не',
            'remember бисквитка' => isset($_COOKIE[self::REMEMBER_COOKIE]) ? 'да' : 'не',
        ];
        $sid = self::sessionId();
        $d['id на сесията'] = $sid ? substr($sid, 0, 12) . '…' : 'не е разчетено';
        $d['файл на сесията'] = ($sid && is_file(self::sessionDir() . '/' . $sid)) ? 'намерен' : 'липсва';
        $d['разпознат user_id'] = self::userId() ?? 'няма';
        return $d;
    }

    /* ---------------------------------------------------------------- */
    /* Сесия                                                            */
    /* ---------------------------------------------------------------- */

    private static function fromSession(): ?int
    {
        $sid = self::sessionId();
        if (!$sid) return null;

        $file = self::sessionDir() . '/' . $sid;
        if (!is_file($file) || !is_readable($file)) return null;

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return null;

        // Laravel пази сесията като serialize() на масив
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data)) {
            // при SESSION_ENCRYPT=true съдържанието е шифровано
            $dec = self::decrypt($raw);
            $data = $dec !== null ? @unserialize($dec, ['allowed_classes' => false]) : null;
        }
        if (!is_array($data)) return null;

        $id = $data[self::GUARD_KEY] ?? null;
        return is_numeric($id) ? (int)$id : null;
    }

    /** id-то на сесията от бисквитката (дешифрирано). */
    private static function sessionId(): ?string
    {
        $name = self::sessionCookieName();
        $c = $_COOKIE[$name] ?? null;
        if (!$c) return null;

        $val = self::decryptCookie($c, $name);
        if ($val === null) return null;

        return preg_match('/^[A-Za-z0-9]{20,64}$/', $val) ? $val : null;
    }

    /* ---------------------------------------------------------------- */
    /* „Запомни ме“                                                     */
    /* ---------------------------------------------------------------- */

    private static function fromRemember(): ?int
    {
        $c = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!$c) return null;

        $val = self::decryptCookie($c, self::REMEMBER_COOKIE);
        if ($val === null) return null;

        // формат: id|remember_token|част от хеша на паролата
        $parts = explode('|', $val);
        if (count($parts) < 2) return null;

        $id    = (int)$parts[0];
        $token = (string)$parts[1];
        if ($id <= 0 || $token === '') return null;

        $u = one('SELECT id, remember_token FROM users WHERE id = ?', [$id]);
        if (!$u || !$u['remember_token']) return null;

        return hash_equals((string)$u['remember_token'], $token) ? $id : null;
    }

    /* ---------------------------------------------------------------- */
    /* Дешифриране (същият алгоритъм като Illuminate\Encryption)        */
    /* ---------------------------------------------------------------- */

    /** Маха и представката, която Laravel слага пред стойността на бисквитката. */
    private static function decryptCookie(string $cookie, string $name): ?string
    {
        $plain = self::decrypt($cookie);
        if ($plain === null) return null;

        $key = self::key();
        if ($key === null) return null;

        $prefix = hash_hmac('sha1', $name . 'v2', $key) . '|';
        if (str_starts_with($plain, $prefix)) {
            return substr($plain, strlen($prefix));
        }
        // по-стари версии на Laravel не слагат представка
        return str_contains($plain, '|') && strlen($plain) > 41 ? $plain : $plain;
    }

    private static function decrypt(string $payload): ?string
    {
        $key = self::key();
        if ($key === null) return null;

        $json = json_decode(base64_decode($payload, true) ?: '', true);
        if (!is_array($json) || !isset($json['iv'], $json['value'], $json['mac'])) return null;

        $iv = base64_decode($json['iv'], true);
        if ($iv === false) return null;

        // проверка на подписа – пази от подправена бисквитка
        $calc = hash_hmac('sha256', $json['iv'] . $json['value'], $key);
        if (!hash_equals($calc, (string)$json['mac'])) return null;

        $cipher = strlen($key) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';
        $out = openssl_decrypt($json['value'], $cipher, $key, 0, $iv);
        if ($out === false) return null;

        // при serialize=true стойността е сериализирана
        $un = @unserialize($out, ['allowed_classes' => false]);
        return is_string($un) ? $un : $out;
    }

    /* ---------------------------------------------------------------- */
    /* Настройки от .env на ВИС                                          */
    /* ---------------------------------------------------------------- */

    private static function envPath(): ?string
    {
        $p = rtrim(LARAVEL_PATH, '/\\') . '/.env';
        return is_file($p) ? $p : null;
    }

    /** Чете .env на Laravel приложението (само нужните ключове). */
    private static function env(string $key, ?string $default = null): ?string
    {
        if (self::$env === null) {
            self::$env = [];
            $p = self::envPath();
            if ($p && is_readable($p)) {
                foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                    [$k, $v] = explode('=', $line, 2);
                    self::$env[trim($k)] = trim(trim($v), "\"'");
                }
            }
        }
        return self::$env[$key] ?? $default;
    }

    private static function key(): ?string
    {
        $k = defined('LARAVEL_APP_KEY') && LARAVEL_APP_KEY !== '' ? LARAVEL_APP_KEY : self::env('APP_KEY');
        if (!$k) return null;
        if (str_starts_with($k, 'base64:')) {
            $d = base64_decode(substr($k, 7), true);
            return $d === false ? null : $d;
        }
        return $k;
    }

    private static function sessionCookieName(): string
    {
        if (defined('LARAVEL_SESSION_COOKIE') && LARAVEL_SESSION_COOKIE !== '') return LARAVEL_SESSION_COOKIE;
        $n = self::env('SESSION_COOKIE');
        if ($n) return $n;
        // по подразбиране Laravel прави име от APP_NAME
        $app = self::env('APP_NAME', 'laravel');
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', (string)$app) ?? 'laravel');
        return trim($slug, '_') . '_session';
    }

    private static function sessionDir(): string
    {
        if (defined('LARAVEL_SESSION_PATH') && LARAVEL_SESSION_PATH !== '') return rtrim(LARAVEL_SESSION_PATH, '/\\');
        return rtrim(LARAVEL_PATH, '/\\') . '/storage/framework/sessions';
    }
}
