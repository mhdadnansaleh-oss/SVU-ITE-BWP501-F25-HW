<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/student_helpers.php';
require_once __DIR__ . '/student_form.php';

require_login();

$state = list_state($_GET);          // search/sort/page, so we can return to the same list view
$id    = positive_int($_GET['id'] ?? null);

if ($id === null) {
    set_flash('danger', 'معرّف الطالب غير صالح.');
    redirect(list_url($state));
}

// Load the student
try {
    $stmt = $pdo->prepare('SELECT id, name, email, phone, address, profile_picture FROM students WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $student = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Load student error: ' . $e->getMessage());
    set_flash('danger', 'تعذّر تحميل بيانات الطالب. يرجى المحاولة لاحقًا.');
    redirect(list_url($state));
}

if (!$student) {
    set_flash('danger', 'لم يتم العثور على الطالب المطلوب.');
    redirect(list_url($state));
}

$errors = [];
$values = [
    'name'    => (string) $student['name'],
    'email'   => (string) $student['email'],
    'phone'   => (string) ($student['phone'] ?? ''),
    'address' => (string) ($student['address'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors['general'] = 'انتهت صلاحية الجلسة أو أن الطلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $values      = clean_student_input($_POST);
        $newPicture  = null;
        $saved       = false;

        try {
            $errors = validate_student_form($pdo, $values, $id);

            $file    = (isset($_FILES['profile_picture']) && is_array($_FILES['profile_picture']))
                ? $_FILES['profile_picture'] : null;
            $hasFile = $file !== null && upload_present($file);

            if ($hasFile) {
                $pictureError = validate_profile_picture($file);
                if ($pictureError !== null) {
                    $errors['profile_picture'] = $pictureError;
                }
            }

            if (!$errors) {
                if ($hasFile) {
                    $newPicture = save_profile_picture($file);
                }

                $update = $pdo->prepare(
                    'UPDATE students
                        SET name = :name, email = :email, phone = :phone,
                            address = :address, profile_picture = :picture
                      WHERE id = :id'
                );
                $update->execute([
                    ':name'    => $values['name'],
                    ':email'   => $values['email'],
                    ':phone'   => $values['phone'],
                    ':address' => $values['address'] !== '' ? $values['address'] : null,
                    ':picture' => $newPicture ?? $student['profile_picture'],
                    ':id'      => $id,
                ]);
                $saved = true;
            }
        } catch (PDOException $e) {
            delete_profile_picture($newPicture);   // roll back the new file

            if ((string) $e->getCode() === '23000') {
                $errors['email'] = 'هذا البريد الإلكتروني مسجّل لطالب آخر.';
            } else {
                error_log('Update student error: ' . $e->getMessage());
                $errors['general'] = 'حدث خطأ غير متوقع أثناء تحديث بيانات الطالب. يرجى المحاولة لاحقًا.';
            }
        } catch (RuntimeException $e) {
            error_log('Upload error: ' . $e->getMessage());
            $errors['profile_picture'] = 'تعذّر حفظ الصورة على الخادم. يرجى المحاولة مرة أخرى.';
        }

        if ($saved) {
            if ($newPicture !== null) {
                delete_profile_picture($student['profile_picture']);   // remove the replaced picture
            }
            set_flash('success', 'تم تحديث بيانات الطالب «' . $values['name'] . '» بنجاح.');
            redirect(list_url($state));
        }
    }
}

render_header('تعديل بيانات الطالب');
render_navbar('students');
?>
<main class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-lg-9 col-xl-8">
            <div class="card content-card">
                <div class="card-body p-4 p-md-5">

                    <div class="d-flex align-items-center gap-3 mb-4">
                        <?= avatar_html($student['profile_picture'], 'avatar-lg') ?>
                        <div>
                            <h1 class="h3 fw-bold mb-1">تعديل بيانات الطالب</h1>
                            <p class="text-muted mb-0">رقم السجل: <span class="bidi-ltr"><?= (int) $student['id'] ?></span></p>
                        </div>
                    </div>

                    <?php if (isset($errors['general'])): ?>
                        <div class="alert alert-danger" role="alert"><?= e($errors['general']) ?></div>
                    <?php endif; ?>

                    <?php
                    render_student_form(
                        action_url('update_student.php', $id, $state),
                        $values,
                        $errors,
                        'حفظ التعديلات',
                        list_url($state),
                        $student
                    );
                    ?>

                </div>
            </div>
        </div>
    </div>
</main>
<?php render_footer(); ?>
