<?php
require_once '../../config/db.php';
session_start();

header('Content-Type: application/json');


if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator' || !isset($_SESSION['accountid'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$admin_id = (int) $_SESSION['accountid'];
$batch_id = uniqid('PLT_', true);

/**
 * Ensure tbl_placement_results has the extended yearly-upload schema
 * without breaking existing columns used by current operations.
 */
function has_column(mysqli $conn, $table, $column)
{
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'";
    $res = $conn->query($sql);
    return ($res && $res->num_rows > 0);
}

function ensure_column(mysqli $conn, $table, $column, $definition)
{
    if (has_column($conn, $table, $column)) {
        return;
    }
    $tableEsc = $conn->real_escape_string($table);
    $sql = "ALTER TABLE `{$tableEsc}` ADD COLUMN `{$column}` {$definition}";
    if (!$conn->query($sql)) {
        throw new Exception('Failed to add column ' . $column . ': ' . $conn->error);
    }
}

function has_index(mysqli $conn, $table, $indexName)
{
    $tableEsc = $conn->real_escape_string($table);
    $indexEsc = $conn->real_escape_string($indexName);
    $sql = "SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$indexEsc}'";
    $res = $conn->query($sql);
    return ($res && $res->num_rows > 0);
}

function ensure_index(mysqli $conn, $table, $indexName, $definition)
{
    if (has_index($conn, $table, $indexName)) {
        return;
    }
    $tableEsc = $conn->real_escape_string($table);
    $sql = "ALTER TABLE `{$tableEsc}` ADD INDEX `{$indexName}` {$definition}";
    if (!$conn->query($sql)) {
        throw new Exception('Failed to add index ' . $indexName . ': ' . $conn->error);
    }
}

function execute_or_throw(mysqli $conn, $sql, $context)
{
    if (!$conn->query($sql)) {
        throw new Exception($context . ': ' . $conn->error);
    }
}

try {
    ensure_column($conn, 'tbl_placement_results', 'preferred_program', "VARCHAR(255) DEFAULT NULL AFTER `qualitative_code`");
    ensure_column($conn, 'tbl_placement_results', 'english_standard_score', "INT(11) DEFAULT NULL AFTER `preferred_program`");
    ensure_column($conn, 'tbl_placement_results', 'english_stanine', "TINYINT(4) DEFAULT NULL AFTER `english_standard_score`");
    ensure_column($conn, 'tbl_placement_results', 'english_qualitative_text', "VARCHAR(50) DEFAULT NULL AFTER `english_stanine`");
    ensure_column($conn, 'tbl_placement_results', 'science_standard_score', "INT(11) DEFAULT NULL AFTER `english_qualitative_text`");
    ensure_column($conn, 'tbl_placement_results', 'science_stanine', "TINYINT(4) DEFAULT NULL AFTER `science_standard_score`");
    ensure_column($conn, 'tbl_placement_results', 'science_qualitative_text', "VARCHAR(50) DEFAULT NULL AFTER `science_stanine`");
    ensure_column($conn, 'tbl_placement_results', 'mathematics_standard_score', "INT(11) DEFAULT NULL AFTER `science_qualitative_text`");
    ensure_column($conn, 'tbl_placement_results', 'mathematics_stanine', "TINYINT(4) DEFAULT NULL AFTER `mathematics_standard_score`");
    ensure_column($conn, 'tbl_placement_results', 'mathematics_qualitative_text', "VARCHAR(50) DEFAULT NULL AFTER `mathematics_stanine`");
    ensure_column($conn, 'tbl_placement_results', 'filipino_standard_score', "INT(11) DEFAULT NULL AFTER `mathematics_qualitative_text`");
    ensure_column($conn, 'tbl_placement_results', 'filipino_stanine', "TINYINT(4) DEFAULT NULL AFTER `filipino_standard_score`");
    ensure_column($conn, 'tbl_placement_results', 'filipino_qualitative_text', "VARCHAR(50) DEFAULT NULL AFTER `filipino_stanine`");
    ensure_column($conn, 'tbl_placement_results', 'social_studies_standard_score', "INT(11) DEFAULT NULL AFTER `filipino_qualitative_text`");
    ensure_column($conn, 'tbl_placement_results', 'social_studies_stanine', "TINYINT(4) DEFAULT NULL AFTER `social_studies_standard_score`");
    ensure_column($conn, 'tbl_placement_results', 'social_studies_qualitative_text', "VARCHAR(50) DEFAULT NULL AFTER `social_studies_stanine`");
    ensure_column($conn, 'tbl_placement_results', 'esm_competency_standard_score', "INT(11) DEFAULT NULL AFTER `social_studies_qualitative_text`");
    ensure_column($conn, 'tbl_placement_results', 'esm_competency_stanine', "TINYINT(4) DEFAULT NULL AFTER `esm_competency_standard_score`");
    ensure_column($conn, 'tbl_placement_results', 'esm_competency_qualitative_text', "VARCHAR(50) DEFAULT NULL AFTER `esm_competency_stanine`");
    ensure_column($conn, 'tbl_placement_results', 'overall_standard_score', "INT(11) DEFAULT NULL AFTER `esm_competency_qualitative_text`");
    ensure_column($conn, 'tbl_placement_results', 'overall_stanine', "TINYINT(4) DEFAULT NULL AFTER `overall_standard_score`");
    ensure_column($conn, 'tbl_placement_results', 'overall_qualitative_text', "VARCHAR(50) DEFAULT NULL AFTER `overall_stanine`");

    // Search/performance indexes used by dashboard and student fetching.
    ensure_index($conn, 'tbl_placement_results', 'idx_batch_sat', "(`upload_batch_id`, `sat_score`)");
    ensure_index($conn, 'tbl_placement_results', 'idx_batch_examinee', "(`upload_batch_id`, `examinee_number`)");
    ensure_index($conn, 'tbl_placement_results', 'idx_batch_name', "(`upload_batch_id`, `full_name`(120))");
    ensure_index($conn, 'tbl_placement_results', 'idx_created_at', "(`created_at`)");
} catch (Exception $schemaEx) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Schema update failed: ' . $schemaEx->getMessage()
    ]);
    exit;
}

$conn->begin_transaction();

try {

    /**
     * New yearly cycle reset:
     * wipe all transaction tables before creating the new upload batch.
     */
    execute_or_throw($conn, "DELETE FROM tbl_score_audit_logs", 'Failed clearing score audit logs');
    execute_or_throw($conn, "DELETE FROM tbl_interview_scores", 'Failed clearing interview scores');
    execute_or_throw($conn, "DELETE FROM tbl_student_transfer_history", 'Failed clearing transfer history');
    execute_or_throw($conn, "DELETE FROM tbl_student_interview", 'Failed clearing student interviews');
    execute_or_throw($conn, "DELETE FROM tbl_placement_results", 'Failed clearing placement results');
    execute_or_throw($conn, "DELETE FROM tbl_placement_upload_batches", 'Failed clearing upload batches');

    $stmt = $conn->prepare("
        INSERT INTO tbl_placement_upload_batches
        (batch_id, total_rows, inserted_rows, duplicate_rows, error_rows, status, uploaded_by)
        VALUES (?, 0, 0, 0, 0, 'processing', ?)
    ");
    $stmt->bind_param("si", $batch_id, $admin_id);
    $stmt->execute();

    $conn->commit();

    echo json_encode([
        'success'  => true,
        'batch_id' => $batch_id
    ]);

} catch (Exception $e) {

    $conn->rollback();

    echo json_encode([
        'success' => false,
        'message' => 'Failed to start upload'
    ]);
}
