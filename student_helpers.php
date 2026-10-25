<?php
declare(strict_types=1);

/**
 * student_helpers.php - validation, secure upload handling and list-state
 * helpers shared by the student management pages.
 *
 * Requires PHP 7.4+ and the "fileinfo" extension (enabled by default).
 */

require_once __DIR__ . '/auth.php';

// ---------------------------------------------------------------
// Settings
// ---------------------------------------------------------------
const STUDENT_FIELDS     = ['name', 'email', 'phone', 'address'];
const SORTABLE_COLUMNS   = ['name', 'email', 'phone', 'created_at'];
const DEFAULT_SORT       = 'created_at';
const DEFAULT_DIR        = 'desc';
const STUDENTS_PER_PAGE  = 10;

const UPLOAD_DIR         = __DIR__ . '/uploads';   // physical folder
const UPLOAD_URL_PREFIX  = 'uploads/';             // path saved in the database
const MAX_UPLOAD_BYTES   = 2 * 1024 * 1024;        // 2 MB
const MAX_IMAGE_SIDE     = 6000;                   // pixels (guards against huge images)
const ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// ---------------------------------------------------------------
// Small input helpers (input_string() and positive_int() now live in auth.php)
// ---------------------------------------------------------------

/** Convert Arabic-Indic and Persian digits to Western digits. */
function normalize_digits(string $value): string
{
    return strtr($value, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
}

// ---------------------------------------------------------------
// Field cleaning + validation (used by the form AND the AJAX endpoint)
// ---------------------------------------------------------------

function clean_student_value(string $field, string $value): string
{
    $value = trim($value);

    switch ($field) {
        case 'name':
            return trim((string) preg_replace('/\s+/u', ' ', $value));
        case 'email':
            return mb_strtolower($value, 'UTF-8');
        case 'phone':
            return trim((string) preg_replace('/\s+/u', ' ', normalize_digits($value)));
        default:
            return $value;
    }
}

/** Clean all text fields from a request array. */
function clean_student_input(array $source): array
{
    $clean = [];
    foreach (STUDENT_FIELDS as $field) {
        $clean[$field] = clean_student_value($field, input_string($source, $field));
    }
    return $clean;
}

/**
 * Validate one field. Returns an Arabic error message, or null when valid.
 * $ignoreId is the student being edited (so their own email is not a duplicate).
 */
function student_field_error(PDO $pdo, string $field, string $value, ?int $ignoreId = null): ?string
{
    $value  = clean_student_value($field, $value);
    $length = mb_strlen($value, 'UTF-8');

    switch ($field) {
        case 'name':
            if ($value === '') {
                return 'اسم الطالب مطلوب.';
            }
            if ($length < 2 || $length > 100) {
                return 'يجب أن يتراوح طول الاسم بين 2 و100 حرف.';
            }
            if (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'\-]*$/u', $value)) {
                return 'يجب أن يتكوّن الاسم من حروف فقط، ويُسمح بالمسافة والنقطة والشرطة.';
            }
            return null;

        case 'email':
            if ($value === '') {
                return 'البريد الإلكتروني مطلوب.';
            }
            if (strlen($value) > 255 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return 'يرجى إدخال بريد إلكتروني صحيح.';
            }
            $stmt = $pdo->prepare('SELECT id FROM students WHERE email = :email AND id <> :id LIMIT 1');
            $stmt->execute([':email' => $value, ':id' => $ignoreId ?? 0]);
            if ($stmt->fetchColumn() !== false) {
                return 'هذا البريد الإلكتروني مسجّل لطالب آخر.';
            }
            return null;

        case 'phone':
            if ($value === '') {
                return 'رقم الهاتف مطلوب.';
            }
            if (!preg_match('/^\+?[0-9 \-()]+$/', $value)) {
                return 'يجب أن يحتوي رقم الهاتف على أرقام فقط، ويمكن أن يبدأ بالرمز (+).';
            }
            $digits = preg_match_all('/[0-9]/', $value);
            if ($digits < 7 || $digits > 15) {
                return 'يجب أن يتراوح عدد أرقام الهاتف بين 7 و15 رقمًا.';
            }
            if ($length > 20) {
                return 'يجب ألا يزيد طول رقم الهاتف عن 20 خانة.';
            }
            return null;

        case 'address':
            // Optional field
            if ($length > 500) {
                return 'يجب ألا يزيد العنوان عن 500 حرف.';
            }
            return null;
    }

    return 'حقل غير معروف.';
}

