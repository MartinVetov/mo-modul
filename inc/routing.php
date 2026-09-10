<?php
/**
 * Маршрутизиране: кое МО получава анализа за предмет и паралелка
 *
 * Включва се от inc/bootstrap.php – не се require-ва пряко.
 */

/* --------------------------------------------------------------------
 * Методически обединения – автоматично маршрутизиране v5.
 *
 * Има две независими правила:
 *   1) професия/специалност -> отговорно МО  (mo_department_programs)
 *   2) общообразователен предмет -> едно МО (mo_subject_departments)
 *
 * Типът на предмета е глобален според компетентностите:
 * - общообразователните предмети използват mo_subject_departments;
 * - професионалните предмети използват професията/специалността на класа
 *   чрез mo_department_programs.
 *
 * „Чужд език по професията“ е изрично общообразователно изключение, но
 * компетентностите му могат да останат програмно-специфични.
 * ------------------------------------------------------------------ */

/** Пълни данни за активно МО, включително ръководството. */
function department_assignment_details(int $departmentId): ?array
{
    return one(
        'SELECT d.id AS department_id, d.name AS department_name,
                d.is_active AS department_active, d.chair_id, d.deputy_id,
                ' . user_name_sql('ch') . ' AS chair_name, ch.email AS chair_email,
                ' . user_name_sql('dp') . ' AS deputy_name, dp.email AS deputy_email
         FROM mo_departments d
         LEFT JOIN users ch ON ch.id = d.chair_id
         LEFT JOIN users dp ON dp.id = d.deputy_id
         WHERE d.id = ?
         LIMIT 1',
        [$departmentId]
    );
}

/** Отговорното МО за конкретна професия/специалност. */
function program_department_assignment(?int $programId): ?array
{
    if (!$programId) return null;
    $r = one(
        'SELECT dp.id, dp.program_id, dp.department_id, dp.is_active,
                d.name AS department_name, d.is_active AS department_active
         FROM mo_department_programs dp
         JOIN mo_departments d ON d.id = dp.department_id
         WHERE dp.program_id = ? AND dp.is_active = 1
           AND d.is_active = 1 AND d.department_type = \'professional\'
         LIMIT 1',
        [$programId]
    );
    if (!$r) return null;
    $details = department_assignment_details((int)$r['department_id']);
    return $details ? array_merge($r, $details) : $r;
}

/**
 * Единственото общообразователно МО на предмета.
 * mo_subject_departments вече се използва само за тази цел.
 */
function general_subject_department_assignment(int $subjectId): array
{
    $rows = all(
        'SELECT sd.id, sd.subject_id, sd.department_id, sd.is_active,
                d.name AS department_name, d.is_active AS department_active,
                d.chair_id, d.deputy_id,
                ' . user_name_sql('ch') . ' AS chair_name, ch.email AS chair_email,
                ' . user_name_sql('dp') . ' AS deputy_name, dp.email AS deputy_email
         FROM mo_subject_departments sd
         JOIN mo_departments d ON d.id = sd.department_id
         LEFT JOIN users ch ON ch.id = d.chair_id
         LEFT JOIN users dp ON dp.id = d.deputy_id
         WHERE sd.subject_id = ? AND sd.is_active = 1
           AND d.department_type = \'general\'
         ORDER BY d.name',
        [$subjectId]
    );

    if (!$rows) {
        return [
            'ok' => false,
            'code' => 'general_subject_department',
            'error' => 'За този общообразователен предмет няма зададено методическо обединение.',
        ];
    }
    if (count($rows) > 1) {
        return [
            'ok' => false,
            'code' => 'general_subject_multiple_departments',
            'error' => 'Общообразователният предмет е зададен към повече от едно МО. Оставете само едно.',
            'candidates' => $rows,
        ];
    }
    if (!(int)$rows[0]['department_active']) {
        return [
            'ok' => false,
            'code' => 'department_inactive',
            'error' => 'Методическото обединение „' . $rows[0]['department_name'] . '“ е скрито.',
            'candidates' => $rows,
        ];
    }
    return [
        'ok' => true,
        'assignment' => $rows[0],
        'resolution' => 'general_subject',
    ];
}

