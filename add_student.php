<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/student_helpers.php';
require_once __DIR__ . '/student_form.php';

require_login();

$errors = [];
$values = ['name' => '', 'email' => '', 'phone' => '', 'address' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors['general'] = 'انتهت صلاحية الجلسة أو أن الطلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $values      = clean_student_input($_POST);
        $picturePath = null;
        $saved       = false;

        try {
            // 1) Validate text fields (same rules as the live AJAX validation)
            $errors = validate_student_form($pdo, $values, null);

            // 2) Validate the optional picture
            $file    = (isset($_FILES['profile_picture']) && is_array($_FILES['profile_picture']))
                ? $_FILES['profile_picture'] : null;
            $hasFile = $file !== null && upload_present($file);

            if ($hasFile) {
                $pictureError = validate_profile_picture($file);
                if ($pictureError !== null) {
                    $errors['profile_picture'] = $pictureError;
                }
            }

            // 3) Save the file, then the record
            if (!$errors) {
                if ($hasFile) {
                    $picturePath = save_profile_picture($file);
                }

                $insert = $pdo->prepare(
                    'INSERT INTO students (name, email, phone, address, profile_picture)
                     VALUES (:name, :email, :phone, :address, :picture)'
                );
                $insert->execute([
                    ':name'    => $values['name'],
                    ':email'   => $values['email'],
                    ':phone'   => $values['phone'],
                    ':address' => $values['address'] !== '' ? $values['address'] : null,
                    ':picture' => $picturePath,
                ]);
                $saved = true;
            }
        } catch (PDOException $e) {
            delete_profile_picture($picturePath);   // do not leave an orphan file behind

            if ((string) $e->getCode() === '23000') {
                $errors['email'] = 'هذا البريد الإلكتروني مسجّل لطالب آخر.';
            } else {
                error_log('Add student error: ' . $e->getMessage());
                $errors['general'] = 'حدث خطأ غير متوقع أثناء حفظ بيانات الطالب. يرجى المحاولة لاحقًا.';
            }
        } catch (RuntimeException $e) {
            error_log('Upload error: ' . $e->getMessage());
            $errors['profile_picture'] = 'تعذّر حفظ الصورة على الخادم. يرجى المحاولة مرة أخرى.';
        }

        if ($saved) {
            set_flash('success', 'تمت إضافة الطالب «' . $values['name'] . '» بنجاح.');
            redirect('view_students.php');
        }
    }
}

render_header('إضافة طالب جديد');
render_navbar('add');
?>
<main class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-lg-9 col-xl-8">
            <div class="card content-card">
                <div class="card-body p-4 p-md-5">

                    <div class="mb-4">
                        <h1 class="h3 fw-bold mb-1">إضافة طالب جديد</h1>
                        <p class="text-muted mb-0">أدخل بيانات الطالب. الحقول المعلّمة بعلامة (<span class="text-danger">*</span>) إلزامية.</p>
                    </div>

                    <?php if (isset($errors['general'])): ?>
                        <div class="alert alert-danger" role="alert"><?= e($errors['general']) ?></div>
                    <?php endif; ?>

                    <?php render_student_form('add_student.php', $values, $errors, 'حفظ الطالب', 'view_students.php'); ?>

                </div>
            </div>
        </div>
    </div>
</main>
<?php render_footer(); ?>
