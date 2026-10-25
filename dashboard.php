<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/student_helpers.php';

// Any page that calls this is only reachable by signed-in users.
require_login();

// ---------------------------------------------------------------
// Statistics (no user input in these queries; all figures come from the database)
// ---------------------------------------------------------------
$arabicMonths = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

$stats      = ['total' => 0, 'this_month' => 0, 'last_month' => 0, 'last_7_days' => 0, 'with_picture' => 0];
$chart      = [];
$recent     = [];
$statsError = null;
$pendingUsers = 0;      // inactive accounts waiting for an admin (admins only)

try {
    // Month boundaries are computed by MySQL (CURDATE) so they match the
    // time zone that created_at was stored in.
    $row = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS this_month,
                COALESCE(SUM(created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
                         AND created_at <  DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS last_month,
                COALESCE(SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)), 0) AS last_7_days,
                COALESCE(SUM(profile_picture IS NOT NULL), 0) AS with_picture,
                CURDATE() AS today
           FROM students"
    )->fetch();

    $stats = [
        'total'        => (int) $row['total'],
        'this_month'   => (int) $row['this_month'],
        'last_month'   => (int) $row['last_month'],
        'last_7_days'  => (int) $row['last_7_days'],
        'with_picture' => (int) $row['with_picture'],
    ];

    // Registrations per month for the last 6 months
    $monthly = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
           FROM students
          WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
          GROUP BY ym"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $firstOfMonth = (new DateTimeImmutable((string) $row['today']))->modify('first day of this month');
    for ($i = 5; $i >= 0; $i--) {
        $month   = $firstOfMonth->modify("-$i months");
        $chart[] = [
            'label' => $arabicMonths[(int) $month->format('n') - 1],
            'title' => $month->format('Y/m'),
            'count' => (int) ($monthly[$month->format('Y-m')] ?? 0),
        ];
    }

    // Latest registrations
    $recent = $pdo->query(
        'SELECT id, name, email, profile_picture, created_at
           FROM students
          ORDER BY created_at DESC, id DESC
          LIMIT 5'
    )->fetchAll();

    if (is_admin()) {
        $pendingUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn();
    }
} catch (PDOException $e) {
    error_log('Dashboard statistics error: ' . $e->getMessage());
    $statsError = 'تعذّر تحميل الإحصائيات حاليًا. يرجى المحاولة لاحقًا.';
}

$counts      = array_column($chart, 'count');
$maxCount    = max(1, $counts ? max($counts) : 0);
$picPercent  = $stats['total'] > 0 ? (int) round($stats['with_picture'] / $stats['total'] * 100) : 0;

$cards = [
    [
        'icon'  => 'bi-people-fill',
        'tone'  => 'primary',
        'label' => 'إجمالي الطلاب المسجّلين',
        'value' => $stats['total'],
        'note'  => 'جميع السجلات في النظام',
    ],
    [
        'icon'  => 'bi-calendar-plus-fill',
        'tone'  => 'success',
        'label' => 'تسجيلات هذا الشهر',
        'value' => $stats['this_month'],
        'note'  => 'الشهر الماضي: ' . number_format($stats['last_month']),
    ],
    [
        'icon'  => 'bi-clock-history',
        'tone'  => 'info',
        'label' => 'آخر 7 أيام',
        'value' => $stats['last_7_days'],
        'note'  => 'طلاب جدد خلال الأسبوع الأخير',
    ],
    [
        'icon'  => 'bi-image-fill',
        'tone'  => 'warning',
        'label' => 'طلاب لديهم صورة شخصية',
        'value' => $stats['with_picture'],
        'note'  => $picPercent . '٪ من إجمالي الطلاب',
    ],
];