/**
 * Определя контекста на предмета за конкретната паралелка.
 * Типът се определя глобално от subject_training_type(), за да съвпада
 * точно с административната класификация на предметите.
 */
function subject_context_for_class(int $subjectId, int $classId): array
{
    $c = one('SELECT id, grade_level, program_id FROM mo_classes WHERE id=? AND is_active=1', [$classId]);
    if (!$c) return ['ok'=>false, 'code'=>'class', 'error'=>'Паралелката не съществува или е скрита.'];

    $programId = $c['program_id'] !== null ? (int)$c['program_id'] : null;
    $grade = (int)$c['grade_level'];

    if (subject_is_rpp($subjectId)) {
        if (!$programId) {
            return ['ok'=>false,'code'=>'program_missing','error'=>'Паралелката няма зададена професия/специалност.','mode'=>'professional','is_rpp'=>true];
        }
        if (!rpp_subject_available_for_class($subjectId, $classId)) {
            return ['ok'=>false,'code'=>'subject_class','error'=>'Този РПП предмет не е зададен за класа и професията/специалността на избраната паралелка.','mode'=>'professional','is_rpp'=>true];
        }
        return ['ok'=>true,'mode'=>'professional','program_id'=>$programId,'grade_level'=>$grade,'is_rpp'=>true];
    }

    $type = subject_training_type($subjectId);
    if (!$type) {
        return [
            'ok'=>false,
            'code'=>'subject_class',
            'error'=>'Предметът няма активни компетентности и не може да се използва за анализ.',
        ];
    }

    if ($type === 'professional') {
        if (!$programId) {
            return [
                'ok'=>false,
                'code'=>'program_missing',
                'error'=>'Паралелката няма зададена професия/специалност.',
                'mode'=>'professional',
            ];
        }
        $specific = (bool)one(
            'SELECT id FROM mo_competencies
             WHERE subject_id=? AND grade_level=? AND program_id=? AND is_active=1 LIMIT 1',
            [$subjectId, $grade, $programId]
        );
        if (!$specific) {
            return [
                'ok'=>false,
                'code'=>'subject_class',
                'error'=>'Този професионален предмет няма компетентности за специалността/професията на избраната паралелка.',
                'mode'=>'professional',
            ];
        }
        return ['ok'=>true, 'mode'=>'professional', 'program_id'=>$programId, 'grade_level'=>$grade];
    }

    // Общообразователен предмет. За „Чужд език по професията“ са валидни
    // и компетентности за конкретната програма, но маршрутът остава general.
    $available = false;
    if (subject_is_general_exception($subjectId) && $programId) {
        $available = (bool)one(
            'SELECT id FROM mo_competencies
             WHERE subject_id=? AND grade_level=? AND program_id=? AND is_active=1 LIMIT 1',
            [$subjectId, $grade, $programId]
        );
    }
    if (!$available) {
        $available = (bool)one(
            'SELECT id FROM mo_competencies
             WHERE subject_id=? AND grade_level=? AND program_id IS NULL AND is_active=1 LIMIT 1',
            [$subjectId, $grade]
        );
    }
    if (!$available) {
        return [
            'ok'=>false,
            'code'=>'subject_class',
            'error'=>'Този общообразователен предмет няма компетентности за избраната паралелка.',
            'mode'=>'general',
        ];
    }

    return ['ok'=>true, 'mode'=>'general', 'program_id'=>$programId, 'grade_level'=>$grade];
}

/**
 * Автоматично определя МО за предмет + клас.
 */
function resolve_subject_department_for_class(int $subjectId, int $classId): array
{
    $context = subject_context_for_class($subjectId, $classId);
    if (empty($context['ok'])) return $context;

    if (($context['mode'] ?? '') === 'professional') {
        $programId = (int)($context['program_id'] ?? 0);
        if (!$programId) {
            return [
                'ok'=>false,
                'code'=>'program_missing',
                'error'=>'Паралелката няма зададена професия/специалност.',
                'route_mode'=>'professional',
            ];
        }
        $assignment = program_department_assignment($programId);
        if (!$assignment || !(int)($assignment['department_active'] ?? 0)) {
            return [
                'ok'=>false,
                'code'=>'program_department',
                'error'=>'За професията/специалността на паралелката няма зададено активно методическо обединение.',
                'route_mode'=>'professional',
            ];
        }
        return [
            'ok'=>true,
            'assignment'=>$assignment,
            'resolution'=>'program_department',
            'route_mode'=>'professional',
        ];
    }

    $general = general_subject_department_assignment($subjectId);
    $general['route_mode'] = 'general';
    return $general;
}

