<?php
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "student_registration_system";

$conn = mysqli_connect($servername, $db_username, $db_password, $dbname);

if (!$conn) {
    die("فشل الاتصال بقاعدة البيانات: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
?>
