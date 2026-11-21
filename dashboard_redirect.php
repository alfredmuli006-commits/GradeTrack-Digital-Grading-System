
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_SESSION['role'] ?? '';

if ($role === 'admin') {
    header('Location: admin_dashboard.php');
} elseif ($role === 'teacher') {
    header('Location: teacher_dashboard.php');
} elseif ($role === 'student') {
    header('Location: index.php');
} else {
    header('Location: login.php');
}

exit;
