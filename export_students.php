<?php
declare(strict_types=1);

/**
 * export_students.php - downloads the student list as a CSV file.
 *
 *  - Signed-in users only, POST only, CSRF-protected (the export buttons are forms).
 *  - Exports ALL matching rows (not just one page) and respects the active
 *    search text and sort order from the list page.
 *  - UTF-8 with BOM, so Microsoft Excel shows Arabic correctly when the file
 *    is opened by double-click.
 *  - Rows are streamed, so large tables do not exhaust PHP memory.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/student_helpers.php';

/**
 * Write phone numbers as ="0501234567" so Excel keeps leading zeros and the "+"
 * instead of turning them into numbers. Only values made of digits, spaces,
 * "+", "-" and parentheses get this treatment, so nothing executable can be
 * smuggled in. Set to false to export plain text instead.
 */
const EXPORT_PHONE_AS_EXCEL_TEXT = true;

/** One CSV cell: always quoted, quotes doubled, formulas neutralised. */
function csv_cell(string $value): string
{
    // CSV/formula injection: a cell starting with = + - @ (or a control
    // character) can run as a formula in Excel. A leading apostrophe makes it plain text.
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r\n") !== false) {
        $value = "'" . $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
}

function csv_phone_cell(string $phone): string
{
    if (EXPORT_PHONE_AS_EXCEL_TEXT && preg_match('/^\+?[0-9 \-()]{1,20}$/', $phone)) {
        return '"=""' . $phone . '"""';          // CSV encoding of: ="0501234567"
    }
    return csv_cell($phone);
}

function csv_line(array $cells, string $delimiter): string
{
    return implode($delimiter, $cells) . "\r\n";
}

// ---------------------------------------------------------------
// Access control
// ---------------------------------------------------------------
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('view_students.php');           // direct visits (GET) just go back to the list
}

$state = list_state($_POST);                 // q / sort / dir from the list page

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    set_flash('danger', 'انتهت صلاحية الجلسة أو أن الطلب غير صالح. يرجى المحاولة مرة أخرى.');
    redirect(list_url($state));
}

$delimiters = ['comma' => ',', 'semicolon' => ';'];
$delimiter  = $delimiters[input_string($_POST, 'delimiter')] ?? ',';

// ---------------------------------------------------------------
// Run the query BEFORE sending any download headers, so a database
// problem can still be reported with a normal redirect + message.
// ---------------------------------------------------------------
[$where, $params] = student_search_clause($state['q']);

$sql = "SELECT id, name, email, phone, address, profile_picture, created_at
          FROM students
          $where
         ORDER BY " . student_order_by($state);

try {
    // Stream rows from MySQL instead of loading the whole result into memory.
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $e) {
    error_log('Export students error: ' . $e->getMessage());
    set_flash('danger', 'تعذّر تصدير الملف. يرجى المحاولة لاحقًا.');
    redirect(list_url($state));
}

// ---------------------------------------------------------------
// Send the file
// ---------------------------------------------------------------
$filename = 'students_' . ($state['q'] !== '' ? 'filtered_' : '') . date('Y-m-d_His') . '.csv';

session_write_close();                       // do not lock the session while streaming

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

fwrite($out, "\xEF\xBB\xBF");                // UTF-8 BOM: makes Excel read the file as UTF-8

$headings = ['الرقم', 'الاسم', 'البريد الإلكتروني', 'رقم الهاتف', 'العنوان', 'الصورة الشخصية', 'تاريخ التسجيل'];
fwrite($out, csv_line(array_map('csv_cell', $headings), $delimiter));

$count = 0;
while (($row = $stmt->fetch()) !== false) {
    fwrite($out, csv_line([
        csv_cell((string) $row['id']),
        csv_cell((string) $row['name']),
        csv_cell((string) $row['email']),
        csv_phone_cell((string) ($row['phone'] ?? '')),
        csv_cell((string) ($row['address'] ?? '')),
        csv_cell(($row['profile_picture'] ?? '') !== '' ? 'نعم' : 'لا'),
        csv_cell((string) $row['created_at']),
    ], $delimiter));

    if (++$count % 500 === 0) {
        flush();
    }
}

fclose($out);
exit;
