<?php
session_start();
include 'db.php';

// حماية الصفحة: التأكد من تسجيل دخول المستخدم
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html?error=auth");
    exit();
}

// معالجة طلب التحقق عبر AJAX (عند إرسال ajax_validate=1)
if (isset($_POST['ajax_validate']) && $_POST['ajax_validate'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    
    $errors = array();

    if ($name == '') {
        $errors['name'] = "اسم الطالب مطلوب.";
    } elseif (mb_strlen($name) < 3) {
        $errors['name'] = "الاسم يجب أن يكون 3 أحرف على الأقل.";
    }

    if ($email == '') {
        $errors['email'] = "البريد الإلكتروني مطلوب.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "صيغة البريد الإلكتروني غير صحيحة (مثال: student@mail.com).";
    } else {
        // التأكد من عدم تكرار البريد الإلكتروني لطالب آخر
        $email_safe = mysqli_real_escape_string($conn, $email);
        $q = mysqli_query($conn, "SELECT id FROM students WHERE email = '$email_safe'");
        if ($q && mysqli_num_rows($q) > 0) {
            $errors['email'] = "هذا البريد الإلكتروني مسجل لطالب آخر مسبقاً.";
        }
    }

    if ($phone == '') {
        $errors['phone'] = "رقم الهاتف مطلوب.";
    } elseif (!preg_match('/^[0-9+\-\s]{7,15}$/', $phone)) {
        $errors['phone'] = "رقم الهاتف يجب أن يتكون من 7 إلى 15 رقماً.";
    }

    if ($address == '') {
        $errors['address'] = "حقل العنوان لا يمكن أن يكون فارغاً.";
    }

    if (count($errors) > 0) {
        echo json_encode(array("status" => "error", "errors" => $errors));
    } else {
        echo json_encode(array("status" => "success"));
    }
    exit();
}

// معالجة إرسال النموذج العادي لحفظ الطالب في قاعدة البيانات
$msg = "";
$err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['ajax_validate'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);

    if (empty($name) || empty($email) || empty($phone) || empty($address)) {
        $err = "يرجى ملء جميع الحقول المطلوبة.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = "يرجى إدخال بريد إلكتروني صحيح.";
    } else {
        $profile_pic_path = "";

        // معالجة رفع الصورة الشخصية (اختياري)
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
            $upload_dir = "uploads/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_name = basename($_FILES['profile_picture']['name']);
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed = array("jpg", "jpeg", "png", "gif");

            if (in_array($file_ext, $allowed)) {
                // تسمية الملف برقم زمني لمنع التكرار
                $new_file_name = time() . "_" . rand(100, 999) . "." . $file_ext;
                $target_file = $upload_dir . $new_file_name;

                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
                    $profile_pic_path = $target_file;
                } else {
                    $err = "حدث خطأ أثناء رفع ملف الصورة.";
                }
            } else {
                $err = "يسمح فقط برفع الصور من نوع JPG, JPEG, PNG, GIF.";
            }
        }

        // إذا لم يكن هناك خطأ، نحفظ بيانات الطالب في قاعدة البيانات
        if ($err == "") {
            $stmt = mysqli_prepare($conn, "INSERT INTO students (name, email, phone, address, profile_picture) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $phone, $address, $profile_pic_path);

            if (mysqli_stmt_execute($stmt)) {
                $msg = "تمت إضافة الطالب بنجاح!";
            } else {
                $err = "حدث خطأ أثناء حفظ البيانات: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة طالب - نظام تسجيل الطلاب</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.html">نظام تسجيل الطلاب (BWP501)</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="index.html">الرئيسية</a></li>
                    <li class="nav-item"><a class="nav-link" href="view_students.php">عرض الطلاب</a></li>
                    <li class="nav-item"><a class="nav-link active" href="add_student.php">إضافة طالب</a></li>
                    <li class="nav-item"><a class="nav-link text-warning" href="logout.php">تسجيل الخروج (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="form-box" style="max-width: 650px;">
            <h3 class="mb-4 text-center">إضافة طالب جديد</h3>

            <?php if ($msg != ""): ?>
                <div class="alert alert-success">
                    <?php echo $msg; ?> 
                    <a href="view_students.php" class="alert-link">الذهاب إلى قائمة الطلاب</a>
                </div>
            <?php endif; ?>

            <?php if ($err != ""): ?>
                <div class="alert alert-danger"><?php echo $err; ?></div>
            <?php endif; ?>

            <div id="ajax-general-msg"></div>

            <form id="addStudentForm" action="add_student.php" method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="name" class="form-label">الاسم الكامل *</label>
                    <input type="text" class="form-control" id="name" name="name" placeholder="أدخل اسم الطالب الكامل" onblur="validateWithAjax()">
                    <div id="err-name" class="error-msg"></div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">البريد الإلكتروني *</label>
                    <input type="text" class="form-control" id="email" name="email" placeholder="student@example.com" onblur="validateWithAjax()">
                    <div id="err-email" class="error-msg"></div>
                </div>

                <div class="mb-3">
                    <label for="phone" class="form-label">رقم الهاتف *</label>
                    <input type="text" class="form-control" id="phone" name="phone" placeholder="مثال: 0933112233" onblur="validateWithAjax()">
                    <div id="err-phone" class="error-msg"></div>
                </div>

                <div class="mb-3">
                    <label for="address" class="form-label">العنوان *</label>
                    <textarea class="form-control" id="address" name="address" rows="3" placeholder="المدينة، المنطقة، الشارع..." onblur="validateWithAjax()"></textarea>
                    <div id="err-address" class="error-msg"></div>
                </div>

                <div class="mb-3">
                    <label for="profile_picture" class="form-label">الصورة الشخصية (اختياري)</label>
                    <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*">
                    <div class="form-text">الصيغ المسموحة: JPG, PNG, GIF.</div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <a href="view_students.php" class="btn btn-secondary">العودة للقائمة</a>
                    <button type="submit" class="btn btn-primary">حفظ بيانات الطالب</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // دالة التحقق من الحقول عبر AJAX بدون إعادة تحميل الصفحة
        function validateWithAjax(callback) {
            var name = document.getElementById('name').value;
            var email = document.getElementById('email').value;
            var phone = document.getElementById('phone').value;
            var address = document.getElementById('address').value;

            var xhr = new XMLHttpRequest();
            xhr.open("POST", "add_student.php", true);
            xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");

            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);

                        // مسح رسائل الخطأ السابقة
                        document.getElementById('err-name').innerText = "";
                        document.getElementById('err-email').innerText = "";
                        document.getElementById('err-phone').innerText = "";
                        document.getElementById('err-address').innerText = "";

                        if (res.status === "error") {
                            if (res.errors.name) document.getElementById('err-name').innerText = res.errors.name;
                            if (res.errors.email) document.getElementById('err-email').innerText = res.errors.email;
                            if (res.errors.phone) document.getElementById('err-phone').innerText = res.errors.phone;
                            if (res.errors.address) document.getElementById('err-address').innerText = res.errors.address;

                            if (callback) callback(false);
                        } else {
                            if (callback) callback(true);
                        }
                    } catch (e) {
                        console.log("AJAX parse error:", e);
                        if (callback) callback(true);
                    }
                }
            };

            var params = "ajax_validate=1" +
                         "&name=" + encodeURIComponent(name) +
                         "&email=" + encodeURIComponent(email) +
                         "&phone=" + encodeURIComponent(phone) +
                         "&address=" + encodeURIComponent(address);

            xhr.send(params);
        }

        // التحقق عبر AJAX قبل إرسال النموذج
        document.getElementById('addStudentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var form = this;

            validateWithAjax(function(isValid) {
                if (isValid) {
                    form.submit();
                } else {
                    document.getElementById('ajax-general-msg').innerHTML = 
                        '<div class="alert alert-warning">يرجى تصحيح الأخطاء الموضحة باللون الأحمر قبل الحفظ.</div>';
                }
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
