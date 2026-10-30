<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html?error=auth");
    exit();
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];

    // جلب مسار صورة الطالب لحذفها من المجلد إن وجدت
    $res = mysqli_query($conn, "SELECT profile_picture FROM students WHERE id = $id");
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res);
        if (!empty($row['profile_picture']) && file_exists($row['profile_picture'])) {
            @unlink($row['profile_picture']);
        }
    }

    // حذف سجل الطالب من قاعدة البيانات
    $del_sql = "DELETE FROM students WHERE id = $id";
    mysqli_query($conn, $del_sql);

    header("Location: view_students.php?msg=deleted");
    exit();
} else {
    header("Location: view_students.php");
    exit();
}
?>
