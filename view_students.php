<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/student_helpers.php';

require_login();

// ---------------------------------------------------------------
// Read search / sort / page (all whitelisted in list_state())
// ---------------------------------------------------------------
$state    = list_state($_GET);
$students = [];
$total    = 0;
$loadError = null;

try {
    // ---- WHERE (search by name, email or phone using LIKE) ----
    // Shared with export_students.php so the CSV matches the list exactly.
    [$where, $params] = student_search_clause($state['q']);

    // ---- total + page clamp ----
    $count = $pdo->prepare("SELECT COUNT(*) FROM students $where");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $totalPages    = max(1, (int) ceil($total / STUDENTS_PER_PAGE));
    $state['page'] = min($state['page'], $totalPages);
    $offset        = ($state['page'] - 1) * STUDENTS_PER_PAGE;

    // ---- page of rows (sort column + direction come from whitelists) ----
    $orderBy = student_order_by($state);
    $stmt = $pdo->prepare(
        "SELECT id, name, email, phone, address, profile_picture, created_at
           FROM students
           $where
          ORDER BY $orderBy
          LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', STUDENTS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('List students error: ' . $e->getMessage());
    $loadError  = 'تعذّر تحميل قائمة الطلاب. يرجى المحاولة لاحقًا.';
    $totalPages = 1;
    $offset     = 0;
}

// ---------------------------------------------------------------
// Helpers for this page
// ---------------------------------------------------------------
function sort_header(array $state, string $column, string $label): string
{
    $active  = $state['sort'] === $column;
    $nextDir = ($active && $state['dir'] === 'asc') ? 'desc' : 'asc';
    $url     = list_url(array_merge($state, ['sort' => $column, 'dir' => $nextDir, 'page' => 1]));

    if (!$active) {
        $icon = 'bi-arrow-down-up text-muted';
    } else {
        $icon = $state['dir'] === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
    }

    return '<a href="' . e($url) . '" class="text-decoration-none text-dark">'
         . e($label) . ' <i class="bi ' . $icon . '" aria-hidden="true"></i></a>';
}

function aria_sort(array $state, string $column): string
{
    if ($state['sort'] !== $column) {
        return '';
    }
    return ' aria-sort="' . ($state['dir'] === 'asc' ? 'ascending' : 'descending') . '"';
}

$pageUrl = static function (int $page) use ($state): string {
    return list_url(array_merge($state, ['page' => $page]));
};

render_header('قائمة الطلاب');
render_navbar('students');
?>
<main class="container py-4 py-md-5">

    <?php render_flash(); ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">قائمة الطلاب</h1>
            <p class="text-muted mb-0">إدارة بيانات الطلاب المسجّلين: البحث والفرز والتعديل والحذف.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php render_export_dropdown($state, $state['q'] !== '' ? 'تصدير نتائج البحث (CSV)' : 'تصدير الكل (CSV)'); ?>
            <a href="add_student.php" class="btn btn-primary">
                <i class="bi bi-person-plus-fill me-1"></i>إضافة طالب جديد
            </a>
        </div>
    </div>

    <div class="card content-card">
        <div class="card-body p-3 p-md-4">

            <!-- Search -->
            <form method="get" action="view_students.php" class="row g-2 mb-3">
                <input type="hidden" name="sort" value="<?= e($state['sort']) ?>">
                <input type="hidden" name="dir" value="<?= e($state['dir']) ?>">
                <div class="col-12 col-md">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input type="search" class="form-control" name="q" value="<?= e($state['q']) ?>"
                               maxlength="100" placeholder="ابحث بالاسم أو البريد الإلكتروني أو رقم الهاتف"
                               aria-label="بحث في الطلاب">
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary px-4">بحث</button>
                </div>
                <?php if ($state['q'] !== ''): ?>
                    <div class="col-auto">
                        <a href="view_students.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-lg me-1"></i>إعادة تعيين
                        </a>
                    </div>
                <?php endif; ?>
            </form>

            <?php if ($loadError !== null): ?>
                <div class="alert alert-danger" role="alert"><?= e($loadError) ?></div>
            <?php else: ?>

                <p class="text-muted small mb-3">
                    <?php if ($total > 0): ?>
                        عرض من <strong><?= $offset + 1 ?></strong> إلى <strong><?= $offset + count($students) ?></strong>
                        من أصل <strong><?= $total ?></strong> طالب
                        <?php if ($state['q'] !== ''): ?>
                            — نتائج البحث عن «<?= e($state['q']) ?>»
                        <?php endif; ?>
                    <?php else: ?>
                        لا توجد نتائج.
                    <?php endif; ?>
                </p>

                <!-- Table -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">الصورة</th>
                                <th scope="col"<?= aria_sort($state, 'name') ?>><?= sort_header($state, 'name', 'الاسم') ?></th>
                                <th scope="col"<?= aria_sort($state, 'email') ?>><?= sort_header($state, 'email', 'البريد الإلكتروني') ?></th>
                                <th scope="col"<?= aria_sort($state, 'phone') ?>><?= sort_header($state, 'phone', 'الهاتف') ?></th>
                                <th scope="col" class="d-none d-lg-table-cell">العنوان</th>
                                <th scope="col"<?= aria_sort($state, 'created_at') ?>><?= sort_header($state, 'created_at', 'تاريخ التسجيل') ?></th>
                                <th scope="col" class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$students): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="bi bi-people fs-1 d-block mb-2" aria-hidden="true"></i>
                                    <?php if ($state['q'] !== ''): ?>
                                        لا توجد نتائج مطابقة لبحثك.
                                    <?php else: ?>
                                        لا يوجد طلاب مسجّلون حتى الآن.
                                        <a href="add_student.php" class="text-decoration-none">أضف أول طالب</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($students as $i => $row): ?>
                            <?php $id = (int) $row['id']; ?>
                            <tr>
                                <td class="text-muted"><?= $offset + $i + 1 ?></td>
                                <td><?= avatar_html($row['profile_picture']) ?></td>
                                <td class="fw-semibold"><?= e($row['name']) ?></td>
                                <td><span class="bidi-ltr"><?= e($row['email']) ?></span></td>
                                <td><span class="bidi-ltr"><?= e($row['phone'] ?? '') ?></span></td>
                                <td class="d-none d-lg-table-cell text-muted" title="<?= e($row['address'] ?? '') ?>">
                                    <?= ($row['address'] ?? '') !== ''
                                        ? e(mb_strimwidth((string) $row['address'], 0, 40, '…', 'UTF-8'))
                                        : '—' ?>
                                </td>
                                <td class="text-nowrap text-muted">
                                    <span class="bidi-ltr"><?= e(date('Y/m/d', (int) strtotime((string) $row['created_at']))) ?></span>
                                </td>
                                <td class="text-center text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal" data-bs-target="#detailsModal"
                                            data-student-id="<?= $id ?>"
                                            data-student-name="<?= e($row['name']) ?>"
                                            data-student-email="<?= e($row['email']) ?>"
                                            data-student-phone="<?= e($row['phone'] ?? '') ?>"
                                            data-student-address="<?= e($row['address'] ?? '') ?>"
                                            data-student-picture="<?= e(profile_picture_url($row['profile_picture']) ?? '') ?>"
                                            data-student-created="<?= e(date('Y/m/d H:i', (int) strtotime((string) $row['created_at']))) ?>"
                                            data-student-edit="<?= e(action_url('update_student.php', $id, $state)) ?>">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>عرض التفاصيل
                                    </button>
                                    <a href="<?= e(action_url('update_student.php', $id, $state)) ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>تعديل
                                    </a>
                                    <a href="<?= e(action_url('delete_student.php', $id, $state)) ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       data-delete data-name="<?= e($row['name']) ?>">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>حذف
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <?php
                    $current = $state['page'];
                    $from    = max(1, $current - 2);
                    $to      = min($totalPages, $current + 2);
                    ?>
                    <nav class="mt-4" aria-label="التنقل بين الصفحات">
                        <ul class="pagination justify-content-center flex-wrap mb-2">

                            <li class="page-item<?= $current <= 1 ? ' disabled' : '' ?>">
                                <?php if ($current > 1): ?>
                                    <a class="page-link" href="<?= e($pageUrl($current - 1)) ?>" rel="prev">
                                        <i class="bi bi-chevron-right me-1" aria-hidden="true"></i>السابق
                                    </a>
                                <?php else: ?>
                                    <span class="page-link"><i class="bi bi-chevron-right me-1" aria-hidden="true"></i>السابق</span>
                                <?php endif; ?>
                            </li>

                            <?php if ($from > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= e($pageUrl(1)) ?>">1</a></li>
                                <?php if ($from > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $from; $p <= $to; $p++): ?>
                                <li class="page-item<?= $p === $current ? ' active' : '' ?>">
                                    <a class="page-link" href="<?= e($pageUrl($p)) ?>"
                                       <?= $p === $current ? 'aria-current="page"' : '' ?>><?= $p ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($to < $totalPages): ?>
                                <?php if ($to < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?= e($pageUrl($totalPages)) ?>"><?= $totalPages ?></a></li>
                            <?php endif; ?>

                            <li class="page-item<?= $current >= $totalPages ? ' disabled' : '' ?>">
                                <?php if ($current < $totalPages): ?>
                                    <a class="page-link" href="<?= e($pageUrl($current + 1)) ?>" rel="next">
                                        التالي<i class="bi bi-chevron-left ms-1" aria-hidden="true"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="page-link">التالي<i class="bi bi-chevron-left ms-1" aria-hidden="true"></i></span>
                                <?php endif; ?>
                            </li>
                        </ul>
                        <p class="text-center text-muted small mb-0">الصفحة <?= $current ?> من <?= $totalPages ?></p>
                    </nav>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Student details (filled from the row's data-* attributes - no page reload) -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="detailsModalLabel">
                    <i class="bi bi-person-vcard me-2" aria-hidden="true"></i>تفاصيل الطالب
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>

            <div class="modal-body">
                <div class="text-center mb-4">
                    <span class="avatar avatar-xl mb-3">
                        <img id="detailsPicture" class="d-none" alt="صورة الطالب">
                        <i id="detailsPlaceholder" class="bi bi-person-fill" aria-hidden="true"></i>
                    </span>
                    <h3 class="h5 fw-bold mb-1" id="detailsName"></h3>
                    <div class="text-muted small">رقم السجل: <span class="bidi-ltr" id="detailsId"></span></div>
                </div>

                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted fw-normal">البريد الإلكتروني</dt>
                    <dd class="col-sm-8 text-break"><a id="detailsEmail" class="bidi-ltr text-decoration-none"></a></dd>

                    <dt class="col-sm-4 text-muted fw-normal">رقم الهاتف</dt>
                    <dd class="col-sm-8"><a id="detailsPhone" class="bidi-ltr text-decoration-none"></a></dd>

                    <dt class="col-sm-4 text-muted fw-normal">العنوان</dt>
                    <dd class="col-sm-8" id="detailsAddress" style="white-space: pre-line;"></dd>

                    <dt class="col-sm-4 text-muted fw-normal">تاريخ التسجيل</dt>
                    <dd class="col-sm-8 mb-0"><span class="bidi-ltr" id="detailsCreated"></span></dd>
                </dl>
            </div>

            <div class="modal-footer">
                <a href="#" id="detailsEdit" class="btn btn-primary">
                    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>تعديل البيانات
                </a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('detailsModal');

    function setText(id, value) {
        document.getElementById(id).textContent = value && value !== '' ? value : '—';
    }
    // mailto: / tel: links. Values go in via textContent / property assignment (never innerHTML).
    function setLink(id, value, prefix, clean) {
        var link = document.getElementById(id);
        if (!value) {
            link.textContent = '—';
            link.removeAttribute('href');
            return;
        }
        link.textContent = value;
        link.setAttribute('href', prefix + clean(value));
    }

    modal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;           // the "عرض التفاصيل" button that was clicked
        if (!button) { return; }
        var d = button.dataset;

        setText('detailsName', d.studentName);
        setText('detailsId', d.studentId);
        setText('detailsAddress', d.studentAddress);
        setText('detailsCreated', d.studentCreated);
        setLink('detailsEmail', d.studentEmail, 'mailto:', encodeURIComponent);
        setLink('detailsPhone', d.studentPhone, 'tel:', function (v) { return v.replace(/[^\d+]/g, ''); });
        document.getElementById('detailsEdit').setAttribute('href', d.studentEdit || '#');

        var picture     = document.getElementById('detailsPicture');
        var placeholder = document.getElementById('detailsPlaceholder');
        if (d.studentPicture) {
            picture.src = d.studentPicture;
            picture.classList.remove('d-none');
            placeholder.classList.add('d-none');
        } else {
            picture.removeAttribute('src');
            picture.classList.add('d-none');
            placeholder.classList.remove('d-none');
        }
    });
})();
</script>

<!-- Delete confirmation -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="deleteForm" action="delete_student.php">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="deleteModalLabel">
                        <i class="bi bi-exclamation-triangle-fill text-danger me-2" aria-hidden="true"></i>تأكيد الحذف
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    هل أنت متأكد من حذف الطالب <strong id="deleteName"></strong>؟
                    <div class="text-muted small mt-2">لا يمكن التراجع عن هذا الإجراء، وسيتم حذف صورته الشخصية أيضًا.</div>
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
document.addEventListener('DOMContentLoaded', function () {
    // Progressive enhancement: without JavaScript the "حذف" link opens a confirmation page instead.
    var modalElement = document.getElementById('deleteModal');
    var form         = document.getElementById('deleteForm');
    var nameElement  = document.getElementById('deleteName');
    var modal        = new bootstrap.Modal(modalElement);

    document.querySelectorAll('[data-delete]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            form.action = link.href;
            nameElement.textContent = link.getAttribute('data-name');
            modal.show();
        });
    });
});
</script>
<?php render_footer(); ?>
