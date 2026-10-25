<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/user_helpers.php';

require_login();

// Wrong "current password" attempts allowed before the form is locked for a while.
const PASSWORD_MAX_ATTEMPTS = 5;
const PASSWORD_LOCK_SECONDS = 900;   // 15 minutes

$user   = load_current_user();       // id, username, email, role, is_active, created_at
$userId = (int) $user['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $lockedUntil = (int) ($_SESSION['pw_lock_until'] ?? 0);

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors['general'] = 'انتهت صلاحية الجلسة أو أن الطلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';

    } elseif ($lockedUntil > time()) {
        $minutes = (int) ceil(($lockedUntil - time()) / 60);
        $errors['general'] = 'تم إيقاف تغيير كلمة المرور مؤقتًا بسبب كثرة المحاولات الخاطئة. '
                           . 'يرجى المحاولة بعد ' . $minutes . ' دقيقة.';

    } else {
        // Passwords are never trimmed or altered.
        $current = input_string($_POST, 'current_password');
        $new     = input_string($_POST, 'new_password');
        $confirm = input_string($_POST, 'confirm_password');

        if ($current === '') {
            $errors['current_password'] = 'يرجى إدخال كلمة المرور الحالية.';
        }

        $error = user_password_error($new);
        if ($error !== null) {
            $errors['new_password'] = $error;
        }

        if ($confirm === '') {
            $errors['confirm_password'] = 'يرجى تأكيد كلمة المرور الجديدة.';
        } elseif (!isset($errors['new_password']) && !hash_equals($new, $confirm)) {
            $errors['confirm_password'] = 'كلمتا المرور غير متطابقتين.';
        }

        try {
            // Verify the current password against the stored hash.
            if (!isset($errors['current_password'])) {
                $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $userId]);
                $storedHash = (string) $stmt->fetchColumn();

                if (!password_verify($current, $storedHash)) {
                    $errors['current_password'] = 'كلمة المرور الحالية غير صحيحة.';

                    $_SESSION['pw_fail'] = (int) ($_SESSION['pw_fail'] ?? 0) + 1;
                    if ($_SESSION['pw_fail'] >= PASSWORD_MAX_ATTEMPTS) {
                        $_SESSION['pw_lock_until'] = time() + PASSWORD_LOCK_SECONDS;
                        $_SESSION['pw_fail']       = 0;
                    }
                } elseif (!isset($errors['new_password']) && hash_equals($current, $new)) {
                    $errors['new_password'] = 'يجب أن تختلف كلمة المرور الجديدة عن كلمة المرور الحالية.';
                }
            }

            // Everything is valid: store the new hash.
            if (!$errors) {
                $newHash = password_hash($new, PASSWORD_ALGO, PASSWORD_OPTIONS);

                $update = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $update->execute([':password' => $newHash, ':id' => $userId]);

                // New session ID, and a new fingerprint for THIS session only: every
                // other session of this account is signed out on its next request.
                session_regenerate_id(true);
                $_SESSION['auth_fp'] = password_fingerprint($newHash);
                unset($_SESSION['pw_fail'], $_SESSION['pw_lock_until'], $_SESSION['csrf_token']);

                set_flash('success', 'تم تغيير كلمة المرور بنجاح. تم تسجيل خروج حسابك من الأجهزة الأخرى تلقائيًا.');
                redirect('profile.php');
            }
        } catch (PDOException $e) {
            error_log('Password change error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ غير متوقع أثناء تغيير كلمة المرور. يرجى المحاولة لاحقًا.';
        }
    }
}