/** Validate every text field. Returns [field => message]. */
function validate_student_form(PDO $pdo, array $values, ?int $ignoreId = null): array
{
    $errors = [];
    foreach (STUDENT_FIELDS as $field) {
        $error = student_field_error($pdo, $field, $values[$field] ?? '', $ignoreId);
        if ($error !== null) {
            $errors[$field] = $error;
        }
    }
    return $errors;
}

// ---------------------------------------------------------------
// Profile picture upload
// ---------------------------------------------------------------

/** True when the user actually chose a file. */
function upload_present(array $file): bool
{
    return !isset($file['error']) || $file['error'] !== UPLOAD_ERR_NO_FILE;
}

/** Validate an uploaded image. Returns an Arabic error message, or null when valid. */
function validate_profile_picture(array $file): ?string
{
    $sizeMessage    = 'حجم الصورة يتجاوز الحد المسموح به (2 ميغابايت).';
    $genericMessage = 'تعذّر رفع الصورة. يرجى المحاولة مرة أخرى.';

    if (!isset($file['error'], $file['tmp_name'], $file['size'])
        || !is_int($file['error']) || !is_string($file['tmp_name'])) {
        return $genericMessage;
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return $sizeMessage;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return $genericMessage;
    }
    if ((int) $file['size'] > MAX_UPLOAD_BYTES) {
        return $sizeMessage;
    }

    // Detect the REAL type from the file contents - never trust the
    // browser-supplied name or MIME type.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!is_string($mime) || !isset(ALLOWED_IMAGE_TYPES[$mime])) {
        return 'نوع الملف غير مسموح. الصيغ المسموحة: JPG وPNG وWEBP.';
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || ($info['mime'] ?? '') !== $mime) {
        return 'الملف المرفوع ليس صورة صالحة.';
    }
    if ($info[0] > MAX_IMAGE_SIDE || $info[1] > MAX_IMAGE_SIDE) {
        return 'أبعاد الصورة كبيرة جدًا (الحد الأقصى 6000 بكسل).';
    }

    return null;
}

/**
 * Move a validated upload into uploads/ under a random name.
 * Returns the relative path to store in the database (e.g. uploads/ab12....jpg).
 *
 * @throws RuntimeException when the file cannot be stored
 */
function save_profile_picture(array $file): string
{
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Cannot create the uploads directory.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext  = is_string($mime) ? (ALLOWED_IMAGE_TYPES[$mime] ?? null) : null;
    if ($ext === null) {
        throw new RuntimeException('Unsupported image type.');
    }

    // Random name + extension chosen by US from the detected type.
    $filename    = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('move_uploaded_file() failed.');
    }
    chmod($destination, 0644);

    return UPLOAD_URL_PREFIX . $filename;
}

/** Delete a stored picture. basename() makes path traversal impossible. */
function delete_profile_picture(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }
    $file = UPLOAD_DIR . '/' . basename($path);
    if (is_file($file)) {
        unlink($file);
    }
}

/** Return a safe URL for a stored picture, or null if the value looks wrong. */
function profile_picture_url(?string $path): ?string
{
    if ($path !== null && preg_match('#^uploads/[a-f0-9]{32}\.(?:jpg|png|webp)$#', $path)) {
        return $path;
    }
    return null;
}

/** Round avatar (picture or placeholder icon). */
function avatar_html(?string $path, string $extraClass = ''): string
{
    $url   = profile_picture_url($path);
    $inner = $url !== null
        ? '<img src="' . e($url) . '" alt="صورة الطالب" loading="lazy">'
        : '<i class="bi bi-person-fill" aria-hidden="true"></i>';

    return '<span class="avatar' . ($extraClass !== '' ? ' ' . e($extraClass) : '') . '">' . $inner . '</span>';
}

// ---------------------------------------------------------------
// List state (search / sort / page) carried between pages
// ---------------------------------------------------------------

