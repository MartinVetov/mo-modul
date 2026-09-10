<?php
/**
 * Потребители, роли и ръководство на методическите обединения
 *
 * Включва се от inc/bootstrap.php – не се require-ва пряко.
 */

/* ------------------------------------------------------------------ */
/* Потребител и роли                                                   */
/* ------------------------------------------------------------------ */

/** Влезлият потребител според сесията на ВИС, или null. */
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;

    $id = DEV_MODE && DEV_USER_ID ? (int)DEV_USER_ID : LaravelAuth::userId();
    if (!$id) return $u = null;

    $row = one('SELECT id, name, surname, last_name, email, role FROM users WHERE id = ?', [$id]);
    if (!$row) return $u = null;

    $row['display_name'] = trim(($row['name'] ?? '') . ' ' . ($row['last_name'] ?: ($row['surname'] ?? '')));
    if ($row['display_name'] === '') $row['display_name'] = $row['email'];

    // роли от модула + подразбиращата се роля от ВИС
    $roles = array_column(all('SELECT role FROM mo_user_roles WHERE user_id = ?', [$id]), 'role');
    if (in_array(strtolower((string)$row['role']), ['teacher', 'user'], true) || !$roles) $roles[] = 'teacher';
    $row['roles'] = array_values(array_unique($roles));

    return $u = $row;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        http_response_code(401);
        require __DIR__ . '/no_session.php';
        exit;
    }
    return $u;
}

function has_role(string $role, ?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && in_array($role, $u['roles'], true);
}

function require_role(string ...$roles): array
{
    $u = require_user();
    foreach ($roles as $r) if (has_role($r, $u)) return $u;
    http_response_code(403);
    die('<p style="font-family:sans-serif">Нямате права за тази страница. Обърнете се към администратора на системата.</p>');
}

function role_bg(string $r): string
{
    return ['teacher' => 'учител', 'methodist' => 'методист',
            'deputy' => 'зам-директор', 'admin' => 'администратор'][$r] ?? $r;
}

function roles_bg(array $roles): string
{
    return implode(', ', array_map('role_bg', $roles));
}

function user_name_sql(string $alias = 'u'): string
{
    return "TRIM(CONCAT(COALESCE($alias.name,''),' ',COALESCE($alias.last_name,$alias.surname,'')))";
}

/** Единственото общообразователно МО на предмета, ако е зададено еднозначно. */
/**
 * Синхронизира производната роля "methodist" с ръководството на МО.
 * Ролята не се назначава ръчно: председателите и заместниците я получават
 * автоматично, а при освобождаване се премахва, ако човекът не ръководи друго МО.
 */
function sync_methodist_roles(?int $assignedBy = null): void
{
    q('DELETE r FROM mo_user_roles r
       LEFT JOIN mo_departments d
         ON d.is_active = 1 AND (d.chair_id = r.user_id OR d.deputy_id = r.user_id)
       WHERE r.role = "methodist" AND d.id IS NULL');

    q('INSERT IGNORE INTO mo_user_roles (user_id, role, assigned_by)
       SELECT x.user_id, "methodist", ?
       FROM (
           SELECT chair_id AS user_id FROM mo_departments WHERE is_active=1 AND chair_id IS NOT NULL
           UNION
           SELECT deputy_id AS user_id FROM mo_departments WHERE is_active=1 AND deputy_id IS NOT NULL
       ) x', [$assignedBy]);
}

/** МО-тата, в които потребителят е председател или заместник. */
function my_departments(?array $u = null): array
{
    $u = $u ?? current_user();
    if (!$u) return [];
    static $cache = [];
    $id = (int)$u['id'];
    if (!isset($cache[$id])) {
        // председателството се подрежда преди заместничеството, за да
        // отваря страницата първо МО-то, за което човекът отговаря
        $cache[$id] = all('SELECT * FROM mo_departments
                           WHERE is_active = 1 AND (chair_id = ? OR deputy_id = ?)
                           ORDER BY (chair_id = ?) DESC, name', [$id, $id, $id]);
    }
    return $cache[$id];
}

/** Ръководи ли потребителят поне едно МО? */
function leads_department(?array $u = null): bool
{
    return (bool)my_departments($u);
}

/** Каква е ролята му в дадено МО: председател, заместник или нищо. */
function department_role(int $depId, ?array $u = null): ?string
{
    $u = $u ?? current_user();
    if (!$u) return null;
    $d = one('SELECT chair_id, deputy_id FROM mo_departments WHERE id = ?', [$depId]);
    if (!$d) return null;
    if ((int)$d['chair_id']  === (int)$u['id']) return 'chair';
    if ((int)$d['deputy_id'] === (int)$u['id']) return 'deputy';
    return null;
}

function department_role_bg(?string $r): string
{
    return ['chair' => 'председател на МО', 'deputy' => 'зам.-председател на МО'][$r] ?? '';
}