render_header('حسابي');
render_navbar('profile');
?>
<main class="container py-4 py-md-5">

    <?php render_flash(); ?>

    <div class="mb-4">
        <h1 class="h3 fw-bold mb-1">حسابي</h1>
        <p class="text-muted mb-0">عرض بيانات حسابك وتغيير كلمة المرور.</p>
    </div>

    <div class="row g-4">

        <!-- Account information -->
        <div class="col-lg-5">
            <div class="card content-card h-100">
                <div class="card-body p-4 text-center">
                    <span class="avatar avatar-xl mb-3"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
                    <h2 class="h5 fw-bold mb-2"><?= e($user['username']) ?></h2>
                    <span class="badge <?= $user['role'] === 'admin' ? 'text-bg-primary' : 'text-bg-secondary' ?> mb-4">
                        <?= e(user_role_label((string) $user['role'])) ?>
                    </span>

                    <dl class="text-start mb-0">
                        <dt class="text-muted small">البريد الإلكتروني</dt>
                        <dd class="mb-3"><span class="bidi-ltr"><?= e($user['email']) ?></span></dd>

                        <dt class="text-muted small">الحالة</dt>
                        <dd class="mb-3"><span class="badge text-bg-success">مفعّل</span></dd>

                        <dt class="text-muted small">تاريخ إنشاء الحساب</dt>
                        <dd class="mb-0">
                            <span class="bidi-ltr"><?= e(date('Y/m/d H:i', (int) strtotime((string) $user['created_at']))) ?></span>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Change password -->
        <div class="col-lg-7">
            <div class="card content-card h-100">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h5 fw-bold mb-1">تغيير كلمة المرور</h2>
                    <p class="text-muted mb-4">أدخل كلمة المرور الحالية ثم اختر كلمة مرور جديدة.</p>

                    <?php if (isset($errors['general'])): ?>
                        <div class="alert alert-danger" role="alert"><?= e($errors['general']) ?></div>
                    <?php endif; ?>

                    <form method="post" action="profile.php" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="current_password" class="form-label fw-semibold">كلمة المرور الحالية</label>
                            <div class="input-group has-validation">
                                <input type="password"
                                       class="form-control<?= is_invalid($errors, 'current_password') ?>"
                                       id="current_password" name="current_password"
                                       autocomplete="current-password" required>
                                <button class="btn btn-outline-secondary" type="button"
                                        data-toggle-password="current_password" aria-label="إظهار كلمة المرور">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?= field_feedback($errors, 'current_password') ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-semibold">كلمة المرور الجديدة</label>
                            <div class="input-group has-validation">
                                <input type="password"
                                       class="form-control<?= is_invalid($errors, 'new_password') ?>"
                                       id="new_password" name="new_password"
                                       autocomplete="new-password" required>
                                <button class="btn btn-outline-secondary" type="button"
                                        data-toggle-password="new_password" aria-label="إظهار كلمة المرور">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?= field_feedback($errors, 'new_password') ?>
                            </div>

                            <div class="progress mt-2 d-none" style="height: 6px;" id="strength-wrap">
                                <div class="progress-bar" id="strength-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                            <div class="form-text">
                                8 أحرف على الأقل، وتتضمن حرفًا ورقمًا.
                                <span id="strength-label" class="fw-semibold"></span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password" class="form-label fw-semibold">تأكيد كلمة المرور الجديدة</label>
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

                        <button type="submit" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-key-fill me-1" aria-hidden="true"></i>تغيير كلمة المرور
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// Live password-strength hint (informational only - the server enforces the rules).
(function () {
    var input = document.getElementById('new_password');
    var wrap  = document.getElementById('strength-wrap');
    var bar   = document.getElementById('strength-bar');
    var label = document.getElementById('strength-label');
    if (!input) { return; }

    var levels = [
        { text: 'ضعيفة',  width: '25%',  cls: 'bg-danger'  },
        { text: 'مقبولة', width: '50%',  cls: 'bg-warning' },
        { text: 'جيدة',   width: '75%',  cls: 'bg-info'    },
        { text: 'قوية',   width: '100%', cls: 'bg-success' }
    ];

    input.addEventListener('input', function () {
        var v = input.value;
        if (v === '') { wrap.classList.add('d-none'); label.textContent = ''; return; }

        var score = 0;
        if (v.length >= 8) { score++; }
        if (v.length >= 12) { score++; }
        if (/\p{L}/u.test(v) && /\d/.test(v)) { score++; }
        if ((/\p{Ll}/u.test(v) && /\p{Lu}/u.test(v)) || /[^\p{L}\p{N}]/u.test(v)) { score++; }

        var level = levels[Math.max(0, score - 1)];
        wrap.classList.remove('d-none');
        bar.style.width = level.width;
        bar.className = 'progress-bar ' + level.cls;
        label.textContent = '— القوة: ' + level.text;
    });
})();
</script>
<?php render_footer(); ?>