function list_state(array $source): array
{
    $q = trim(input_string($source, 'q'));
    if (mb_strlen($q, 'UTF-8') > 100) {
        $q = mb_substr($q, 0, 100, 'UTF-8');
    }

    $sort = input_string($source, 'sort');
    if (!in_array($sort, SORTABLE_COLUMNS, true)) {
        $sort = DEFAULT_SORT;
    }

    $dir  = strtolower(input_string($source, 'dir')) === 'asc' ? 'asc' : 'desc';
    $page = positive_int(input_string($source, 'page')) ?? 1;

    return ['q' => $q, 'sort' => $sort, 'dir' => $dir, 'page' => $page];
}

/** Only non-default values go into the URL. */
function state_params(array $state): array
{
    $params = [];
    if ($state['q'] !== '') {
        $params['q'] = $state['q'];
    }
    if ($state['sort'] !== DEFAULT_SORT || $state['dir'] !== DEFAULT_DIR) {
        $params['sort'] = $state['sort'];
        $params['dir']  = $state['dir'];
    }
    if ($state['page'] > 1) {
        $params['page'] = $state['page'];
    }
    return $params;
}

function list_url(array $state): string
{
    $params = state_params($state);
    return 'view_students.php' . ($params ? '?' . http_build_query($params) : '');
}

/** URL of an action page for one student, keeping the list state. */
function action_url(string $script, int $id, array $state): string
{
    return $script . '?' . http_build_query(['id' => $id] + state_params($state));
}

// ---------------------------------------------------------------
// Search + ordering (shared by view_students.php and export_students.php,
// so the CSV always contains exactly what the list shows)
// ---------------------------------------------------------------

/**
 * WHERE clause for the search box (name, email or phone using LIKE).
 * Returns [sql, params]; sql is '' when there is no search text.
 */
function student_search_clause(string $q): array
{
    if ($q === '') {
        return ['', []];
    }

    $like = static function (string $text): string {
        // escape LIKE wildcards so "%" and "_" are searched literally
        return '%' . addcslashes($text, '\\%_') . '%';
    };

    return [
        'WHERE name LIKE :q_name OR email LIKE :q_email OR phone LIKE :q_phone',
        [
            ':q_name'  => $like($q),
            ':q_email' => $like($q),
            ':q_phone' => $like(normalize_digits($q)),   // Arabic digits work too
        ],
    ];
}

/** ORDER BY expression. Column and direction are whitelisted here as a safety net. */
function student_order_by(array $state): string
{
    $column = in_array($state['sort'] ?? '', SORTABLE_COLUMNS, true) ? $state['sort'] : DEFAULT_SORT;
    $dir    = strtoupper((string) ($state['dir'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';

    return $column . ' ' . $dir . ', id ' . $dir;
}

// ---------------------------------------------------------------
// "Export CSV" dropdown (used on the list page and the dashboard)
// ---------------------------------------------------------------

/**
 * A POST form with a CSRF token, so an export cannot be triggered by a link
 * or image on another website. Carries the current search/sort so the export
 * matches what the user is looking at.
 */
function render_export_dropdown(
    array $state = [],
    string $label = 'تصدير CSV',
    string $buttonClass = 'btn-outline-success',
    string $formClass = 'd-inline-block'
): void {
    $state = list_state($state);   // sanitise whatever was passed in
    ?>
<form method="post" action="export_students.php" class="<?= e($formClass) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="q" value="<?= e($state['q']) ?>">
    <input type="hidden" name="sort" value="<?= e($state['sort']) ?>">
    <input type="hidden" name="dir" value="<?= e($state['dir']) ?>">

    <div class="btn-group">
        <button type="button" class="btn <?= e($buttonClass) ?> dropdown-toggle"
                data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i><?= e($label) ?>
        </button>
        <ul class="dropdown-menu">
            <li>
                <button type="submit" name="delimiter" value="comma" class="dropdown-item">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>ملف CSV بفاصلة (,)
                </button>
            </li>
            <li>
                <button type="submit" name="delimiter" value="semicolon" class="dropdown-item">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>ملف CSV بفاصلة منقوطة (;)
                </button>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <span class="dropdown-item-text small text-muted">
                    إذا ظهرت البيانات في عمود واحد داخل Excel، فاستخدم الفاصلة المنقوطة.
                </span>
            </li>
        </ul>
    </div>
</form>
<?php
}
