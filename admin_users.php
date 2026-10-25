<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/user_helpers.php';

require_admin();                         // administrators only

$me   = load_current_user();
$myId = (int) $me['id'];

$errors = [];
$form   = ['username' => '', 'email' => '', 'role' => 'staff'];

/** Load one user (id, username, role, is_active) or null. */
function fetch_user(PDO $pdo, ?int $id): ?array
{
    if ($id === null) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, username, role, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------
// Actions (all POST + CSRF, then redirect back)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'انتهت صلاحية الجلسة أو أن الطلب غير صالح. يرجى المحاولة مرة أخرى.');
        redirect('admin_users.php');
    }

    $action = input_string($_POST, 'action');

    try {
        // ---- 1) who may register ----
        if ($action === 'set_mode') {
            $mode = input_string($_POST, 'registration_mode');

            if (!in_array($mode, REGISTRATION_MODES, true)) {
                set_flash('danger', 'خيار التسجيل غير صالح.');
            } else {
                $save = $pdo->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (:key, :value)');
                $save->execute([':key' => 'registration_mode', ':value' => $mode]);
                set_flash('success', 'تم حفظ إعداد التسجيل: ' . registration_mode_label($mode) . '.');
            }
            redirect('admin_users.php');
        }

        // ---- 2) create an account (admin-only registration) ----
        if ($action === 'create_user') {
            $form = [
                'username' => trim(input_string($_POST, 'username')),
                'email'    => mb_strtolower(trim(input_string($_POST, 'email')), 'UTF-8'),
                'role'     => input_string($_POST, 'role'),
            ];
            if (!in_array($form['role'], USER_ROLES, true)) {
                $form['role'] = 'staff';
            }
            $password = input_string($_POST, 'password');
            $confirm  = input_string($_POST, 'confirm_password');

            $error = user_username_error($form['username']);
            if ($error !== null) {
                $errors['username'] = $error;
            }
            $error = user_email_error($form['email']);
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
                $errors = user_duplicates($pdo, $form['username'], $form['email']);
            }

            if (!$errors) {
                create_user($pdo, $form['username'], $form['email'], $password, $form['role'], true);
                set_flash('success', 'تم إنشاء حساب «' . $form['username'] . '» بنجاح.');
                redirect('admin_users.php');
            }
            // otherwise fall through and show the form with its errors
        }

        // ---- 3) actions on an existing user ----
        elseif (in_array($action, ['set_active', 'set_role', 'delete_user'], true)) {
            $target = fetch_user($pdo, positive_int(input_string($_POST, 'user_id')));

            if ($target === null) {
                set_flash('danger', 'المستخدم المطلوب غير موجود.');
            } elseif ((int) $target['id'] === $myId) {
                // Stops an admin from locking themselves out (also guarantees one active admin remains).
                set_flash('danger', 'لا يمكنك تنفيذ هذا الإجراء على حسابك الشخصي.');
            } elseif ($action === 'set_active') {
                $active = input_string($_POST, 'active') === '1' ? 1 : 0;
                $stmt = $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
                $stmt->execute([':active' => $active, ':id' => $target['id']]);
                set_flash('success', ($active ? 'تم تفعيل حساب «' : 'تم تعطيل حساب «') . $target['username'] . '».');
            } elseif ($action === 'set_role') {
                $role = input_string($_POST, 'role');
                if (!in_array($role, USER_ROLES, true)) {
                    set_flash('danger', 'الدور المطلوب غير صالح.');
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
                    $stmt->execute([':role' => $role, ':id' => $target['id']]);
                    set_flash('success', 'تم تغيير دور «' . $target['username'] . '» إلى ' . user_role_label($role) . '.');
                }
            } else {   // delete_user
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $stmt->execute([':id' => $target['id']]);
                set_flash('success', 'تم حذف المستخدم «' . $target['username'] . '».');
            }
            redirect('admin_users.php');
        }

        else {
            set_flash('danger', 'إجراء غير معروف.');
            redirect('admin_users.php');
        }

    } catch (PDOException $e) {
        if ($action === 'create_user' && (string) $e->getCode() === '23000') {
            $errors['general'] = 'اسم المستخدم أو البريد الإلكتروني مسجّل مسبقًا.';
        } else {
            error_log('Admin users error: ' . $e->getMessage());
            set_flash('danger', 'حدث خطأ غير متوقع. يرجى المحاولة لاحقًا.');
            redirect('admin_users.php');
        }
    }
}

