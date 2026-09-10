<?php
/**
 * Данните за обобщението на методиста и изготвянето на Word документа.
 * Отделени тук, за да ги ползват и страницата с обобщението, и експортът.
 */

/** Всички числа и списъци за един методист, година и срок. */
function summary_data(int $departmentId, int $yid, string $term): array
{
    $P = [':d' => $departmentId, ':y' => $yid, ':t' => $term];

    $sum = one('SELECT COUNT(*) n, COUNT(DISTINCT user_id) teachers, AVG(avg_grade) avg,
                       COALESCE(SUM(total_grades),0) grades, COALESCE(SUM(students_count),0) students,
                       COALESCE(SUM(g2),0) weak, COALESCE(SUM(g6),0) top,
                       COALESCE(SUM(c_mastered),0) ok, COALESCE(SUM(c_partial),0) partial,
                       COALESCE(SUM(c_failed),0) no
                FROM mo_v_entries
                WHERE department_id=:d AND status="sent" AND year_id=:y AND term=:t', $P)
        ?? ['n' => 0, 'teachers' => 0, 'avg' => null, 'grades' => 0, 'students' => 0,
            'weak' => 0, 'top' => 0, 'ok' => 0, 'partial' => 0, 'no' => 0];

    $bySubject = all('SELECT subject_name, program_name, COUNT(*) n, AVG(avg_grade) avg,
                             COALESCE(SUM(total_grades),0) grades, COALESCE(SUM(g2),0) weak,
                             SUM(c_mastered) ok, SUM(c_partial) partial, SUM(c_failed) no
                      FROM mo_v_entries
                      WHERE department_id=:d AND status="sent" AND year_id=:y AND term=:t
                      GROUP BY subject_name, program_name ORDER BY subject_name', $P);

    $byClass = all('SELECT class_name, grade_level, program_name, COUNT(*) n,
                           AVG(avg_grade) avg, SUM(c_failed) no
                    FROM mo_v_entries
                    WHERE department_id=:d AND status="sent" AND year_id=:y AND term=:t
                    GROUP BY class_name, grade_level, program_name
                    ORDER BY grade_level, class_name', $P);

    $failed = all('SELECT z.title,z.code,z.subject_name,z.grade_level,
                          SUM(z.failed) failed,SUM(z.partial) partial
                   FROM (
                     SELECT k.title,k.code,s.name subject_name,c.grade_level,
                            SUM(ec.state="not_mastered") failed,SUM(ec.state="partial") partial
                     FROM mo_entry_competencies ec
                     JOIN mo_competencies k ON k.id=ec.competency_id
                     JOIN mo_subjects s ON s.id=k.subject_id
                     JOIN mo_entries e ON e.id=ec.entry_id
                     JOIN mo_classes c ON c.id=e.class_id
                     WHERE e.department_id=:d1 AND e.status="sent" AND e.year_id=:y1 AND e.term=:t1
                     GROUP BY k.id,k.title,k.code,s.name,c.grade_level
                     UNION ALL
                     SELECT mc.title,"РПП" AS code,s.name subject_name,c.grade_level,
                            SUM(mc.state="not_mastered") failed,SUM(mc.state="partial") partial
                     FROM mo_entry_manual_competencies mc
                     JOIN mo_entries e ON e.id=mc.entry_id
                     JOIN mo_subjects s ON s.id=e.subject_id
                     JOIN mo_classes c ON c.id=e.class_id
                     WHERE e.department_id=:d2 AND e.status="sent" AND e.year_id=:y2 AND e.term=:t2
                     GROUP BY mc.title,s.name,c.grade_level
                   ) z
                   GROUP BY z.title,z.code,z.subject_name,z.grade_level
                   HAVING failed > 0 OR partial > 0
                   ORDER BY failed DESC,partial DESC LIMIT 15',
                  [':d1'=>$departmentId,':y1'=>$yid,':t1'=>$term,
                   ':d2'=>$departmentId,':y2'=>$yid,':t2'=>$term]);

    $measures = all('SELECT teacher_name, subject_name, class_name, measures FROM mo_v_entries
                     WHERE department_id=:d AND status="sent" AND year_id=:y AND term=:t
                       AND measures IS NOT NULL AND measures <> ""
                     ORDER BY teacher_name', $P);

    $rows = all('SELECT * FROM mo_v_entries
                 WHERE department_id=:d AND status="sent" AND year_id=:y AND term=:t
                 ORDER BY teacher_name, grade_level, class_name', $P);

    return compact('sum', 'bySubject', 'byClass', 'failed', 'measures', 'rows')
         + ['year' => one('SELECT label FROM mo_years WHERE id=?', [$yid])['label'] ?? ''];
}

/** Подготвя данните за AI промпта. */
function ai_context(array $D, string $methodistName, string $term): array
{
    $g = max(1, (int)$D['sum']['grades']);
    return [
        'department' => $methodistName,
        'year' => $D['year'],
        'term' => term_label($term),
        'records' => (int)$D['sum']['n'],
        'grades'  => (int)$D['sum']['grades'],
        'avg'     => fmt_avg($D['sum']['avg']),
        'weak'    => (int)$D['sum']['weak'],
        'weak_pct' => fmt_pct((int)$D['sum']['weak'] / $g),
        'top'     => (int)$D['sum']['top'],
        'top_pct' => 'усвоени ' . (int)$D['sum']['ok'] . ', частично ' . (int)$D['sum']['partial']
                   . ', неусвоени ' . (int)$D['sum']['no'],
        'by_subject' => array_map(static function ($r) {
            return ['subject_name' => $r['subject_name'] . ($r['program_name'] ? ' (' . $r['program_name'] . ')' : ''),
                    'avg' => fmt_avg($r['avg']),
                    'weak_pct' => 'неусвоени ' . (int)$r['no'],
                    'records' => (int)$r['n']];
        }, $D['bySubject']),
        'topics' => array_map(static fn($m) => [
            'subject_name' => $m['subject_name'], 'class_name' => $m['class_name'],
            'weakest_topic' => mb_strimwidth((string)$m['measures'], 0, 200, '…'),
            'reason' => '', 'measure' => ''], $D['measures']),
        'competencies' => array_map(static fn($f) => [
            'title' => $f['title'] . ' (' . $f['subject_name'] . ', ' . (int)$f['grade_level'] . ' кл.)',
            'ok' => 0, 'part' => (int)$f['partial'], 'no' => (int)$f['failed']], $D['failed']),
    ];
}

/**
 * Изготвя Word документа и HTML копие за преглед и печат в PDF.
 * @return array{docx: DocxWriter, html: string, title: string}
 */
function build_summary_doc(array $D, array $rep, array $u, string $term): array
{
    $title = trim((string)($rep['title'] ?? '')) !== ''
        ? (string)$rep['title']
        : 'Обобщен анализ на методическото обединение';

    $sub = SCHOOL_NAME . ' · ' . $D['year'] . ' · ' . term_label($term);

    $d = new DocxWriter(mb_strtoupper($title, 'UTF-8'), $sub);

    /* --- 1. Обобщени резултати --- */
    $d->heading('1. Обобщени резултати');
    $s = $D['sum'];
    $d->paragraph(sprintf(
        'Настоящият анализ обхваща %d анализа, изготвени от %d учители. Общият брой поставени оценки е %d, '
      . 'при среден успех %s. Отчетени са %d усвоени, %d частично усвоени и %d неусвоени компетентности; '
      . 'останалите се пренасят за втория учебен срок.',
        (int)$s['n'], (int)$s['teachers'], (int)$s['grades'], fmt_avg($s['avg']),
        (int)$s['ok'], (int)$s['partial'], (int)$s['no']));

    if ($D['bySubject']) {
        $rows = [];
        foreach ($D['bySubject'] as $r) {
            $rows[] = [$r['subject_name'], $r['program_name'] ?: '—', (string)(int)$r['n'],
                       fmt_avg($r['avg']), (string)(int)$r['ok'], (string)(int)$r['partial'], (string)(int)$r['no']];
        }
        $d->table(['Предмет', 'Специалност', 'Анализи', 'Среден успех', 'Усвоени', 'Частично', 'Неусвоени'], $rows);
    }

    if ($D['byClass']) {
        $d->heading('2. Резултати по паралелки');
        $rows = [];
        foreach ($D['byClass'] as $r) {
            $rows[] = [$r['class_name'], $r['program_name'] ?: '—', (string)(int)$r['n'],
                       fmt_avg($r['avg']), (string)(int)$r['no']];
        }
        $d->table(['Паралелка', 'Специалност', 'Анализи', 'Среден успех', 'Неусвоени компетентности'], $rows);
    }

    $n = 3;
    if (trim((string)($rep['summary_text'] ?? '')) !== '') {
        $d->heading($n++ . '. Обобщение на методическото обединение');
        $d->paragraph((string)$rep['summary_text']);
    }
    foreach ([['strengths', 'Силни страни'], ['improvements', 'Области за подобрение'],
              ['measures', 'Мерки на методическото обединение'], ['notes', 'Бележки на методиста'],
              ['other', 'Други']] as [$f, $lab]) {
        if (trim((string)($rep[$f] ?? '')) === '') continue;
        $d->heading($n++ . '. ' . $lab);
        $d->paragraph((string)$rep[$f]);
    }

    if ($D['failed']) {
        $d->heading($n++ . '. Компетентности, изискващи внимание');
        $rows = [];
        foreach ($D['failed'] as $f) {
            $rows[] = [$f['title'], $f['subject_name'], (int)$f['grade_level'] . ' клас',
                       (string)(int)$f['partial'], (string)(int)$f['failed']];
        }
        $d->table(['Компетентност', 'Предмет', 'Клас', 'Частично', 'Неусвоена'], $rows);
    }

    if ($D['measures']) {
        $d->heading($n++ . '. Мерки, предложени от учителите');
        $items = [];
        foreach ($D['measures'] as $m) {
            $items[] = $m['teacher_name'] . ' (' . $m['subject_name'] . ', ' . $m['class_name'] . '): ' . $m['measures'];
        }
        $d->bullets($items);
    }

    $d->signature('Методист: ' . $u['display_name'] . ' ......................',
                  'Дата: ' . date('d.m.Y'));

    /* --- HTML копие за преглед и печат в PDF --- */
    $h = '<h1>' . e(mb_strtoupper($title, 'UTF-8')) . '</h1><p class="center"><em>' . e($sub) . '</em></p>';
    $h .= '<h2>1. Обобщени резултати</h2><p>' . e(sprintf(
        'Настоящият анализ обхваща %d анализа, изготвени от %d учители. Общият брой поставени оценки е %d, '
      . 'при среден успех %s. Отчетени са %d усвоени, %d частично усвоени и %d неусвоени компетентности; '
      . 'останалите се пренасят за втория учебен срок.',
        (int)$s['n'], (int)$s['teachers'], (int)$s['grades'], fmt_avg($s['avg']),
        (int)$s['ok'], (int)$s['partial'], (int)$s['no'])) . '</p>';

    if ($D['bySubject']) {
        $h .= '<table><tr><th>Предмет</th><th>Професия/специалност</th><th>Анализи</th><th>Среден успех</th>'
            . '<th>Усвоени</th><th>Частично</th><th>Неусвоени</th></tr>';
        foreach ($D['bySubject'] as $r) {
            $h .= '<tr><td>' . e($r['subject_name']) . '</td><td>' . e($r['program_name'] ?: '—') . '</td>'
                . '<td>' . (int)$r['n'] . '</td><td>' . fmt_avg($r['avg']) . '</td><td>' . (int)$r['ok'] . '</td>'
                . '<td>' . (int)$r['partial'] . '</td><td>' . (int)$r['no'] . '</td></tr>';
        }
        $h .= '</table>';
    }

    $k = 2;
    if ($D['byClass']) {
        $h .= '<h2>' . $k++ . '. Резултати по паралелки</h2><table>'
            . '<tr><th>Паралелка</th><th>Професия/специалност</th><th>Анализи</th><th>Среден успех</th><th>Неусвоени</th></tr>';
        foreach ($D['byClass'] as $r) {
            $h .= '<tr><td>' . e($r['class_name']) . '</td><td>' . e($r['program_name'] ?: '—') . '</td>'
                . '<td>' . (int)$r['n'] . '</td><td>' . fmt_avg($r['avg']) . '</td><td>' . (int)$r['no'] . '</td></tr>';
        }
        $h .= '</table>';
    }
    if (trim((string)($rep['summary_text'] ?? '')) !== '') {
        $h .= '<h2>' . $k++ . '. Обобщение на методическото обединение</h2><p>' . nl2br(e((string)$rep['summary_text'])) . '</p>';
    }
    foreach ([['strengths', 'Силни страни'], ['improvements', 'Области за подобрение'],
              ['measures', 'Мерки на методическото обединение'], ['notes', 'Бележки на методиста'],
              ['other', 'Други']] as [$f, $lab]) {
        if (trim((string)($rep[$f] ?? '')) === '') continue;
        $h .= '<h2>' . $k++ . '. ' . e($lab) . '</h2><p>' . nl2br(e((string)$rep[$f])) . '</p>';
    }
    if ($D['failed']) {
        $h .= '<h2>' . $k++ . '. Компетентности, изискващи внимание</h2><table>'
            . '<tr><th>Компетентност</th><th>Предмет</th><th>Клас</th><th>Частично</th><th>Неусвоена</th></tr>';
        foreach ($D['failed'] as $f) {
            $h .= '<tr><td>' . e($f['title']) . '</td><td>' . e($f['subject_name']) . '</td>'
                . '<td>' . (int)$f['grade_level'] . '</td><td>' . (int)$f['partial'] . '</td>'
                . '<td>' . (int)$f['failed'] . '</td></tr>';
        }
        $h .= '</table>';
    }
    if ($D['measures']) {
        $h .= '<h2>' . $k++ . '. Мерки, предложени от учителите</h2><ul>';
        foreach ($D['measures'] as $m) {
            $h .= '<li>' . e($m['teacher_name'] . ' (' . $m['subject_name'] . ', ' . $m['class_name'] . '): '
                . $m['measures']) . '</li>';
        }
        $h .= '</ul>';
    }
    $h .= '<p style="margin-top:2.5em">Методист: ' . e($u['display_name'])
        . ' ......................&nbsp;&nbsp;&nbsp;&nbsp; Дата: ' . date('d.m.Y') . '</p>';

    return ['docx' => $d, 'html' => $h, 'title' => $title];
}

/* =====================================================================
 * Обобщение на цялото училище – сглобява се от обобщенията на МО
 * и от анализите зад тях.
 * ===================================================================== */

/** Числата и списъците за училищното обобщение. */
function school_summary_data(int $yid, string $term): array
{
    $P = [':y' => $yid, ':t' => $term];

    $sum = one('SELECT COUNT(*) n, COUNT(DISTINCT user_id) teachers,
                       COUNT(DISTINCT department_id) deps, AVG(avg_grade) avg,
                       COALESCE(SUM(total_grades),0) grades, COALESCE(SUM(g2),0) weak,
                       COALESCE(SUM(c_mastered),0) ok, COALESCE(SUM(c_partial),0) partial,
                       COALESCE(SUM(c_failed),0) no
                FROM mo_v_entries
                WHERE year_id = :y AND term = :t AND status = "sent"', $P)
        ?? ['n'=>0,'teachers'=>0,'deps'=>0,'avg'=>null,'grades'=>0,'weak'=>0,'ok'=>0,'partial'=>0,'no'=>0];

    $byDepartment = all(
        'SELECT d.id, d.name AS department_name,
                ' . user_name_sql('c') . ' AS chair_name,
                (SELECT COUNT(*) FROM mo_v_entries v
                  WHERE v.department_id = d.id AND v.year_id = :y AND v.term = :t AND v.status = "sent") n,
                (SELECT AVG(v.avg_grade) FROM mo_v_entries v
                  WHERE v.department_id = d.id AND v.year_id = :y2 AND v.term = :t2 AND v.status = "sent") avg,
                (SELECT COALESCE(SUM(v.c_failed),0) FROM mo_v_entries v
                  WHERE v.department_id = d.id AND v.year_id = :y3 AND v.term = :t3 AND v.status = "sent") no,
                (SELECT s.id FROM mo_summaries s
                  WHERE s.department_id = d.id AND s.year_id = :y4 AND s.term = :t4 AND s.status = "sent" LIMIT 1) summary_id,
                (SELECT s.deputy_status FROM mo_summaries s
                  WHERE s.department_id = d.id AND s.year_id = :y5 AND s.term = :t5 AND s.status = "sent" LIMIT 1) deputy_status
         FROM mo_departments d
         LEFT JOIN users c ON c.id = d.chair_id
         WHERE d.is_active = 1
         ORDER BY d.name',
        [':y'=>$yid, ':t'=>$term, ':y2'=>$yid, ':t2'=>$term, ':y3'=>$yid, ':t3'=>$term,
         ':y4'=>$yid, ':t4'=>$term, ':y5'=>$yid, ':t5'=>$term]);

    $summaries = all(
        'SELECT s.*, d.name AS department_name,
                ' . user_name_sql('f') . ' AS author_name
         FROM mo_summaries s
         LEFT JOIN mo_departments d ON d.id = s.department_id
         LEFT JOIN users f ON f.id = COALESCE(s.finalized_by, s.methodist_id)
         WHERE s.status = "sent" AND s.year_id = :y AND s.term = :t
         ORDER BY d.name', $P);

    $failed = all(
        'SELECT k.title, s.name subject_name, c.grade_level,
                SUM(ec.state="not_mastered") failed, SUM(ec.state="partial") partial
         FROM mo_entry_competencies ec
         JOIN mo_competencies k ON k.id = ec.competency_id
         JOIN mo_subjects s ON s.id = k.subject_id
         JOIN mo_entries e ON e.id = ec.entry_id
         JOIN mo_classes c ON c.id = e.class_id
         WHERE e.status = "sent" AND e.year_id = :y AND e.term = :t
         GROUP BY k.id, k.title, s.name, c.grade_level
         HAVING failed > 0 ORDER BY failed DESC LIMIT 15', $P);

    return compact('sum', 'byDepartment', 'summaries', 'failed')
         + ['year' => one('SELECT label FROM mo_years WHERE id=?', [$yid])['label'] ?? ''];
}

/**
 * Word документът на училищното обобщение – същото форматиране като при
 * МО: Times New Roman, 12pt двустранно, заглавия 16pt центрирани.
 *
 * @return array{docx: DocxWriter, html: string, title: string}
 */
function build_school_doc(array $D, array $rep, array $u, string $term): array
{
    $title = trim((string)($rep['title'] ?? '')) !== ''
        ? (string)$rep['title']
        : 'Обобщен анализ на училището';
    $sub = SCHOOL_NAME . ' · ' . $D['year'] . ' · ' . term_label($term);
    $s = $D['sum'];

    $d = new DocxWriter(mb_strtoupper($title, 'UTF-8'), $sub);

    $d->heading('1. Обобщени резултати');
    $intro = sprintf(
        'Анализът обхваща %d анализа от %d учители в %d методически обединения. Общият брой '
      . 'поставени оценки е %d при среден успех %s. Отчетени са %d усвоени, %d частично усвоени '
      . 'и %d неусвоени компетентности.',
        (int)$s['n'], (int)$s['teachers'], (int)$s['deps'], (int)$s['grades'],
        fmt_avg($s['avg']), (int)$s['ok'], (int)$s['partial'], (int)$s['no']);
    $d->paragraph($intro);

    if ($D['byDepartment']) {
        $d->heading('2. По методически обединения');
        $rows = [];
        foreach ($D['byDepartment'] as $r) {
            $rows[] = [$r['department_name'], $r['chair_name'] ?: '—', (string)(int)$r['n'],
                       fmt_avg($r['avg']), (string)(int)$r['no'],
                       $r['summary_id'] ? 'изпратено' : 'няма'];
        }
        $d->table(['Методическо обединение', 'Председател', 'Анализи', 'Среден успех',
                   'Неусвоени', 'Обобщение'], $rows);
    }

    $n = 3;
    foreach ([['summary_text', 'Обща оценка'], ['strengths', 'Силни страни'],
              ['improvements', 'Области за подобрение'], ['measures', 'Мерки за следващия период'],
              ['notes', 'Бележки']] as [$f, $lab]) {
        if (trim((string)($rep[$f] ?? '')) === '') continue;
        $d->heading($n++ . '. ' . $lab);
        $d->paragraph((string)$rep[$f]);
    }

    if ($D['failed']) {
        $d->heading($n++ . '. Компетентности с най-много пропуски');
        $rows = [];
        foreach ($D['failed'] as $f) {
            $rows[] = [$f['title'], $f['subject_name'], (int)$f['grade_level'] . ' клас',
                       (string)(int)$f['partial'], (string)(int)$f['failed']];
        }
        $d->table(['Компетентност', 'Предмет', 'Клас', 'Частично', 'Неусвоена'], $rows);
    }

    if ($D['summaries']) {
        $d->heading($n++ . '. Изводи от методическите обединения');
        foreach ($D['summaries'] as $sm) {
            $d->paragraph($sm['department_name'] . ' (' . ($sm['author_name'] ?: '—') . ')',
                          ['bold' => true, 'align' => 'left']);
            foreach ([['strengths', 'Силни страни'], ['improvements', 'Области за подобрение'],
                      ['measures', 'Мерки']] as [$f, $lab]) {
                if (trim((string)($sm[$f] ?? '')) === '') continue;
                $d->paragraph($lab . ': ' . $sm[$f]);
            }
        }
    }

    $d->signature('Изготвил: ' . $u['display_name'] . ' ......................',
                  'Дата: ' . date('d.m.Y'));

    /* HTML копие за преглед */
    $h = '<h1>' . e(mb_strtoupper($title, 'UTF-8')) . '</h1><p class="center"><em>' . e($sub) . '</em></p>';
    $h .= '<h2>1. Обобщени резултати</h2><p>' . e($intro) . '</p>';
    if ($D['byDepartment']) {
        $h .= '<h2>2. По методически обединения</h2><table><tr><th>МО</th><th>Председател</th>'
            . '<th>Анализи</th><th>Среден успех</th><th>Неусвоени</th><th>Обобщение</th></tr>';
        foreach ($D['byDepartment'] as $r) {
            $h .= '<tr><td>' . e($r['department_name']) . '</td><td>' . e($r['chair_name'] ?: '—') . '</td>'
                . '<td>' . (int)$r['n'] . '</td><td>' . fmt_avg($r['avg']) . '</td>'
                . '<td>' . (int)$r['no'] . '</td><td>' . ($r['summary_id'] ? 'изпратено' : 'няма') . '</td></tr>';
        }
        $h .= '</table>';
    }
    $k = 3;
    foreach ([['summary_text', 'Обща оценка'], ['strengths', 'Силни страни'],
              ['improvements', 'Области за подобрение'], ['measures', 'Мерки за следващия период'],
              ['notes', 'Бележки']] as [$f, $lab]) {
        if (trim((string)($rep[$f] ?? '')) === '') continue;
        $h .= '<h2>' . $k++ . '. ' . e($lab) . '</h2><p>' . nl2br(e((string)$rep[$f])) . '</p>';
    }
    $h .= '<p style="margin-top:2.5em">Изготвил: ' . e($u['display_name'])
        . ' ......................&nbsp;&nbsp;&nbsp;&nbsp; Дата: ' . date('d.m.Y') . '</p>';

    return ['docx' => $d, 'html' => $h, 'title' => $title];
}
