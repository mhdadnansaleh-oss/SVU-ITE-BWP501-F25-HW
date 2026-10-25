<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/user_helpers.php';

redirect_if_logged_in();

// Who may register is decided by an admin (admin_users.php).
//   closed   -> no form at all
//   approval -> account is created but stays inactive until an admin activates it
//   open     -> account is active immediately
// The very first account (empty users table) is always allowed and becomes the admin.
$isFirstUser = !users_exist();
$mode        = effective_registration_mode();
$becomesActive = $isFirstUser || $mode === 'open';

$errors = [];
$old    = ['username' => '', 'email' => ''];

if ($mode !== 'closed' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors['general'] = 'انتهت صلاحية الجلسة أو أن الطلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $username = trim(input_string($_POST, 'username'));
        $email    = mb_strtolower(trim(input_string($_POST, 'email')), 'UTF-8');
        $password = input_string($_POST, 'password');            // never trim passwords
        $confirm  = input_string($_POST, 'confirm_password');

        $old = ['username' => $username, 'email' => $email];

        $error = user_username_error($username);
        if ($error !== null) {
            $errors['username'] = $error;
        }

        $error = user_email_error($email);
        if ($error !== null) {
            $errors['email'] = $error;
        }

        $error = user_password_error($password);
        if ($error !== null) {
            $errors['password'] = $error;
        }

        if ($confirm === '') {
            $errors['confirm_password'] = 'يرجى تأكيد كلمة المرور.';
        } elseif (!isset($errors['password']) && !hash_equals($password, $confirm)) {
            $errors['confirm_password'] = 'كلمتا المرور غير متطابقتين.';
        }

        if (!$errors) {
            $created = false;

            try {
                $errors = user_duplicates($pdo, $username, $email);

                if (!$errors) {
                    create_user($pdo, $username, $email, $password, $isFirstUser ? 'admin' : 'staff', $becomesActive);
                    $created = true;
                }
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    // Duplicate key (two people registering at the same moment)
                    $errors['general'] = 'اسم المستخدم أو البريد الإلكتروني مسجّل مسبقًا.';
                } else {
                    error_log('Registration error: ' . $e->getMessage());
                    $errors['general'] = 'حدث خطأ غير متوقع أثناء إنشاء الحساب. يرجى المحاولة لاحقًا.';
                }
            }

            if ($created) {
                if ($isFirstUser) {
                    set_flash('success', 'تم إنشاء حساب المسؤول الأول بنجاح. يمكنك الآن تسجيل الدخول.');
                } elseif ($becomesActive) {
                    set_flash('success', 'تم إنشاء حسابك بنجاح. يمكنك الآن تسجيل الدخول.');
                } else {
                    set_flash('info', 'تم استلام طلب التسجيل بنجاح. سيتم تفعيل حسابك بعد موافقة مسؤول النظام.');
                }
                redirect('login.php');
            }
        }
    }
}

render_header('إنشاء حساب جديد');
?>
<main class="auth-wrapper">
    <div class="card auth-card">
        <div class="card-body p-4 p-md-5">

        <?php if ($mode === 'closed'): ?>

            <div class="text-center">
                <div class="brand-badge"><i class="bi bi-lock-fill"></i></div>
                <h1 class="h4 fw-bold mb-2">التسجيل مغلق حاليًا</h1>
                <p class="text-muted">
                    لا يمكن إنشاء حسابات جديدة بشكل مباشر. يرجى التواصل مع مسؤول النظام للحصول على حساب.
                </p>
                <a href="login.php" class="btn btn-primary px-4">
                    <i class="bi bi-box-arrow-in-right me-1"></i>العودة إلى تسجيل الدخول
                </a>
            </div>

        <?php else: ?>

            <div class="text-center mb-4">
                <div class="brand-badge"><i class="bi bi-mortarboard-fill"></i></div>
                <h1 class="h4 fw-bold mb-1">إنشاء حساب جديد</h1>
                <p class="text-muted mb-0">أدخل بياناتك لإنشاء حسابك في <?= e(APP_NAME) ?>.</p>
            </div>

            <?php if ($isFirstUser): ?>
                <div class="alert alert-info" role="alert">
                    هذا هو أول حساب في النظام، وسيتم إنشاؤه بصلاحيات <strong>مسؤول</strong>.
                </div>
            <?php elseif ($mode === 'approval'): ?>
                <div class="alert alert-info" role="alert">
                    تتطلب الحسابات الجديدة موافقة مسؤول النظام قبل تفعيلها.
                </div>
            <?php endif; ?>

            <?php if (isset($errors['general'])): ?>
                <div class="alert alert-danger" role="alert"><?= e($errors['general']) ?></div>
            <?php endif; ?>

            <form method="post" action="register.php" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold">اسم المستخدم</label>
                    <input type="text"
                           class="form-control<?= is_invalid($errors, 'username') ?>"
                           id="username" name="username"
                           value="<?= e($old['username']) ?>"
                           maxlength="50" autocomplete="username" required>
                    <?= field_feedback($errors, 'username') ?>
                    <div class="form-text">من 3 إلى 50 حرفًا: حروف وأرقام والرمزان (_) و(.) فقط.</div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold">البريد الإلكتروني</label>
                    <input type="email" dir="ltr"
                           class="form-control<?= is_invalid($errors, 'email') ?>"
                           id="email" name="email"
                           value="<?= e($old['email']) ?>"
                           placeholder="name@example.com"
                           maxlength="255" autocomplete="email" required>
                    <?= field_feedback($errors, 'email') ?>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold">كلمة المرور</label>
                    <div class="input-group has-validation">
                        <input type="password"
                               class="form-control<?= is_invalid($errors, 'password') ?>"
                               id="password" name="password"
                               autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary" type="button"
                                data-toggle-password="password" aria-label="إظهار كلمة المرور">
                            <i class="bi bi-eye"></i>
                        </button>
                        <?= field_feedback($errors, 'password') ?>
                    </div>
                    <div class="form-text">8 أحرف على الأقل، وتتضمن حرفًا ورقمًا.</div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label fw-semibold">تأكيد كلمة المرور</label>
                    <div class="input-group has-validation">
                        <input type="password"
                               class="form-control<?= is_invalid($errors, 'confirm_password') ?>"
                               id="confirm_password" name="confirm_password"
                               autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary" type="button"
                                data-toggle-password="confirm_password" aria-label="إظهار كلمة المرور">
                            <i class="bi bi-eye"></i>
                        </button>
                        <?= field_feedback($errors, 'confirm_password') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                    <i class="bi bi-person-plus me-1"></i><?= $mode === 'approval' && !$isFirstUser ? 'إرسال طلب التسجيل' : 'إنشاء الحساب' ?>
                </button>
            </form>

            <p class="text-center text-muted mt-4 mb-0">
                لديك حساب بالفعل؟
                <a href="login.php" class="fw-semibold text-decoration-none">تسجيل الدخول</a>
            </p>

        <?php endif; ?>

        </div>
    </div>
</main>
<?php render_footer(); ?>
