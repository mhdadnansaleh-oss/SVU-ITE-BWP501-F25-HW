<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html?error=auth");
    exit();
}

// جلب رقم الطالب من الرابط أو من النموذج
$id = 0;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
} elseif (isset($_POST['id']) && is_numeric($_POST['id'])) {
    $id = (int)$_POST['id'];
}

if ($id <= 0) {
    header("Location: view_students.php");
    exit();
}

// جلب بيانات الطالب الحالية لعرضها في الحقول
$res = mysqli_query($conn, "SELECT * FROM students WHERE id = $id");
if (!$res || mysqli_num_rows($res) == 0) {
    header("Location: view_students.php");
    exit();
}
$student = mysqli_fetch_assoc($res);

$err = "";

// معالجة حفظ التعديلات
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);

    if (empty($name) || empty($email) || empty($phone) || empty($address)) {
        $err = "جميع الحقول مطلوبة ما عدا الصورة الشخصية.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = "صيغة البريد الإلكتروني غير صحيحة.";
    } else {
        $profile_pic_path = $student['profile_picture']; // الاحتفاظ بالصورة القديمة افتراضياً

        // التحقق مما إذا قام المستخدم برفع صورة جديدة
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
            $upload_dir = "uploads/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_name = basename($_FILES['profile_picture']['name']);
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed = array("jpg", "jpeg", "png", "gif");

            if (in_array($file_ext, $allowed)) {
                $new_file_name = time() . "_" . rand(100, 999) . "." . $file_ext;
                $target_file = $upload_dir . $new_file_name;

                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
                    $profile_pic_path = $target_file;
                } else {
                    $err = "تعذر رفع الصورة الشخصية الجديدة.";
                }
            } else {
                $err = "يسمح فقط برفع الصور من نوع JPG, JPEG, PNG, GIF.";
            }
        }

        if ($err == "") {
            $stmt = mysqli_prepare($conn, "UPDATE students SET name = ?, email = ?, phone = ?, address = ?, profile_picture = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "sssssi", $name, $email, $phone, $address, $profile_pic_path, $id);

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                header("Location: view_students.php?msg=updated");
                exit();
            } else {
                $err = "فشل التحديث: " . mysqli_error($conn);
                mysqli_stmt_close($stmt);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل بيانات طالب - نظام تسجيل الطلاب</title>
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
                    <li class="nav-item"><a class="nav-link" href="add_student.php">إضافة طالب</a></li>
                    <li class="nav-item"><a class="nav-link text-warning" href="logout.php">تسجيل الخروج (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="form-box" style="max-width: 650px;">
            <h3 class="mb-4 text-center">تعديل بيانات الطالب</h3>

            <?php if ($err != ""): ?>
                <div class="alert alert-danger"><?php echo $err; ?></div>
            <?php endif; ?>

            <form action="update_student.php?id=<?php echo $student['id']; ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?php echo $student['id']; ?>">

                <div class="mb-3">
                    <label for="name" class="form-label">الاسم الكامل *</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($student['name']); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">البريد الإلكتروني *</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($student['email']); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="phone" class="form-label">رقم الهاتف *</label>
                    <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($student['phone']); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="address" class="form-label">العنوان *</label>
                    <textarea class="form-control" id="address" name="address" rows="3" required><?php echo htmlspecialchars($student['address']); ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">الصورة الشخصية الحالية</label>
                    <div>
                        <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="صورة الطالب" class="student-img mb-2">
                        <?php else: ?>
                            <p class="text-muted small">لا توجد صورة شخصية مرفوعة.</p>
                        <?php endif; ?>
                    </div>
                    <label for="profile_picture" class="form-label">رفع صورة جديدة (اتركه فارغاً للاحتفاظ بالصورة الحالية)</label>
                    <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*">
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <a href="view_students.php" class="btn btn-secondary">إلغاء</a>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
