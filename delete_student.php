<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/student_helpers.php';

require_login();

$state = list_state($_GET);          // keep search/sort/page when returning to the list
$id    = positive_int($_GET['id'] ?? null);

if ($id === null) {
    set_flash('danger', 'معرّف الطالب غير صالح.');
    redirect(list_url($state));
}

// Load the student (needed for the confirmation text and to remove the picture)
try {
    $stmt = $pdo->prepare('SELECT id, name, email, phone, profile_picture FROM students WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $student = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Load student error: ' . $e->getMessage());
    set_flash('danger', 'تعذّر تحميل بيانات الطالب. يرجى المحاولة لاحقًا.');
    redirect(list_url($state));
}

if (!$student) {
    set_flash('danger', 'لم يتم العثور على الطالب المطلوب. ربما تم حذفه مسبقًا.');
    redirect(list_url($state));
}

// ---------------------------------------------------------------
// POST = the user confirmed -> delete
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'انتهت صلاحية الجلسة أو أن الطلب غير صالح. يرجى المحاولة مرة أخرى.');
        redirect(list_url($state));
    }

    try {
        $delete = $pdo->prepare('DELETE FROM students WHERE id = :id');
        $delete->execute([':id' => $id]);

        // Remove the picture file only after the row is gone
        delete_profile_picture($student['profile_picture']);

        set_flash('success', 'تم حذف الطالب «' . $student['name'] . '» بنجاح.');
    } catch (PDOException $e) {
        error_log('Delete student error: ' . $e->getMessage());
        set_flash('danger', 'تعذّر حذف الطالب. يرجى المحاولة لاحقًا.');
    }

    redirect(list_url($state));
}

// ---------------------------------------------------------------
// GET = show the confirmation page (also the fallback when JS is off)
// ---------------------------------------------------------------
render_header('حذف الطالب');
render_navbar('students');
?>
<main class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card content-card">
                <div class="card-body p-4 p-md-5 text-center">

                    <div class="mb-3 text-danger" style="font-size: 3rem;">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    </div>
                    <h1 class="h4 fw-bold mb-2">تأكيد حذف الطالب</h1>
                    <p class="text-muted">هل أنت متأكد من رغبتك في حذف هذا الطالب؟ لا يمكن التراجع عن هذا الإجراء.</p>

                    <div class="d-flex align-items-center justify-content-center gap-3 border rounded-3 p-3 my-4 text-start">
                        <?= avatar_html($student['profile_picture'], 'avatar-lg') ?>
                        <div class="text-start">
                            <div class="fw-bold"><?= e($student['name']) ?></div>
                            <div class="text-muted small"><span class="bidi-ltr"><?= e($student['email']) ?></span></div>
                            <div class="text-muted small"><span class="bidi-ltr"><?= e($student['phone'] ?? '') ?></span></div>
                        </div>
                    </div>

                    <form method="post" action="<?= e(action_url('delete_student.php', $id, $state)) ?>"
                          class="d-flex flex-wrap justify-content-center gap-2">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger px-4">
                            <i class="bi bi-trash me-1" aria-hidden="true"></i>نعم، احذف الطالب
                        </button>
                        <a href="<?= e(list_url($state)) ?>" class="btn btn-outline-secondary px-4">إلغاء</a>
                    </form>

                </div>
            </div>
        </div>
    </div>
</main>
<?php render_footer(); ?>
