<?php
session_start();
session_unset();
session_destroy();
header("Location: admin.php"); // إعادة التوجيه لصفحة الدخول
exit;
?>