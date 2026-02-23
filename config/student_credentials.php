<?php
/**
 * Shared helpers for student portal credentials.
 */

if (!function_exists('ensure_student_credentials_table')) {
    function ensure_student_credentials_table($conn)
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tbl_student_credentials (
                credential_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                placement_result_id INT UNSIGNED NOT NULL,
                interview_id INT UNSIGNED DEFAULT NULL,
                examinee_number VARCHAR(50) NOT NULL,
                active_email VARCHAR(190) DEFAULT NULL,
                temp_code VARCHAR(8) DEFAULT NULL,
                password_hash VARCHAR(255) NOT NULL,
                must_change_password TINYINT(1) NOT NULL DEFAULT 1,
                status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                password_changed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_student_credentials_examinee (examinee_number),
                UNIQUE KEY uq_student_credentials_temp_code (temp_code),
                KEY idx_student_credentials_placement (placement_result_id),
                KEY idx_student_credentials_interview (interview_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        $ok = (bool) $conn->query($sql);
        if (!$ok) {
            return false;
        }

        $emailColumnResult = $conn->query("SHOW COLUMNS FROM tbl_student_credentials LIKE 'active_email'");
        if (!$emailColumnResult) {
            return false;
        }

        $hasEmailColumn = ($emailColumnResult->num_rows > 0);
        $emailColumnResult->free();

        if (!$hasEmailColumn) {
            $alterOk = (bool) $conn->query(
                "ALTER TABLE tbl_student_credentials ADD COLUMN active_email VARCHAR(190) DEFAULT NULL AFTER examinee_number"
            );
            if (!$alterOk) {
                return false;
            }
        }

        // Remove legacy plaintext temporary codes from storage.
        $conn->query("UPDATE tbl_student_credentials SET temp_code = NULL WHERE temp_code IS NOT NULL");
        return true;
    }
}

if (!function_exists('generate_student_temp_code')) {
    function generate_student_temp_code($length = 8)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIndex = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $maxIndex)];
        }

        return $code;
    }
}

if (!function_exists('generate_unique_student_temp_code')) {
    function generate_unique_student_temp_code($conn, $length = 12, $maxAttempts = 1)
    {
        $code = generate_student_temp_code($length);
        return [true, $code, null];
    }
}

if (!function_exists('provision_student_credentials')) {
    /**
     * Creates or updates student credentials tied to examinee number.
     * If $rotatePassword is true, a new unique temporary password is issued.
     */
    function provision_student_credentials($conn, $placementResultId, $interviewId, $examineeNumber, $rotatePassword = true)
    {
        $placementResultId = (int) $placementResultId;
        $interviewId = (int) $interviewId;
        $examineeNumber = trim((string) $examineeNumber);

        if ($placementResultId <= 0 || $interviewId <= 0 || $examineeNumber === '') {
            return [
                'success' => false,
                'message' => 'Invalid input while provisioning student credentials.'
            ];
        }

        if (!ensure_student_credentials_table($conn)) {
            return [
                'success' => false,
                'message' => 'Failed ensuring student credentials storage.'
            ];
        }

        $findSql = "
            SELECT credential_id, must_change_password
            FROM tbl_student_credentials
            WHERE examinee_number = ?
            LIMIT 1
        ";
        $findStmt = $conn->prepare($findSql);
        if (!$findStmt) {
            return [
                'success' => false,
                'message' => 'Failed to prepare credential lookup.'
            ];
        }
        $findStmt->bind_param('s', $examineeNumber);
        $findStmt->execute();
        $existing = $findStmt->get_result()->fetch_assoc();
        $findStmt->close();

        $credentialId = isset($existing['credential_id']) ? (int) $existing['credential_id'] : 0;
        $issuedTempCode = null;
        $created = false;
        $rotated = false;

        if ($credentialId <= 0 || $rotatePassword) {
            list($ok, $tempCode, $errorMessage) = generate_unique_student_temp_code($conn, 12, 1);
            if (!$ok) {
                return [
                    'success' => false,
                    'message' => $errorMessage ?: 'Failed generating temporary password.'
                ];
            }

            $issuedTempCode = $tempCode;
            $passwordHash = password_hash($issuedTempCode, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to hash temporary password.'
                ];
            }
        }

        if ($credentialId > 0) {
            if ($rotatePassword) {
                $updateSql = "
                    UPDATE tbl_student_credentials
                    SET placement_result_id = ?,
                        interview_id = ?,
                        password_hash = ?,
                        must_change_password = 1,
                        status = 'active',
                        password_changed_at = NULL
                    WHERE credential_id = ?
                    LIMIT 1
                ";
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    return [
                        'success' => false,
                        'message' => 'Failed to prepare credential update.'
                    ];
                }
                $updateStmt->bind_param(
                    'iisi',
                    $placementResultId,
                    $interviewId,
                    $passwordHash,
                    $credentialId
                );
                $okUpdate = $updateStmt->execute();
                $updateStmt->close();

                if (!$okUpdate) {
                    return [
                        'success' => false,
                        'message' => 'Failed updating student credentials.'
                    ];
                }

                $rotated = true;
            } else {
                $updateSql = "
                    UPDATE tbl_student_credentials
                    SET placement_result_id = ?,
                        interview_id = ?,
                        status = 'active'
                    WHERE credential_id = ?
                    LIMIT 1
                ";
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    return [
                        'success' => false,
                        'message' => 'Failed to prepare student credential sync.'
                    ];
                }
                $updateStmt->bind_param('iii', $placementResultId, $interviewId, $credentialId);
                $okUpdate = $updateStmt->execute();
                $updateStmt->close();

                if (!$okUpdate) {
                    return [
                        'success' => false,
                        'message' => 'Failed syncing student credentials.'
                    ];
                }
            }
        } else {
            $insertSql = "
                INSERT INTO tbl_student_credentials (
                    placement_result_id,
                    interview_id,
                    examinee_number,
                    password_hash,
                    must_change_password,
                    status
                ) VALUES (?, ?, ?, ?, 1, 'active')
            ";
            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) {
                return [
                    'success' => false,
                    'message' => 'Failed to prepare credential insert.'
                ];
            }
            $insertStmt->bind_param(
                'iiss',
                $placementResultId,
                $interviewId,
                $examineeNumber,
                $passwordHash
            );
            $okInsert = $insertStmt->execute();
            $insertStmt->close();

            if (!$okInsert) {
                return [
                    'success' => false,
                    'message' => 'Failed creating student credentials.'
                ];
            }

            $created = true;
        }

        return [
            'success' => true,
            'created' => $created,
            'rotated' => $rotated,
            'temporary_code' => $issuedTempCode,
            'examinee_number' => $examineeNumber
        ];
    }
}

