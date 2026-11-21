
<?php
require_once 'auth.php';

$role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? 'User';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Grading System</title>
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}
header {
    background: #172554;
    color: white;
    padding: 22px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}
header h1 { margin: 0; font-size: 23px; }
header a {
    color: white;
    text-decoration: none;
    background: #dc2626;
    padding: 10px 15px;
    border-radius: 7px;
}
main { max-width: 1050px; margin: 40px auto; padding: 0 20px; }
.welcome { margin-bottom: 28px; }
.welcome h2 { margin-bottom: 8px; }
.welcome p { color: #64748b; }
.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 20px;
}
.card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(0,0,0,.06);
}
.card h3 { margin-top: 0; color: #1d4ed8; }
.card p { color: #64748b; line-height: 1.5; }
.card a {
    display: inline-block;
    background: #1d4ed8;
    color: white;
    padding: 11px 15px;
    border-radius: 7px;
    text-decoration: none;
    margin-top: 8px;
}
.notice {
    background: #dbeafe;
    padding: 15px;
    border-radius: 8px;
    margin-top: 20px;
}
footer { text-align: center; padding: 25px; color: #64748b; }
</style>
</head>
<body>
<header>
    <h1>Digital Grading System</h1>
    <a href="logout.php">Log Out</a>
</header>

<main>
    <section class="welcome">
        <h2>Welcome, <?= h($username) ?>!</h2>
        <p>Your role: <strong><?= h(ucfirst($role)) ?></strong></p>
    </section>

    <section class="cards">
        <?php if ($role === 'admin'): ?>
            <div class="card">
                <h3>Student Registration</h3>
                <p>Register students in the grading system.</p>
                <a href="add_student.php">Manage Students</a>
            </div>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'teacher'): ?>
            <div class="card">
                <h3>Grade Entry</h3>
                <p>Enter marks and validate grading data.</p>
                <a href="add_grade.php">Enter Grades</a>
            </div>
            <div class="card">
                <h3>View Grades</h3>
                <p>View grading records.</p>
                <a href="view_grades.php">View Grades</a>
            </div>
        <?php endif; ?>

        <?php if ($role === 'student'): ?>
            <div class="card">
                <h3>My Grades</h3>
                <p>View your own marks and grades.</p>
                <a href="my_grades.php">View My Grades</a>
            </div>
        <?php endif; ?>

        <?php if (!in_array($role, ['admin', 'teacher', 'student'], true)): ?>
            <div class="notice">
                Your account role is not recognized. Please contact the administrator.
            </div>
        <?php endif; ?>
    </section>

    <?php if ($role === 'student'): ?>
        <div class="notice">
            For privacy, student accounts should only display their own grades.
        </div>
    <?php endif; ?>
</main>

<footer>Digital Grading System | Data Integrity and Validation</footer>
</body>
</html>