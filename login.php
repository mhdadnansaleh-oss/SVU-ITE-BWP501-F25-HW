<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

redirect_if_logged_in();

$error      = null;
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'انتهت صلاحية الجلسة أو أن الطلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $identifier = trim(input_string($_POST, 'identifier'));
        $password   = input_string($_POST, 'password');

        if ($identifier === '' || $password === '') {
            $error = 'يرجى إدخال اسم المستخدم أو البريد الإلكتروني وكلمة المرور.';
        } else {
            try {
                // Two distinct placeholders: with real prepared statements
                // (ATTR_EMULATE_PREPARES = false) one name cannot be reused.
                $stmt = $pdo->prepare(
                    'SELECT id, username, email, password, role, is_active
                       FROM users
                      WHERE username = :username OR email = :email
                      LIMIT 1'
                );
                $stmt->execute([
                    ':username' => $identifier,
                    ':email'    => mb_strtolower($identifier, 'UTF-8'),
                ]);
                $user = $stmt->fetch();

                // Always run password_verify(), even for unknown accounts,
                // so timing does not reveal whether the account exists.
                $hashToCheck = $user ? $user['password'] : DUMMY_HASH;
                $passwordOk  = password_verify($password, $hashToCheck);

                if ($user && $passwordOk) {

                    if ((int) $user['is_active'] !== 1) {
                        // Only said AFTER the password was correct, so this message
                        // cannot be used to discover which accounts exist.
                        $error = 'حسابك غير مفعّل بعد أو تم تعطيله. يرجى التواصل مع مسؤول النظام.';
                    } else {
                        $storedHash = (string) $user['password'];

                        // Upgrade the stored hash if the algorithm/cost changed.
                        if (password_needs_rehash($storedHash, PASSWORD_ALGO, PASSWORD_OPTIONS)) {
                            $storedHash = password_hash($password, PASSWORD_ALGO, PASSWORD_OPTIONS);
                            $upgrade = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                            $upgrade->execute([':password' => $storedHash, ':id' => $user['id']]);
                        }

                        // Prevent session fixation: new session ID + fresh CSRF token.
                        session_regenerate_id(true);
                        unset($_SESSION['csrf_token']);

                        $_SESSION['user_id']  = (int) $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role']     = $user['role'];
                        // Ties this session to the current password (see load_current_user()).
                        $_SESSION['auth_fp']  = password_fingerprint($storedHash);

                        redirect('dashboard.php');
                    }

                } else {
                    // Same message for "no such user" and "wrong password".
                    $error = 'اسم المستخدم أو البريد الإلكتروني أو كلمة المرور غير صحيحة.';
                }

            } catch (PDOException $e) {
                error_log('Login error: ' . $e->getMessage());
                $error = 'حدث خطأ غير متوقع أثناء تسجيل الدخول. يرجى المحاولة لاحقًا.';
            }
        }
    }
}

$registrationOpen = effective_registration_mode() !== 'closed';

render_header('تسجيل الدخول');
?>
<main class="auth-wrapper">
    <div class="card auth-card">
        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">
                <div class="brand-badge"><i class="bi bi-mortarboard-fill"></i></div>
                <h1 class="h4 fw-bold mb-1">تسجيل الدخول</h1>
                <p class="text-muted mb-0">مرحبًا بك، أدخل بياناتك للوصول إلى حسابك.</p>
            </div>

            <?php render_flash(); ?>

            <?php if ($error !== null): ?>
                <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="identifier" class="form-label fw-semibold">اسم المستخدم أو البريد الإلكتروني</label>
                    <input type="text"
                           class="form-control"
                           id="identifier" name="identifier"
                           value="<?= e($identifier) ?>"
                           maxlength="255" autocomplete="username" autofocus required>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">كلمة المرور</label>
                    <div class="input-group">
                        <input type="password"
                               class="form-control"
                               id="password" name="password"
                               autocomplete="current-password" required>
                        <button class="btn btn-outline-secondary" type="button"
                                data-toggle-password="password" aria-label="إظهار كلمة المرور">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                    <i class="bi bi-box-arrow-in-right me-1"></i>تسجيل الدخول
                </button>
            </form>

            <p class="text-center text-muted mt-4 mb-0">
                <?php if ($registrationOpen): ?>
                    ليس لديك حساب؟
                    <a href="register.php" class="fw-semibold text-decoration-none">إنشاء حساب جديد</a>
                <?php else: ?>
                    للحصول على حساب، يرجى التواصل مع مسؤول النظام.
                <?php endif; ?>
            </p>
        </div>
    </div>
</main>
<?php render_footer(); ?>
