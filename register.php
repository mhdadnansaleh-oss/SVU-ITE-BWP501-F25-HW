<?php
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // التحقق من عدم وجود حقول فارغة
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        header("Location: register.html?error=empty");
        exit();
    }

    if ($password !== $confirm_password) {
        header("Location: register.html?error=nomatch");
        exit();
    }

    // حماية المدخلات من SQL Injection
    $username_safe = mysqli_real_escape_string($conn, $username);
    $email_safe = mysqli_real_escape_string($conn, $email);

    // التأكد من عدم وجود اسم المستخدم أو الإيميل مسبقاً
    $check_sql = "SELECT * FROM users WHERE username = '$username_safe' OR email = '$email_safe'";
    $check_res = mysqli_query($conn, $check_sql);

    if (mysqli_num_rows($check_res) > 0) {
        header("Location: register.html?error=exists");
        exit();
    }

    // تشفير كلمة المرور كما هو مطلوب في الوظيفة
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // إدخال المستخدم الجديد في قاعدة البيانات
    $insert_sql = "INSERT INTO users (username, email, password) VALUES ('$username_safe', '$email_safe', '$hashed_password')";
    
    if (mysqli_query($conn, $insert_sql)) {
        // التوجيه لصفحة تسجيل الدخول بعد النجاح
        header("Location: login.html?registered=1");
        exit();
    } else {
        header("Location: register.html?error=db");
        exit();
    }
} else {
    header("Location: register.html");
    exit();
}
?>
