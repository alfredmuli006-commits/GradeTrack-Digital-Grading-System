
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "db.php";

// ADMIN ACCESS ONLY
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}

// Helper function for safe output.
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// INITIALIZE DASHBOARD VALUES
$total_students = 0;
$total_teachers = 0;
$total_units = 0;
$total_grades = 0;
$average_marks = 0;
$pass_rate = 0;
$fail_rate = 0;

$grade_distribution = [
    'A' => 0,
    'B' => 0,
    'C' => 0,
    'D' => 0,
    'F' => 0
];

$top_units = [];
$recent_activities = [];
$dashboard_errors = [];

// 1. TOTAL STUDENTS
$result = $conn->query("SELECT COUNT(*) AS total FROM students");

if ($result) {
    $total_students = (int)$result->fetch_assoc()['total'];
} else {
    $dashboard_errors[] = "Unable to load student statistics.";
}

// 2. TOTAL TEACHERS
$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'teacher'
");

if ($result) {
    $total_teachers = (int)$result->fetch_assoc()['total'];
} else {
    $dashboard_errors[] = "Unable to load teacher statistics.";
}

// 3. ACTIVE UNITS
$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM units
    WHERE status = 'active'
");

if ($result) {
    $total_units = (int)$result->fetch_assoc()['total'];
} else {
    $dashboard_errors[] = "Unable to load unit statistics.";
}

// 4. GRADE ANALYTICS
$result = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(AVG(marks), 0) AS average_marks,
        SUM(CASE WHEN marks >= 50 THEN 1 ELSE 0 END) AS passed,
        SUM(CASE WHEN marks < 50 THEN 1 ELSE 0 END) AS failed
    FROM grades
");

if ($result) {
    $analytics = $result->fetch_assoc();

    $total_grades = (int)$analytics['total'];
    $average_marks = (float)$analytics['average_marks'];

    if ($total_grades > 0) {
        $pass_rate = ((int)$analytics['passed'] / $total_grades) * 100;
        $fail_rate = ((int)$analytics['failed'] / $total_grades) * 100;
    }
} else {
    $dashboard_errors[] = "Unable to load grade analytics.";
}

// 5. GRADE DISTRIBUTION
$result = $conn->query("
    SELECT UPPER(TRIM(grade)) AS grade_name, COUNT(*) AS total
    FROM grades
    GROUP BY UPPER(TRIM(grade))
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $grade_name = $row['grade_name'];

        if (isset($grade_distribution[$grade_name])) {
            $grade_distribution[$grade_name] = (int)$row['total'];
        }
    }
} else {
    $dashboard_errors[] = "Unable to load grade distribution.";
}

// 6. TOP PERFORMING UNITS
$result = $conn->query("
    SELECT
        COALESCE(NULLIF(u.unit_name, ''), g.subject) AS unit_name,
        COUNT(*) AS total_grades,
        ROUND(AVG(g.marks), 2) AS average_marks
    FROM grades g
    LEFT JOIN units u ON g.unit_id = u.id
    GROUP BY COALESCE(NULLIF(u.unit_name, ''), g.subject)
    ORDER BY average_marks DESC
    LIMIT 5
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $top_units[] = $row;
    }
} else {
    $dashboard_errors[] = "Unable to load unit performance.";
}

// 7. RECENT SYSTEM ACTIVITY
$result = $conn->query("
    SELECT
        a.action,
        a.entity_type,
        a.description,
        a.created_at,
        COALESCE(u.username, 'Unknown user') AS username
    FROM system_audit_logs a
    LEFT JOIN users u ON u.id = a.actor_user_id
    ORDER BY a.created_at DESC
    LIMIT 10
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
} else {
    $dashboard_errors[] = "Unable to load recent system activity.";
}

// GRADE DISTRIBUTION BAR WIDTHS
$max_grade_count = max(1, max($grade_distribution));

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>GradeTrack | Admin Dashboard</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}

header {
    background: #172554;
    color: white;
    padding: 25px 35px;
}

.header-content {
    max-width: 1250px;
    margin: auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

header h1 {
    margin: 0;
    font-size: 26px;
}

header p {
    color: #cbd5e1;
    margin-bottom: 0;
}

.container {
    max-width: 1250px;
    margin: 30px auto;
    padding: 0 20px;
}

.section-title {
    margin: 30px 0 18px;
    color: #172554;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 20px;
}

.stat-card {
    background: white;
    padding: 23px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
    border-left: 5px solid #2563eb;
}

.stat-card p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}

