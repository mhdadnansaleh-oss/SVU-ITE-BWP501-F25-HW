<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// 1) Clear all session data
$_SESSION = [];

// 2) Delete the session cookie in the browser
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

// 3) Destroy the session on the server
session_destroy();

// 4) Start a fresh session only to carry the confirmation message
session_start();
session_regenerate_id(true);
set_flash('success', 'تم تسجيل خروجك بنجاح.');

redirect('login.php');
