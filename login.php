<?php
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_input = trim($_POST['user_input']);
    $password = $_POST['password'];

    if (empty($user_input) || empty($password)) {
        header("Location: login.html?error=empty");
        exit();
    }

    $user_safe = mysqli_real_escape_string($conn, $user_input);

    // البحث عن المستخدم عن طريق اسم المستخدم أو البريد الإلكتروني
    $sql = "SELECT * FROM users WHERE username = '$user_safe' OR email = '$user_safe' LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) == 1) {
        $row = mysqli_fetch_assoc($result);

        // التحقق من تطابق كلمة المرور المشفرة
        if (password_verify($password, $row['password'])) {
            // نجاح تسجيل الدخول، حفظ البيانات في الجلسة (Session)
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['email'] = $row['email'];

            header("Location: view_students.php");
            exit();
        } else {
            header("Location: login.html?error=wrongpass");
            exit();
        }
    } else {
        header("Location: login.html?error=nouser");
        exit();
    }
} else {
    header("Location: login.html");
    exit();
}
?>