.stat-card h2 {
    margin: 12px 0 5px;
    font-size: 32px;
    color: #172554;
}

.stat-card small {
    color: #64748b;
}

.stat-card.green {
    border-left-color: #16a34a;
}

.stat-card.orange {
    border-left-color: #f59e0b;
}

.stat-card.red {
    border-left-color: #dc2626;
}

.stat-card.purple {
    border-left-color: #9333ea;
}

.analytics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.panel {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
}

.panel h3 {
    margin-top: 0;
    color: #172554;
}

.progress-track {
    height: 12px;
    background: #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    margin: 12px 0 20px;
}

.progress-fill {
    height: 100%;
    border-radius: 20px;
}

.pass-fill {
    background: #16a34a;
}

.fail-fill {
    background: #dc2626;
}

.grade-row {
    margin: 18px 0;
}

.grade-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 7px;
    font-size: 14px;
}

.grade-track {
    background: #e2e8f0;
    height: 11px;
    border-radius: 20px;
    overflow: hidden;
}

.grade-fill {
    background: #2563eb;
    height: 100%;
    border-radius: 20px;
}

.unit-table,
.activity-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 550px;
}

.unit-table th,
.unit-table td,
.activity-table th,
.activity-table td {
    padding: 14px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.unit-table th,
.activity-table th {
    background: #f8fafc;
    color: #172554;
}

.table-container {
    overflow-x: auto;
}

.menu {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 15px;
}

.menu a {
    display: block;
    padding: 20px;
    background: white;
    border-radius: 10px;
    text-decoration: none;
    color: #172554;
    font-weight: bold;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
    transition: 0.2s;
}

.menu a:hover {
    background: #dbeafe;
    transform: translateY(-2px);
}

.error-box {
    background: #fee2e2;
    color: #991b1b;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.empty {
    text-align: center;
    color: #64748b;
    padding: 25px;
}

.logout {
    display: inline-block;
    margin: 30px 0;
    background: #dc2626;
    color: white;
    padding: 13px 25px;
    border-radius: 7px;
    text-decoration: none;
    font-weight: bold;
}

.logout:hover {
    background: #b91c1c;
}

footer {
    text-align: center;
    padding: 25px;
    color: #64748b;
}

@media(max-width: 600px) {
    header {
        padding: 20px;
    }

    .container {
        padding: 0 12px;
    }

    .panel {
        padding: 17px;
    }
}

</style>

</head>

<body>

<header>

    <div class="header-content">

        <div>
            <h1>GradeTrack</h1>
            <p>Administrator Control Centre</p>
        </div>

        <div>
            Welcome, <strong><?php echo e($_SESSION['username'] ?? 'Administrator'); ?></strong>
        </div>

    </div>

</header>

<div class="container">

    <?php if (!empty($dashboard_errors)): ?>

        <div class="error-box">
            <strong>Some dashboard information could not be loaded:</strong>

            <ul>
                <?php foreach ($dashboard_errors as $error): ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <h2 class="section-title">System Overview</h2>

    <div class="stats">

        <div class="stat-card">
            <p>Total Registered Students</p>
            <h2><?php echo number_format($total_students); ?></h2>
            <small>Student accounts</small>
        </div>

        <div class="stat-card green">
            <p>Total Teachers</p>
            <h2><?php echo number_format($total_teachers); ?></h2>
            <small>Registered teachers</small>
        </div>

        <div class="stat-card orange">
            <p>Active Units</p>
            <h2><?php echo number_format($total_units); ?></h2>
            <small>Available academic units</small>
        </div>

        <div class="stat-card purple">
            <p>Total Grades</p>
            <h2><?php echo number_format($total_grades); ?></h2>
            <small>Recorded student results</small>
        </div>

    </div>

    <h2 class="section-title">Academic Performance</h2>

    <div class="stats">

        <div class="stat-card green">
            <p>Pass Rate</p>
            <h2><?php echo number_format($pass_rate, 1); ?>%</h2>
            <small>Marks of 50 and above</small>
        </div>

        <div class="stat-card red">
            <p>Failure Rate</p>
            <h2><?php echo number_format($fail_rate, 1); ?>%</h2>
            <small>Marks below 50</small>
        </div>

        <div class="stat-card">
            <p>Average Marks</p>
            <h2><?php echo number_format($average_marks, 1); ?>%</h2>
            <small>Overall recorded average</small>
        </div>

    </div>

    <div class="analytics-grid" style="margin-top:20px;">

        <div class="panel">

            <h3>Pass and Failure Analysis</h3>

            <p>
                Pass Rate:
                <strong><?php echo number_format($pass_rate, 1); ?>%</strong>
            </p>

            <div class="progress-track">
                <div class="progress-fill pass-fill"
                     style="width:<?php echo min(100, max(0, $pass_rate)); ?>%;">
                </div>
            </div>

            <p>
                Failure Rate:
                <strong><?php echo number_format($fail_rate, 1); ?>%</strong>
            </p>

            <div class="progress-track">
                <div class="progress-fill fail-fill"
                     style="width:<?php echo min(100, max(0, $fail_rate)); ?>%;">
                </div>
            </div>

            <small>
                Based on <?php echo number_format($total_grades); ?> recorded grades.
            </small>

        </div>

        <div class="panel">

            <h3>Grade Distribution</h3>

            <?php foreach ($grade_distribution as $grade => $count): ?>

                <div class="grade-row">

                    <div class="grade-label">
                        <strong>Grade <?php echo e($grade); ?></strong>
                        <span><?php echo number_format($count); ?></span>
                    </div>

                    <div class="grade-track">

                        <div class="grade-fill"
                             style="width:<?php echo ($count / $max_grade_count) * 100; ?>%;">
                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

    <div class="panel" style="margin-top:20px;">

        <h3>Top Performing Units</h3>

        <div class="table-container">

            <?php if (empty($top_units)): ?>

                <div class="empty">
                    No unit performance data available yet.
                </div>

            <?php else: ?>

                <table class="unit-table">

                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Grades Recorded</th>
                            <th>Average Marks</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($top_units as $unit): ?>

                            <tr>
                                <td><?php echo e($unit['unit_name']); ?></td>

                                <td>
                                    <?php echo number_format((int)$unit['total_grades']); ?>
                                </td>

                                <td>
                                    <strong>
                                        <?php echo number_format((float)$unit['average_marks'], 2); ?>%
                                    </strong>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

    </div>

    <h2 class="section-title">Administration</h2>

    <div class="menu">

        <a href="add_student.php">Register Student</a>

        <a href="add_teacher.php">Register Teacher</a>

        <a href="manage_teachers.php">Manage Teachers</a>

        <a href="manage_units.php">Manage Academic Units</a>

        <a href="assign_units.php">Assign Units to Teachers</a>

        <a href="manage_reports.php">Manage Reports</a>

        <a href="download_report.php">Download Student Reports</a>

        <a href="student_transcript.php">Student Academic Transcripts</a>

        <a href="manage_academic_years.php">Manage Academic Years</a>

        <a href="manage_semesters.php">Manage Semesters</a>

        <a href="audit_logs.php">View Audit Logs</a>

    </div>

    <h2 class="section-title">Recent System Activity</h2>

    <div class="panel">

        <div class="table-container">

            <?php if (empty($recent_activities)): ?>

                <div class="empty">
                    No system activities have been recorded yet.
                </div>

            <?php else: ?>

                <table class="activity-table">

                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Description</th>
                            <th>Date and Time</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($recent_activities as $activity): ?>

                            <tr>

                                <td><?php echo e($activity['username']); ?></td>

                                <td><?php echo e($activity['action']); ?></td>

                                <td><?php echo e($activity['entity_type']); ?></td>

                                <td><?php echo e($activity['description']); ?></td>

                                <td>
                                    <?php
                                    $timestamp = strtotime($activity['created_at']);
                                    echo $timestamp
                                        ? e(date('d M Y, H:i', $timestamp))
                                        : 'Unknown date';
                                    ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

        <p>
            <a href="audit_logs.php">View All System Activities →</a>
        </p>

    </div>

    <a class="logout" href="logout.php">Logout</a>

</div>

<footer>
    GradeTrack Digital Grading System
</footer>

</body>
</html>