render_header('لوحة التحكم');
render_navbar();
?>
<main class="container py-4 py-md-5">

    <?php render_flash(); ?>

    <div class="mb-4">
        <h1 class="h3 fw-bold mb-1">مرحبًا، <?= e($_SESSION['username'] ?? '') ?></h1>
        <p class="text-muted mb-0">نظرة عامة على نظام تسجيل الطلاب وأهم المؤشرات.</p>
    </div>

    <?php if ($statsError !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($statsError) ?></div>
    <?php endif; ?>

    <?php if ($pendingUsers > 0): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="alert">
            <span>
                <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                يوجد <strong><?= $pendingUsers ?></strong> حساب غير مفعّل بانتظار المراجعة.
            </span>
            <a href="admin_users.php" class="btn btn-sm btn-warning">مراجعة الحسابات</a>
        </div>
    <?php endif; ?>

    <!-- Key metrics -->
    <div class="row g-3 mb-4">
        <?php foreach ($cards as $card): ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card content-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="stat-icon bg-<?= e($card['tone']) ?>-subtle text-<?= e($card['tone']) ?>-emphasis">
                            <i class="bi <?= e($card['icon']) ?>" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-muted small"><?= e($card['label']) ?></div>
                            <div class="stat-value"><?= number_format($card['value']) ?></div>
                            <div class="text-muted small"><?= e($card['note']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4">

        <!-- Registrations per month -->
        <div class="col-lg-7">
            <div class="card content-card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-1">التسجيلات خلال آخر 6 أشهر</h2>
                    <p class="text-muted small mb-4">عدد الطلاب الجدد في كل شهر.</p>

                    <?php if ($chart): ?>
                        <div class="bar-chart" role="img"
                             aria-label="رسم بياني يوضح عدد التسجيلات الشهرية خلال آخر 6 أشهر">
                            <?php foreach ($chart as $bar): ?>
                                <?php $height = $bar['count'] > 0 ? max(8, (int) round($bar['count'] / $maxCount * 140)) : 3; ?>
                                <div class="bar-col" title="<?= e($bar['title']) ?> : <?= (int) $bar['count'] ?>">
                                    <div class="bar-track">
                                        <div class="bar-fill" style="height: <?= $height ?>px;">
                                            <span class="bar-count"><?= (int) $bar['count'] ?></span>
                                        </div>
                                    </div>
                                    <span class="bar-label"><?= e($bar['label']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">لا تتوفر بيانات لعرضها.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="col-lg-5">
            <div class="card content-card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3">إجراءات سريعة</h2>

                    <div class="d-grid gap-2">
                        <a href="add_student.php" class="btn btn-primary">
                            <i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>إضافة طالب جديد
                        </a>
                        <a href="view_students.php" class="btn btn-outline-primary">
                            <i class="bi bi-people-fill me-1" aria-hidden="true"></i>عرض جميع الطلاب
                        </a>
                        <?php render_export_dropdown([], 'تصدير كل الطلاب (CSV)', 'btn-outline-success', 'd-grid'); ?>
                    </div>

                    <hr class="my-4">

                    <form method="get" action="view_students.php">
                        <label for="quick-search" class="form-label fw-semibold small">بحث سريع عن طالب</label>
                        <div class="input-group">
                            <input type="search" class="form-control" id="quick-search" name="q" maxlength="100"
                                   placeholder="الاسم أو البريد الإلكتروني أو الهاتف">
                            <button type="submit" class="btn btn-primary" aria-label="بحث">
                                <i class="bi bi-search" aria-hidden="true"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Latest registrations -->
    <div class="card content-card">
        <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="h6 fw-bold mb-0">أحدث التسجيلات</h2>
                <a href="view_students.php" class="small text-decoration-none">عرض الكل</a>
            </div>

            <?php if (!$recent): ?>
                <p class="text-muted text-center py-4 mb-0">
                    لا يوجد طلاب مسجّلون حتى الآن.
                    <a href="add_student.php" class="text-decoration-none">أضف أول طالب</a>
                </p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recent as $student): ?>
                        <li class="list-group-item d-flex align-items-center gap-3 px-0">
                            <?= avatar_html($student['profile_picture']) ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-break"><?= e($student['name']) ?></div>
                                <div class="small text-muted text-break"><span class="bidi-ltr"><?= e($student['email']) ?></span></div>
                            </div>
                            <span class="small text-muted d-none d-sm-inline">
                                <span class="bidi-ltr"><?= e(date('Y/m/d', (int) strtotime((string) $student['created_at']))) ?></span>
                            </span>
                            <a href="<?= e(action_url('update_student.php', (int) $student['id'], list_state([]))) ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>تعديل
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php render_footer(); ?>