if (!function_exists('reset_student_temporary_password')) {
    /**
     * Issues a new temporary password for an existing student credential.
     */
    function reset_student_temporary_password($conn, $examineeNumber)
    {
        $examineeNumber = trim((string) $examineeNumber);
        if ($examineeNumber === '') {
            return [
                'success' => false,
                'message' => 'Examinee number is required.'
            ];
        }

        if (!ensure_student_credentials_table($conn)) {
            return [
                'success' => false,
                'message' => 'Failed ensuring student credentials storage.'
            ];
        }

        $findSql = "
            SELECT credential_id
            FROM tbl_student_credentials
            WHERE examinee_number = ?
              AND status = 'active'
            LIMIT 1
        ";
        $findStmt = $conn->prepare($findSql);
        if (!$findStmt) {
            return [
                'success' => false,
                'message' => 'Failed to prepare credential lookup.'
            ];
        }
        $findStmt->bind_param('s', $examineeNumber);
        $findStmt->execute();
        $existing = $findStmt->get_result()->fetch_assoc();
        $findStmt->close();

        $credentialId = (int) ($existing['credential_id'] ?? 0);
        if ($credentialId <= 0) {
            return [
                'success' => false,
                'message' => 'No active student credential found for this examinee number.'
            ];
        }

        list($ok, $tempCode, $errorMessage) = generate_unique_student_temp_code($conn, 12, 1);
        if (!$ok) {
            return [
                'success' => false,
                'message' => $errorMessage ?: 'Failed generating temporary password.'
            ];
        }

        $passwordHash = password_hash($tempCode, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            return [
                'success' => false,
                'message' => 'Failed to hash temporary password.'
            ];
        }

        $updateSql = "
            UPDATE tbl_student_credentials
            SET password_hash = ?,
                must_change_password = 1,
                status = 'active',
                password_changed_at = NULL
            WHERE credential_id = ?
            LIMIT 1
        ";
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            return [
                'success' => false,
                'message' => 'Failed to prepare credential update.'
            ];
        }
        $updateStmt->bind_param('si', $passwordHash, $credentialId);
        $okUpdate = $updateStmt->execute();
        $updateStmt->close();

        if (!$okUpdate) {
            return [
                'success' => false,
                'message' => 'Failed updating student credentials.'
            ];
        }

        $attemptsTableResult = $conn->query("SHOW TABLES LIKE 'tbl_student_login_attempts'");
        if ($attemptsTableResult && $attemptsTableResult->num_rows > 0) {
            $conn->query(
                "DELETE FROM tbl_student_login_attempts WHERE examinee_number = '" .
                $conn->real_escape_string($examineeNumber) .
                "'"
            );
        }
        if ($attemptsTableResult instanceof mysqli_result) {
            $attemptsTableResult->free();
        }

        return [
            'success' => true,
            'temporary_code' => $tempCode,
            'examinee_number' => $examineeNumber
        ];
    }
}
