<?php
// ملف الاتصال بقاعدة البيانات
$servername = "localhost";
$db_username = "root";
$db_password = ""; // كلمة المرور الافتراضية في XAMPP فارغة
$dbname = "student_registration_system";

// إنشاء الاتصال باستخدام mysqli
$conn = mysqli_connect($servername, $db_username, $db_password, $dbname);

// التحقق من نجاح الاتصال
if (!$conn) {
    die("فشل الاتصال بقاعدة البيانات: " . mysqli_connect_error());
}

// ضبط الترميز لدعم اللغة العربية
mysqli_set_charset($conn, "utf8mb4");
?>
