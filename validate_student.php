<?php
declare(strict_types=1);

/**
 * validate_student.php - real-time (AJAX) validation of ONE form field.
 *
 * POST: field, value, student_id (optional, when editing), csrf_token
 * Reply: {"valid": true|false, "message": "..."}
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/student_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function json_reply(bool $valid, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['valid' => $valid, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    json_reply(false, 'انتهت جلستك. يرجى تسجيل الدخول مرة أخرى.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_reply(false, 'طريقة الطلب غير مسموح بها.', 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    json_reply(false, 'الطلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.', 403);
}

$field = input_string($_POST, 'field');
if (!in_array($field, STUDENT_FIELDS, true)) {
    json_reply(false, 'حقل غير معروف.', 400);
}

$studentId = positive_int(input_string($_POST, 'student_id'));

try {
    $error = student_field_error($pdo, $field, input_string($_POST, 'value'), $studentId);
} catch (PDOException $e) {
    error_log('Field validation error: ' . $e->getMessage());
    json_reply(false, 'تعذّر التحقق من الحقل حاليًا. يرجى المحاولة لاحقًا.', 500);
}

json_reply($error === null, $error ?? '');
