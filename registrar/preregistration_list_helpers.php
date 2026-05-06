<?php

if (!function_exists('registrar_prereg_format_datetime')) {
    function registrar_prereg_format_datetime($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 'N/A';
        }

        $timestamp = strtotime($raw);
        return ($timestamp !== false) ? date('M j, Y g:i A', $timestamp) : $raw;
    }
}

if (!function_exists('registrar_prereg_build_filters')) {
    function registrar_prereg_build_filters(array $input): array
    {
        return [
            'search' => trim((string) ($input['q'] ?? '')),
            'program_id' => max(0, (int) ($input['program_id'] ?? 0)),
            'limit' => max(1, min(100, (int) ($input['limit'] ?? 30))),
            'offset' => max(0, (int) ($input['offset'] ?? 0)),
        ];
    }
}

if (!function_exists('registrar_prereg_query_parts')) {
    function registrar_prereg_query_parts(mysqli $conn, array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $programId = max(0, (int) ($filters['program_id'] ?? 0));
        $where = ["spr.status = 'submitted'"];
        $types = '';
        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(
                spr.examinee_number LIKE ?
                OR COALESCE(pr.full_name, \'\') LIKE ?
                OR COALESCE(p.program_code, \'\') LIKE ?
                OR COALESCE(p.program_name, \'\') LIKE ?
                OR COALESCE(sp.secondary_school_name, \'\') LIKE ?
                OR COALESCE(program_campus.campus_name, c.campus_name, \'\') LIKE ?
                OR COALESCE(rc.citymunDesc, \'\') LIKE ?
                OR COALESCE(rb.brgyDesc, \'\') LIKE ?
            )';
            $types .= 'ssssssss';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if ($programId > 0) {
            $where[] = 'spr.program_id = ?';
            $types .= 'i';
            $params[] = $programId;
        }

        return [
            'where_sql' => implode(' AND ', $where),
            'types' => $types,
            'params' => $params,
        ];
    }
}

if (!function_exists('registrar_prereg_fetch_rows')) {
    function registrar_prereg_fetch_rows(mysqli $conn, array $filters): array
    {
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 30)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $parts = registrar_prereg_query_parts($conn, $filters);
        $whereSql = (string) $parts['where_sql'];
        $types = (string) $parts['types'];
        $params = (array) $parts['params'];

        $sql = "
            SELECT
                spr.preregistration_id,
                spr.credential_id,
                spr.interview_id,
                spr.examinee_number,
                spr.program_id,
                spr.locked_rank,
                spr.profile_completion_percent AS submitted_profile_completion_percent,
                spr.agreement_accepted,
                spr.agreement_accepted_at,
                spr.status,
                spr.submitted_at,
                spr.updated_at,
                COALESCE(sp.profile_completion_percent, spr.profile_completion_percent) AS current_profile_completion_percent,
                sp.sex,
                sp.civil_status,
                sp.religion,
                sp.secondary_school_name,
                sp.secondary_school_type,
                rc.citymunDesc AS citymun_name,
                rb.brgyDesc AS barangay_name,
                pr.full_name,
                COALESCE(program_campus.campus_name, c.campus_name) AS campus_name,
                si.classification,
                si.final_score,
                si.interview_datetime,
                ec.class_desc AS etg_class_name,
                p.program_code,
                p.program_name,
                p.major
            FROM tbl_student_preregistration spr
            LEFT JOIN tbl_student_profile sp
                ON sp.credential_id = spr.credential_id
            LEFT JOIN tbl_student_interview si
                ON si.interview_id = spr.interview_id
            LEFT JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            LEFT JOIN tbl_program p
                ON p.program_id = spr.program_id
            LEFT JOIN tbl_college program_college
                ON program_college.college_id = p.college_id
            LEFT JOIN tbl_campus program_campus
                ON program_campus.campus_id = program_college.campus_id
            LEFT JOIN tbl_campus c
                ON c.campus_id = si.campus_id
            LEFT JOIN tbl_etg_class ec
                ON ec.etgclassid = si.etg_class_id
            LEFT JOIN refcitymun rc
                ON rc.citymunCode = sp.citymun_code
            LEFT JOIN refbrgy rb
                ON rb.brgyCode = sp.barangay_code
            WHERE {$whereSql}
            ORDER BY
                CASE
                    WHEN spr.locked_rank IS NULL OR spr.locked_rank = 0 THEN 1
                    ELSE 0
                END ASC,
                spr.locked_rank ASC,
                spr.submitted_at DESC,
                spr.preregistration_id DESC
            LIMIT ? OFFSET ?
        ";

        $rows = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $rows;
        }

        $stmtTypes = $types . 'ii';
        $stmtParams = $params;
        $stmtParams[] = $limit;
        $stmtParams[] = $offset;
        $stmt->bind_param($stmtTypes, ...$stmtParams);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['program_label'] = function_exists('student_preregistration_format_program_label')
                ? student_preregistration_format_program_label($row)
                : trim((string) ($row['program_name'] ?? 'No Program'));
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('registrar_prereg_count_rows')) {
    function registrar_prereg_count_rows(mysqli $conn, array $filters): int
    {
        $parts = registrar_prereg_query_parts($conn, $filters);
        $whereSql = (string) $parts['where_sql'];
        $types = (string) $parts['types'];
        $params = (array) $parts['params'];

        $sql = "
            SELECT COUNT(*) AS total
            FROM tbl_student_preregistration spr
            LEFT JOIN tbl_student_profile sp
                ON sp.credential_id = spr.credential_id
            LEFT JOIN tbl_student_interview si
                ON si.interview_id = spr.interview_id
            LEFT JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            LEFT JOIN tbl_program p
                ON p.program_id = spr.program_id
            LEFT JOIN tbl_college program_college
                ON program_college.college_id = p.college_id
            LEFT JOIN tbl_campus program_campus
                ON program_campus.campus_id = program_college.campus_id
            LEFT JOIN tbl_campus c
                ON c.campus_id = si.campus_id
            LEFT JOIN refcitymun rc
                ON rc.citymunCode = sp.citymun_code
            LEFT JOIN refbrgy rb
                ON rb.brgyCode = sp.barangay_code
            WHERE {$whereSql}
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return max(0, (int) ($row['total'] ?? 0));
    }
}

if (!function_exists('registrar_prereg_render_rows')) {
    function registrar_prereg_render_rows(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        ob_start();
        foreach ($rows as $row):
            $currentPercent = (float) ($row['current_profile_completion_percent'] ?? 0);
            $submittedPercent = (float) ($row['submitted_profile_completion_percent'] ?? 0);
            $classification = trim((string) ($row['classification'] ?? ''));
            $classificationLabel = $classification !== '' ? $classification : 'N/A';
            $school = trim((string) ($row['secondary_school_name'] ?? ''));
            $city = trim((string) ($row['citymun_name'] ?? ''));
            $barangay = trim((string) ($row['barangay_name'] ?? ''));
            ?>
            <tr>
              <td>
                <div class="rpr-student-name"><?= htmlspecialchars((string) ($row['full_name'] ?? 'Unknown Student')); ?></div>
                <small class="rpr-subline">Examinee #: <?= htmlspecialchars((string) ($row['examinee_number'] ?? '')); ?></small>
                <small class="rpr-subline">Classification: <?= htmlspecialchars($classificationLabel); ?></small>
              </td>
              <td>
                <div><?= htmlspecialchars((string) ($row['program_label'] ?? 'No Program')); ?></div>
                <small class="rpr-subline">Campus: <?= htmlspecialchars((string) ($row['campus_name'] ?? 'No Campus')); ?></small>
                <?php if (strtoupper($classification) === 'ETG'): ?>
                  <small class="rpr-subline">ETG: <?= htmlspecialchars((string) ($row['etg_class_name'] ?? 'No ETG class')); ?></small>
                <?php endif; ?>
              </td>
              <td>
                <div><?= htmlspecialchars($school !== '' ? $school : 'No high school'); ?></div>
                <small class="rpr-subline">Type: <?= htmlspecialchars((string) ($row['secondary_school_type'] ?? 'N/A')); ?></small>
              </td>
              <td>
                <div><?= htmlspecialchars($city !== '' ? $city : 'No city/municipality'); ?></div>
                <small class="rpr-subline">Barangay: <?= htmlspecialchars($barangay !== '' ? $barangay : 'N/A'); ?></small>
              </td>
              <td class="text-center">
                <?php if ((int) ($row['locked_rank'] ?? 0) > 0): ?>
                  <span class="badge bg-label-warning">#<?= number_format((int) $row['locked_rank']); ?></span>
                <?php else: ?>
                  <span class="badge bg-label-secondary">N/A</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($currentPercent >= 100): ?>
                  <span class="badge bg-label-success"><?= number_format($currentPercent, 0); ?>%</span>
                <?php else: ?>
                  <span class="badge bg-label-warning"><?= number_format($currentPercent, 0); ?>%</span>
                <?php endif; ?>
                <small class="rpr-subline">Submitted: <?= number_format($submittedPercent, 0); ?>%</small>
              </td>
              <td>
                <div><?= htmlspecialchars(registrar_prereg_format_datetime((string) ($row['submitted_at'] ?? ''))); ?></div>
                <small class="rpr-subline">Updated: <?= htmlspecialchars(registrar_prereg_format_datetime((string) ($row['updated_at'] ?? ''))); ?></small>
              </td>
            </tr>
            <?php
        endforeach;

        return (string) ob_get_clean();
    }
}