// ---------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------
$users     = [];
$loadError = null;

try {
    // Inactive accounts first, so pending requests are seen immediately.
    $users = $pdo->query(
        'SELECT id, username, email, role, is_active, created_at
           FROM users
          ORDER BY is_active ASC, created_at DESC, id DESC'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('List users error: ' . $e->getMessage());
    $loadError = 'تعذّر تحميل قائمة المستخدمين. يرجى المحاولة لاحقًا.';
}

$pending     = count(array_filter($users, static function (array $u): bool { return (int) $u['is_active'] === 0; }));
$currentMode = registration_mode();

$modeOptions = [
    'closed'   => ['مغلق (موصى به)', 'لا يستطيع أي شخص إنشاء حساب بنفسه. المسؤول وحده ينشئ الحسابات من هذه الصفحة.'],
    'approval' => ['بموافقة المسؤول', 'يستطيع الزائر تقديم طلب تسجيل، لكن يبقى حسابه غير مفعّل حتى توافق عليه من هذه الصفحة.'],
    'open'     => ['مفتوح للجميع', 'يستطيع أي شخص إنشاء حساب والدخول فورًا إلى بيانات الطلاب. غير مناسب لبيئة الإنتاج.'],
];

render_header('إدارة المستخدمين');
render_navbar('users');
?>
<main class="container py-4 py-md-5">

    <?php render_flash(); ?>

    <div class="mb-4">
        <h1 class="h3 fw-bold mb-1">إدارة المستخدمين</h1>
        <p class="text-muted mb-0">التحكم في التسجيل العام، وإنشاء الحسابات، وتفعيلها وإدارة صلاحياتها.</p>
    </div>

    <?php if ($currentMode === 'open'): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
            <strong>تنبيه أمني:</strong> التسجيل مفتوح للجميع، ويستطيع أي شخص إنشاء حساب والاطلاع على بيانات الطلاب.
        </div>
    <?php endif; ?>

    <?php if ($pending > 0): ?>
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
            يوجد <strong><?= $pending ?></strong> حساب غير مفعّل بانتظار المراجعة.
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">

        <!-- Registration mode -->
        <div class="col-lg-6">
            <div class="card content-card h-100">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-1">التسجيل العام</h2>
                    <p class="text-muted small mb-3">
                        الوضع الحالي: <strong><?= e(registration_mode_label($currentMode)) ?></strong>
                    </p>

                    <form method="post" action="admin_users.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_mode">

                        <?php foreach ($modeOptions as $value => $option): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="radio" name="registration_mode"
                                       id="mode_<?= e($value) ?>" value="<?= e($value) ?>"
                                       <?= $currentMode === $value ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mode_<?= e($value) ?>">
                                    <span class="fw-semibold"><?= e($option[0]) ?></span>
                                    <span class="d-block text-muted small"><?= e($option[1]) ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>

                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>حفظ الإعداد
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Create account -->
        <div class="col-lg-6">
            <div class="card content-card h-100" id="create-user">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-1">إنشاء حساب جديد</h2>
                    <p class="text-muted small mb-3">ينشئ المسؤول الحساب، ويكون مفعّلًا فورًا.</p>

                    <?php if (isset($errors['general'])): ?>
                        <div class="alert alert-danger" role="alert"><?= e($errors['general']) ?></div>
                    <?php endif; ?>

                    <form method="post" action="admin_users.php#create-user" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_user">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="new_username" class="form-label fw-semibold">اسم المستخدم</label>
                                <input type="text" class="form-control<?= is_invalid($errors, 'username') ?>"
                                       id="new_username" name="username" value="<?= e($form['username']) ?>"
                                       maxlength="50" autocomplete="off" required>
                                <?= field_feedback($errors, 'username') ?>
                            </div>

                            <div class="col-md-6">
                                <label for="new_email" class="form-label fw-semibold">البريد الإلكتروني</label>
                                <input type="email" dir="ltr" class="form-control<?= is_invalid($errors, 'email') ?>"
                                       id="new_email" name="email" value="<?= e($form['email']) ?>"
                                       maxlength="255" autocomplete="off" required>
                                <?= field_feedback($errors, 'email') ?>
                            </div>

                            <div class="col-md-6">
                                <label for="new_password" class="form-label fw-semibold">كلمة المرور</label>
                                <div class="input-group has-validation">
                                    <input type="password" class="form-control<?= is_invalid($errors, 'password') ?>"
                                           id="new_password" name="password" autocomplete="new-password" required>
                                    <button class="btn btn-outline-secondary" type="button"
                                            data-toggle-password="new_password" aria-label="إظهار كلمة المرور">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?= field_feedback($errors, 'password') ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="new_confirm" class="form-label fw-semibold">تأكيد كلمة المرور</label>
                                <div class="input-group has-validation">
                                    <input type="password" class="form-control<?= is_invalid($errors, 'confirm_password') ?>"
                                           id="new_confirm" name="confirm_password" autocomplete="new-password" required>
                                    <button class="btn btn-outline-secondary" type="button"
                                            data-toggle-password="new_confirm" aria-label="إظهار كلمة المرور">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?= field_feedback($errors, 'confirm_password') ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="new_role" class="form-label fw-semibold">الدور</label>
                                <select class="form-select" id="new_role" name="role">
                                    <?php foreach (USER_ROLES as $role): ?>
                                        <option value="<?= e($role) ?>" <?= $form['role'] === $role ? 'selected' : '' ?>>
                                            <?= e(user_role_label($role)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>إنشاء الحساب
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Users list -->
    <div class="card content-card">
        <div class="card-body p-3 p-md-4">
            <h2 class="h5 fw-bold mb-3">الحسابات <span class="text-muted fs-6">(<?= count($users) ?>)</span></h2>

            <?php if ($loadError !== null): ?>
                <div class="alert alert-danger" role="alert"><?= e($loadError) ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">اسم المستخدم</th>
                                <th scope="col">البريد الإلكتروني</th>
                                <th scope="col">الدور</th>
                                <th scope="col">الحالة</th>
                                <th scope="col">تاريخ التسجيل</th>
                                <th scope="col" class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php
                            $uid      = (int) $u['id'];
                            $isMe     = $uid === $myId;
                            $isActive = (int) $u['is_active'] === 1;
                            $isAdmin  = $u['role'] === 'admin';
                            ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?= e($u['username']) ?>
                                    <?php if ($isMe): ?><span class="badge text-bg-light border ms-1">أنت</span><?php endif; ?>
                                </td>
                                <td><span class="bidi-ltr"><?= e($u['email']) ?></span></td>
                                <td>
                                    <span class="badge <?= $isAdmin ? 'text-bg-primary' : 'text-bg-secondary' ?>">
                                        <?= e(user_role_label((string) $u['role'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-warning' ?>">
                                        <?= $isActive ? 'مفعّل' : 'غير مفعّل' ?>
                                    </span>
                                </td>
                                <td class="text-nowrap text-muted">
                                    <span class="bidi-ltr"><?= e(date('Y/m/d', (int) strtotime((string) $u['created_at']))) ?></span>
                                </td>
                                <td class="text-center text-nowrap">
                                    <?php if ($isMe): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <form method="post" action="admin_users.php" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_active">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
                                            <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-success' ?>">
                                                <?= $isActive ? 'تعطيل' : 'تفعيل' ?>
                                            </button>
                                        </form>

                                        <form method="post" action="admin_users.php" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_role">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="role" value="<?= $isAdmin ? 'staff' : 'admin' ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <?= $isAdmin ? 'تحويل إلى موظف' : 'ترقية إلى مسؤول' ?>
                                            </button>
                                        </form>

                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                                                data-user-id="<?= $uid ?>" data-user-name="<?= e($u['username']) ?>">
                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>حذف
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Delete user confirmation -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="admin_users.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId" value="">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="deleteUserModalLabel">
                        <i class="bi bi-exclamation-triangle-fill text-danger me-2" aria-hidden="true"></i>تأكيد حذف المستخدم
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    هل أنت متأكد من حذف حساب <strong id="deleteUserName"></strong>؟
                    <div class="text-muted small mt-2">لا يمكن التراجع عن هذا الإجراء، ولن يتمكن المستخدم من تسجيل الدخول بعد الآن.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1" aria-hidden="true"></i>نعم، احذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('deleteUserModal');
    modal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) { return; }
        document.getElementById('deleteUserId').value = button.getAttribute('data-user-id') || '';
        document.getElementById('deleteUserName').textContent = button.getAttribute('data-user-name') || '';
    });
})();
</script>
<?php render_footer(); ?>
