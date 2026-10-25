<?php
declare(strict_types=1);

/**
 * user_helpers.php - account rules shared by register.php, admin_users.php and
 * profile.php, so there is exactly ONE username / email / password policy.
 */

require_once __DIR__ . '/auth.php';

const USER_ROLES = ['admin', 'staff'];

function user_role_label(string $role): string
{
    return $role === 'admin' ? 'مسؤول' : 'موظف';
}

function registration_mode_label(string $mode): string
{
    switch ($mode) {
        case 'open':
            return 'مفتوح للجميع';
        case 'approval':
            return 'بموافقة المسؤول';
        default:
            return 'مغلق';
    }
}

// ---------------------------------------------------------------
// Field rules - each returns an Arabic error message, or null when valid
// ---------------------------------------------------------------

function user_username_error(string $username): ?string
{
    // "@" is not allowed so a username can never be mistaken for an email at login.
    $length = mb_strlen($username, 'UTF-8');

    if ($username === '') {
        return 'اسم المستخدم مطلوب.';
    }
    if ($length < 3 || $length > 50) {
        return 'يجب أن يتراوح طول اسم المستخدم بين 3 و50 حرفًا.';
    }
    if (!preg_match('/^[\p{L}\p{N}_.]+$/u', $username)) {
        return 'يُسمح فقط بالحروف والأرقام والشرطة السفلية (_) والنقطة (.).';
    }
    return null;
}

function user_email_error(string $email): ?string
{
    if ($email === '') {
        return 'البريد الإلكتروني مطلوب.';
    }
    if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'يرجى إدخال بريد إلكتروني صحيح.';
    }
    return null;
}

/** The password policy: 8+ characters, at least one letter and one digit, max 72 bytes. */
function user_password_error(string $password): ?string
{
    if ($password === '') {
        return 'كلمة المرور مطلوبة.';
    }
    if (mb_strlen($password, 'UTF-8') < 8) {
        return 'يجب ألا تقل كلمة المرور عن 8 أحرف.';
    }
    if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
        return 'يجب أن تحتوي كلمة المرور على حرف واحد على الأقل ورقم واحد على الأقل.';
    }
    if (strlen($password) > 72) {
        // bcrypt only uses the first 72 bytes
        return 'كلمة المرور طويلة جدًا. الحد الأقصى 72 بايت.';
    }
    return null;
}

/** Already-used username / email (case-insensitive). Returns [field => message]. */
function user_duplicates(PDO $pdo, string $username, string $email): array
{
    $errors = [];

    $stmt = $pdo->prepare('SELECT username, email FROM users WHERE username = :username OR email = :email');
    $stmt->execute([':username' => $username, ':email' => $email]);

    foreach ($stmt->fetchAll() as $row) {
        if (mb_strtolower((string) $row['username'], 'UTF-8') === mb_strtolower($username, 'UTF-8')) {
            $errors['username'] = 'اسم المستخدم مستخدم مسبقًا. يرجى اختيار اسم آخر.';
        }
        if (mb_strtolower((string) $row['email'], 'UTF-8') === mb_strtolower($email, 'UTF-8')) {
            $errors['email'] = 'هذا البريد الإلكتروني مسجّل مسبقًا.';
        }
    }

    return $errors;
}

/** Insert a user with a hashed password. Throws PDOException (23000 = duplicate). */
function create_user(
    PDO $pdo,
    string $username,
    string $email,
    string $password,
    string $role = 'staff',
    bool $active = true
): void {
    if (!in_array($role, USER_ROLES, true)) {
        $role = 'staff';
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (username, email, password, role, is_active)
         VALUES (:username, :email, :password, :role, :active)'
    );
    $insert->execute([
        ':username' => $username,
        ':email'    => $email,
        ':password' => password_hash($password, PASSWORD_ALGO, PASSWORD_OPTIONS),
        ':role'     => $role,
        ':active'   => $active ? 1 : 0,
    ]);
}
