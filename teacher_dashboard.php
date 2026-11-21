
<?php
require_once 'auth.php';
require_once 'db.php';

// Only teachers can access this dashboard.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    exit('Access denied. Teachers only.');
}

$teacher_id = (int)$_SESSION['user_id'];

// Fetch active units assigned to this teacher.
$units = [];

$unit_query = $conn->prepare("
    SELECT u.id, u.unit_code, u.unit_name
    FROM teacher_units tu
    INNER JOIN units u ON tu.unit_id = u.id
    WHERE tu.teacher_id = ?
      AND tu.status = 'active'
      AND u.status = 'active'
    ORDER BY u.unit_name ASC
");

if (!$unit_query) {
    error_log("Unit query failed: " . $conn->error);
    exit("Unable to load assigned units.");
}

$unit_query->bind_param("i", $teacher_id);
$unit_query->execute();

$unit_result = $unit_query->get_result();

while ($row = $unit_result->fetch_assoc()) {
    $units[] = $row;
}

$unit_query->close();

$unit_count = count($units);

// Count registered students.
$student_count = 0;

$student_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM students
");

if ($student_result) {
    $student_count = (int)$student_result->fetch_assoc()['total'];
}

// Count grades recorded for this teacher's active assigned units.
$grade_count = 0;

$grade_query = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM grades
    WHERE subject IN (
        SELECT u.unit_name
        FROM teacher_units tu
        INNER JOIN units u ON tu.unit_id = u.id
        WHERE tu.teacher_id = ?
          AND tu.status = 'active'
          AND u.status = 'active'
    )
");

if (!$grade_query) {
    error_log("Grade query failed: " . $conn->error);
    exit("Unable to load grade statistics.");
}

$grade_query->bind_param("i", $teacher_id);
$grade_query->execute();

$grade_result = $grade_query->get_result();
$grade_count = (int)$grade_result->fetch_assoc()['total'];

$grade_query->close();

// Get teacher username.
$username = htmlspecialchars(
    $_SESSION['username'] ?? 'Teacher',
    ENT_QUOTES,
    'UTF-8'
);
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Teacher Dashboard | GradeTrack</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
}

body {
    background: #f1f5f9;
    color: #1e293b;
}

/* Header */

.header {
    background: #172554;
    color: white;
    padding: 22px 35px;
}

.header h1 {
    font-size: 25px;
    margin-bottom: 6px;
}

.header p {
    color: #cbd5e1;
    font-size: 14px;
}

/* Main container */

.container {
    max-width: 1150px;
    margin: 35px auto;
    padding: 0 20px;
}

.welcome {
    margin-bottom: 25px;
}

.welcome h2 {
    font-size: 24px;
    margin-bottom: 8px;
}

.welcome p {
    color: #64748b;
    line-height: 1.6;
}

/* Statistics */

.stats {
    display: grid;
    grid-template-columns: repeat(
        auto-fit,
        minmax(220px, 1fr)
    );
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
    border-left: 5px solid #2563eb;
}

.stat-card h3 {
    font-size: 15px;
    color: #64748b;
    margin-bottom: 15px;
}

.stat-card .number {
    font-size: 32px;
    font-weight: bold;
    color: #172554;
}

/* Sections */

.section {
    background: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
}

.section h2 {
    font-size: 19px;
    margin-bottom: 20px;
}

/* Units */

.unit-list {
    display: grid;
    grid-template-columns: repeat(
        auto-fit,
        minmax(220px, 1fr)
    );
    gap: 15px;
}

.unit {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 18px;
    border-radius: 9px;
}

.unit h3 {
    font-size: 16px;
    margin-bottom: 8px;
    color: #172554;
}

.unit p {
    color: #64748b;
    font-size: 14px;
}

.empty {
    color: #64748b;
    padding: 15px 0;
}

/* Buttons */

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.btn {
    display: inline-block;
    text-decoration: none;
    padding: 13px 20px;
    border-radius: 7px;
    font-weight: bold;
    font-size: 14px;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    background: #e2e8f0;
    color: #172554;
}

.btn-secondary:hover {
    background: #cbd5e1;
}

/* Footer */

.footer {
    text-align: center;
    padding: 20px;
    color: #64748b;
    font-size: 13px;
}

/* Mobile */

@media (max-width: 600px) {

    .header {
        padding: 20px;
    }

    .container {
        margin-top: 25px;
    }

    .section {
        padding: 20px;
    }

}

</style>

</head>

<body>

<header class="header">

    <h1>GradeTrack</h1>

    <p>Teacher Management Portal</p>

</header>

<main class="container">

    <div class="welcome">

        <h2>
            Welcome, <?= $username ?>!
        </h2>

        <p>
            Manage your assigned units, enter student grades
            and monitor your grading activities.
        </p>

    </div>

    <div class="stats">

        <div class="stat-card">

            <h3>Assigned Units</h3>

            <div class="number">
                <?= $unit_count ?>
            </div>

        </div>

        <div class="stat-card">

            <h3>Total Students</h3>

            <div class="number">
                <?= $student_count ?>
            </div>

        </div>

        <div class="stat-card">

            <h3>Grades Recorded for Your Units</h3>

            <div class="number">
                <?= $grade_count ?>
            </div>

        </div>

    </div>

    <section class="section">

        <h2>My Assigned Units</h2>

        <?php if (!empty($units)): ?>

            <div class="unit-list">

                <?php foreach ($units as $unit): ?>

                    <div class="unit">

                        <h3>
                            <?= htmlspecialchars(
                                $unit['unit_name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </h3>

                        <p>
                            Unit Code:

                            <?= htmlspecialchars(
                                $unit['unit_code'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <p class="empty">
                You currently have no active units assigned.
                Please contact the administrator.
            </p>

        <?php endif; ?>

    </section>

    <section class="section">

        <h2>Quick Actions</h2>

        <div class="actions">

            <a href="add_grade.php" class="btn btn-primary">
                Enter Student Grades
            </a>

            <a href="view_grades.php" class="btn btn-secondary">
                View Student Grades
            </a>

            <a href="change_password.php" class="btn btn-secondary">
                Change Password
            </a>

            <a href="logout.php" class="btn btn-secondary">
                Log Out
            </a>

        </div>

    </section>

</main>

<footer class="footer">

    GradeTrack Digital Grading System

</footer>

</body>

</html>