function department_of_subject_for_class(int $subjectId, int $classId): ?int
{
    $r = resolve_subject_department_for_class($subjectId, $classId);
    return !empty($r['ok']) ? (int)$r['assignment']['department_id'] : null;
}

/**
 * Legacy helper: mo_subjects.department_id отразява само едното
 * общообразователно МО на предмета.
 */
function sync_subject_legacy_department(int $subjectId): void
{
    $rows = all('SELECT department_id FROM mo_subject_departments WHERE subject_id=? AND is_active=1 ORDER BY department_id', [$subjectId]);
    $dep = count($rows) === 1 ? (int)$rows[0]['department_id'] : null;
    q('UPDATE mo_subjects SET department_id=? WHERE id=?', [$dep, $subjectId]);
}

/** Пълният маршрут на анализ до председателя на правилното МО. */
function analysis_route(int $subjectId, int $classId, ?int $yearId = null): array
{
    $class = one('SELECT c.id, c.name, c.year_id, c.grade_level, c.program_id, c.is_active,
                         p.name AS program_name, p.kind AS program_kind
                  FROM mo_classes c
                  LEFT JOIN mo_programs p ON p.id = c.program_id
                  WHERE c.id = ?', [$classId]);
    if (!$class || !(int)$class['is_active']) {
        return ['ok' => false, 'code' => 'class', 'error' => 'Паралелката не съществува или е скрита.'];
    }
    if ($yearId !== null && (int)$class['year_id'] !== $yearId) {
        return ['ok' => false, 'code' => 'year', 'error' => 'Паралелката не е от избраната учебна година.'];
    }

    $subject = one('SELECT id, name, is_active FROM mo_subjects WHERE id = ?', [$subjectId]);
    if (!$subject || !(int)$subject['is_active']) {
        return ['ok' => false, 'code' => 'subject', 'error' => 'Предметът не съществува или е скрит.'];
    }

    $resolved = resolve_subject_department_for_class($subjectId, $classId);
    if (empty($resolved['ok'])) {
        return array_merge($resolved, ['class'=>$class, 'subject'=>$subject]);
    }

    $assignment = $resolved['assignment'];
    $chairId = $assignment['chair_id'] !== null ? (int)$assignment['chair_id'] : 0;
    $chairName = trim((string)($assignment['chair_name'] ?? ''));
    if (!$chairId) {
        return [
            'ok'=>false,
            'code'=>'chair',
            'error'=>'За ' . $assignment['department_name'] . ' няма назначен председател. Анализът може да се пази като чернова, но не може да бъде изпратен.',
            'department_id'=>(int)$assignment['department_id'],
            'department_name'=>$assignment['department_name'],
            'class'=>$class,
            'subject'=>$subject,
            'assignment'=>$assignment,
            'resolution'=>$resolved['resolution'] ?? null,
            'route_mode'=>$resolved['route_mode'] ?? null,
        ];
    }

    return [
        'ok'=>true,
        'department_id'=>(int)$assignment['department_id'],
        'department_name'=>$assignment['department_name'],
        'methodist_id'=>$chairId,
        'methodist_name'=>$chairName !== '' ? $chairName : (string)($assignment['chair_email'] ?? ''),
        'methodist_email'=>(string)($assignment['chair_email'] ?? ''),
        'deputy_id'=>$assignment['deputy_id'] !== null ? (int)$assignment['deputy_id'] : null,
        'deputy_name'=>trim((string)($assignment['deputy_name'] ?? '')),
        'class'=>$class,
        'subject'=>$subject,
        'assignment'=>$assignment,
        'resolution'=>$resolved['resolution'] ?? null,
        'route_mode'=>$resolved['route_mode'] ?? null,
        'is_rpp'=>subject_is_rpp($subjectId),
    ];
}
