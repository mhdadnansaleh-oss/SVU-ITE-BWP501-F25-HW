<?php
declare(strict_types=1);

/**
 * layout.php - shared Arabic (RTL) Bootstrap 5 layout.
 * Pages call render_header(), output their own content, then render_footer().
 */

require_once __DIR__ . '/auth.php';

const APP_NAME = 'نظام تسجيل الطلاب';

/** Adds Bootstrap's "is-invalid" class when a field has an error. */
function is_invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}

/** Renders the Arabic error message under a field. */
function field_feedback(array $errors, string $key): string
{
    return isset($errors[$key])
        ? '<div class="invalid-feedback">' . e($errors[$key]) . '</div>'
        : '';
}

/** Renders a one-time flash message (if any). */
function render_flash(): void
{
    $flash = get_flash();
    if ($flash === null) {
        return;
    }
    $allowed = ['success', 'danger', 'warning', 'info'];
    $type    = in_array($flash['type'], $allowed, true) ? $flash['type'] : 'info';
    echo '<div class="alert alert-' . $type . '" role="alert">' . e($flash['message']) . '</div>';
}

function render_header(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | <?= e(APP_NAME) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 RTL build. For production, pin SRI hashes or self-host these files. -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root { --brand: #1d4ed8; --brand-dark: #1e3a8a; }
        body {
            font-family: 'Cairo', system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif;
            background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 60%, #e0e7ff 100%);
            min-height: 100vh;
        }
        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .auth-card {
            width: 100%;
            max-width: 480px;
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .10);
        }
        .brand-badge {
            width: 68px; height: 68px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.9rem;
            margin: 0 auto 1rem;
        }
        .btn-primary { background-color: var(--brand); border-color: var(--brand); }
        .btn-primary:hover, .btn-primary:focus { background-color: var(--brand-dark); border-color: var(--brand-dark); }
        .form-control, .btn { border-radius: .6rem; }
        .navbar-brand { font-weight: 700; }

        /* Student module */
        .content-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .10);
        }
        .avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #64748b;
            display: inline-flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 1.25rem;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-lg { width: 96px; height: 96px; font-size: 2.75rem; }
        .avatar-xl { width: 128px; height: 128px; font-size: 3.75rem; }
        .table > thead th { font-weight: 600; white-space: nowrap; }
        /* keeps emails, phone numbers and dates in the right order inside RTL text */
        .bidi-ltr { direction: ltr; unicode-bidi: isolate; display: inline-block; }
        .navbar .nav-link.active { color: #fff; font-weight: 600; border-bottom: 2px solid #fff; }

        /* Dashboard widgets */
        .min-w-0 { min-width: 0; }
        .stat-icon {
            width: 56px; height: 56px;
            border-radius: 1rem;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.6rem;
            flex-shrink: 0;
        }
        .stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1.2; }
        .bar-chart { display: flex; gap: .75rem; align-items: flex-end; }
        .bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; min-width: 0; }
        .bar-track { height: 170px; width: 100%; display: flex; align-items: flex-end; }
        .bar-fill {
            position: relative;
            width: 100%;
            background: linear-gradient(180deg, #3b82f6, #1d4ed8);
            border-radius: .5rem .5rem 0 0;
        }
        .bar-count { position: absolute; top: -1.5rem; left: 0; right: 0; text-align: center; font-weight: 700; font-size: .9rem; }
        .bar-label { margin-top: .5rem; font-size: .8rem; color: #64748b; white-space: nowrap; }
    </style>
</head>
<body>
<?php
}

/** Top navigation bar for signed-in pages. */
function render_navbar(string $active = ''): void
{
    ?>
    <nav class="navbar navbar-dark shadow-sm" style="background: linear-gradient(90deg, #1d4ed8, #1e3a8a);">
        <div class="container gap-2">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-mortarboard-fill me-2"></i><?= e(APP_NAME) ?>
            </a>
            <ul class="navbar-nav flex-row gap-3 me-lg-auto">
                <li class="nav-item">
                    <a class="nav-link px-1<?= $active === 'students' ? ' active' : '' ?>" href="view_students.php">
                        <i class="bi bi-people-fill me-1"></i>الطلاب
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-1<?= $active === 'add' ? ' active' : '' ?>" href="add_student.php">
                        <i class="bi bi-person-plus-fill me-1"></i>إضافة طالب
                    </a>
                </li>
                <?php if (is_admin()): ?>
                    <li class="nav-item">
                        <a class="nav-link px-1<?= $active === 'users' ? ' active' : '' ?>" href="admin_users.php">
                            <i class="bi bi-shield-lock-fill me-1"></i>المستخدمون
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-3 text-white">
                <a href="profile.php" class="text-white text-decoration-none<?= $active === 'profile' ? ' fw-bold' : '' ?>"
                   title="حسابي">
                    <i class="bi bi-person-circle me-1"></i><?= e($_SESSION['username'] ?? '') ?>
                </a>
                <a href="logout.php" class="btn btn-light btn-sm">
                    <i class="bi bi-box-arrow-left me-1"></i>تسجيل الخروج
                </a>
            </div>
        </div>
    </nav>
<?php
}

function render_footer(): void
{
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show / hide password buttons
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-toggle-password'));
                var icon  = btn.querySelector('i');
                var show  = input.type === 'password';
                input.type = show ? 'text' : 'password';
                icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
                btn.setAttribute('aria-label', show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
            });
        });
    </script>
</body>
</html>
<?php
}
