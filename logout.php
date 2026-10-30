<?php
session_start();

// تفريغ متغيرات الجلسة
session_unset();

// إنهاء الجلسة
session_destroy();

// العودة إلى صفحة تسجيل الدخول
header("Location: login.html?logout=1");
exit();
?>
