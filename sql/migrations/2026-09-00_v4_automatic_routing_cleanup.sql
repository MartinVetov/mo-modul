-- =====================================================================
-- MO module v4 – cleanup for automatic routing
-- Professional subjects are routed ONLY by class profession/specialty.
-- mo_subject_departments is retained ONLY for general-education subjects.
-- Run once after 03_v3_routing_migration.sql on an existing v3 database.
-- =====================================================================

START TRANSACTION;

-- Remove subject-to-MO links from subjects that have no general
-- (program_id IS NULL) competencies. These links are legacy v3 data and
-- are no longer used for professional routing.
DELETE sd
FROM mo_subject_departments sd
WHERE NOT EXISTS (
    SELECT 1
    FROM mo_competencies k
    WHERE k.subject_id = sd.subject_id
      AND k.is_active = 1
      AND k.program_id IS NULL
);

-- Keep the legacy mo_subjects.department_id synchronized only with the
-- single general-education assignment. If configuration is ambiguous,
-- leave it NULL; the admin page will flag it for correction.
UPDATE mo_subjects s
LEFT JOIN (
    SELECT subject_id,
           CASE WHEN COUNT(*) = 1 THEN MAX(department_id) ELSE NULL END AS department_id
    FROM mo_subject_departments
    WHERE is_active = 1
    GROUP BY subject_id
) x ON x.subject_id = s.id
SET s.department_id = x.department_id;

COMMIT;
