<?php
declare(strict_types=1);

/**
 * auth.php - session bootstrap, access control and helpers shared by every page.
 *
 * Protect a page with:
 *     require_once __DIR__ . '/db.php';
 *     require_once __DIR__ . '/auth.php';
 *     require_login();          // any active, signed-in user
 *     require_admin();          // admins only
 *
 * Requires PHP 7.4+.
 */

// ---------------------------------------------------------------
// Basic security headers
// ---------------------------------------------------------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------------------------------------------------------------
// Settings
// ---------------------------------------------------------------

// Password hashing (used by registration, login, profile and admin pages).
// A fixed cost keeps hashing time consistent across PHP versions.
const PASSWORD_ALGO    = PASSWORD_BCRYPT;
const PASSWORD_OPTIONS = ['cost' => 12];

// Well-formed bcrypt hash (same cost) that matches nothing. login.php verifies
// against it when the account does not exist, so response time does not reveal
// whether a username/email is registered.
const DUMMY_HASH = '$2y$12$BCryptRequires22Chrcte/VlQH0piJtjXl.0t1XkA8pw9dMXTpOq';

// Who may create an account at register.php (stored in the `settings` table).
//   closed   - nobody; only an admin creates accounts   (default, safest)
//   approval - anyone may apply, an admin must activate the account
//   open     - anyone may register and sign in at once
const REGISTRATION_MODES = ['closed', 'approval', 'open'];

// ---------------------------------------------------------------
// Secure session start
// ---------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,          // cookie lasts until the browser closes
        'path'     => '/',
        'secure'   => $isHttps,   // only sent over HTTPS when available
        'httponly' => true,       // not readable from JavaScript
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ---------------------------------------------------------------
// General helpers
// ---------------------------------------------------------------

/** Escape a value for safe output inside HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Read a request value as a string (arrays and missing keys become ''). */
function input_string(array $source, string $key): string
{
    return (isset($source[$key]) && is_string($source[$key])) ? $source[$key] : '';
}

/** Return a positive integer from a request value, or null. */
function positive_int($value): ?int
{
    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
        return (int) $value;
    }
    return null;
}

/** Get (or create) the CSRF token for the current session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to place inside every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Check a submitted CSRF token. */
function csrf_verify($token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** One-time messages shown after a redirect. Types: success, danger, warning, info. */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// ---------------------------------------------------------------
// Database access (reuses the $pdo created by db.php)
// ---------------------------------------------------------------
function auth_pdo(): PDO
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        require_once __DIR__ . '/db.php';   // creates $pdo (bound to the global above)
    }
    return $pdo;
}

// ---------------------------------------------------------------
// Session lifecycle
// ---------------------------------------------------------------

/**
 * A fingerprint of the stored password hash. It is kept in the session, so when
 * the password changes every OTHER session of that user stops being valid.
 */
function password_fingerprint(string $passwordHash): string
{
    return hash('sha256', $passwordHash);
}

/** Wipe the session data, the browser cookie and the server-side session. */
function destroy_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/** Start a brand-new session (used after destroy_session() to carry a message). */
function restart_session(): void
{
    session_start();
    session_regenerate_id(true);
}

// ---------------------------------------------------------------
// Authentication / authorisation
// ---------------------------------------------------------------

/**
 * Check the session against the database on every request. The session is only
 * accepted while the account still exists, is active, and the password has not
 * been changed since login. Role and username are refreshed from the database,
 * so an admin's changes (deactivate, role change) take effect immediately.
 *
 * Returns the user row (without the password hash) or null.
 */
function load_current_user(): ?array
{
    static $resolved = false;
    static $user     = null;

    if ($resolved) {
        return $user;
    }
    $resolved = true;

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    try {
        $stmt = auth_pdo()->prepare(
            'SELECT id, username, email, role, is_active, created_at, password
               FROM users
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => (int) $_SESSION['user_id']]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Session check failed: ' . $e->getMessage());
        return null;                       // fail closed
    }

    $reason = null;
    if (!$row || (int) $row['is_active'] !== 1) {
        $reason = 'هذا الحساب غير متاح حاليًا. يرجى التواصل مع مسؤول النظام.';
    } elseif (!isset($_SESSION['auth_fp']) || !is_string($_SESSION['auth_fp'])
        || !hash_equals(password_fingerprint((string) $row['password']), $_SESSION['auth_fp'])) {
        $reason = 'انتهت صلاحية الجلسة أو تم تغيير كلمة المرور. يرجى تسجيل الدخول مرة أخرى.';
    }

    if ($reason !== null) {
        destroy_session();
        restart_session();
        set_flash('warning', $reason);
        return null;
    }

    unset($row['password']);
    $_SESSION['username'] = $row['username'];
    $_SESSION['role']     = $row['role'];

    $user = $row;
    return $user;
}

/** Cheap check: does the session claim a login? (Use load_current_user() for the real check.) */
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

/** Use at the top of any page that requires an authenticated, active user. */
function require_login(): void
{
    // Stop the browser from showing protected pages from cache after logout.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (load_current_user() === null) {
        if (!isset($_SESSION['flash'])) {        // keep a more specific message if one was set
            set_flash('warning', 'يرجى تسجيل الدخول للوصول إلى هذه الصفحة.');
        }
        redirect('login.php');
    }
}

/** Use on pages only administrators may open. */
function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        set_flash('danger', 'ليست لديك صلاحية للوصول إلى هذه الصفحة.');
        redirect('dashboard.php');
    }
}

/** Use on login/register so signed-in users are sent to the dashboard. */
function redirect_if_logged_in(): void
{
    if (load_current_user() !== null) {
        redirect('dashboard.php');
    }
}

// ---------------------------------------------------------------
// Registration policy
// ---------------------------------------------------------------

/** True once at least one account exists. */
function users_exist(): bool
{
    static $exists = null;

    if ($exists === null) {
        try {
            $exists = (int) auth_pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log('users_exist failed: ' . $e->getMessage());
            $exists = true;                // fail closed
        }
    }
    return $exists;
}

/** The mode stored in the settings table. Anything missing or invalid means "closed". */
function registration_mode(): string
{
    static $mode = null;

    if ($mode === null) {
        $mode = 'closed';
        try {
            $stmt = auth_pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = :key');
            $stmt->execute([':key' => 'registration_mode']);
            $value = $stmt->fetchColumn();
            if (is_string($value) && in_array($value, REGISTRATION_MODES, true)) {
                $mode = $value;
            }
        } catch (PDOException $e) {
            error_log('registration_mode failed: ' . $e->getMessage());
        }
    }
    return $mode;
}

/**
 * The mode that applies right now. While there are no accounts at all, the very
 * first registration is allowed (and becomes the administrator), otherwise a
 * fresh install could never be set up.
 */
function effective_registration_mode(): string
{
    return users_exist() ? registration_mode() : 'open';
}
