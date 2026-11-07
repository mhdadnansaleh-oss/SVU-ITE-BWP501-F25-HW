<?php
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        header("Location: register.html?error=empty");
        exit();
    }

    if ($password !== $confirm_password) {
        header("Location: register.html?error=nomatch");
        exit();
    }

    $username_safe = mysqli_real_escape_string($conn, $username);
    $email_safe = mysqli_real_escape_string($conn, $email);

    $check_sql = "SELECT * FROM users WHERE username = '$username_safe' OR email = '$email_safe'";
    $check_res = mysqli_query($conn, $check_sql);

    if (mysqli_num_rows($check_res) > 0) {
        header("Location: register.html?error=exists");
        exit();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $insert_sql = "INSERT INTO users (username, email, password) VALUES ('$username_safe', '$email_safe', '$hashed_password')";
    
    if (mysqli_query($conn, $insert_sql)) {
        header("Location: index.html?registered=1");
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
