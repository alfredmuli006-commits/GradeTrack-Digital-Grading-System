
<?php
require_once 'auth.php';
require_once 'db.php';

// Only students can access this page.
if (($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('Access denied. This page is for student accounts only.');
}

$studentId = $_SESSION['student_id'] ?? '';

if ($studentId === '') {
    exit('Your account is not linked to a student record. Contact the administrator.');
}

// Retrieve only the logged-in student's information and grades.
$stmt = $conn->prepare(
    "SELECT
        s.student_id,
        s.student_name,
        g.subject,
        g.marks,
        g.grade,
        g.semester_id,
        sem.semester_name,
        ay.year_name
     FROM students s
     LEFT JOIN grades g ON g.student_id = s.student_id
     LEFT JOIN semesters sem ON sem.id = g.semester_id
     LEFT JOIN academic_years ay ON ay.id = sem.academic_year_id
     WHERE s.student_id = ?
     ORDER BY
        ay.start_date DESC,
        sem.start_date DESC,
        g.subject ASC"
);

if (!$stmt) {
    error_log("My Grades query preparation failed: " . $conn->error);
    exit('Unable to load grades. Please contact the administrator.');
}

$stmt->bind_param("s", $studentId);
$stmt->execute();

$result = $stmt->get_result();
$student = null;
$grades = [];

while ($row = $result->fetch_assoc()) {
    if ($student === null) {
        $student = [
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name']
        ];
    }

    if ($row['subject'] !== null) {
        $grades[] = $row;
    }
}

$stmt->close();

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$totalMarks = 0;

foreach ($grades as $grade) {
    $totalMarks += (float)$grade['marks'];
}

$gradeCount = count($grades);
$average = $gradeCount > 0 ? $totalMarks / $gradeCount : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Grades | GradeTrack</title>

<style>
:root {
    --navy: #102447;
    --blue: #2563eb;
    --light-blue: #eff6ff;
    --background: #f4f7fb;
    --text: #1e293b;
    --muted: #64748b;
    --border: #e2e8f0;
    --white: #ffffff;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: var(--background);
    color: var(--text);
}

.header {
    background: var(--navy);
    color: white;
    padding: 18px 7%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.brand {
    font-size: 23px;
    font-weight: bold;
    letter-spacing: .3px;
}

.brand span {
    color: #60a5fa;
}

.header-label {
    font-size: 13px;
    color: #dbeafe;
}

.container {
    width: 90%;
    max-width: 1100px;
    margin: 35px auto;
}

.page-heading {
    margin-bottom: 25px;
}

.page-heading h1 {
    color: var(--navy);
    margin: 0 0 8px;
    font-size: 30px;
}

.page-heading p {
    color: var(--muted);
    margin: 0;
    line-height: 1.6;
}

.student-card {
    background: white;
    padding: 23px;
    border-radius: 12px;
    border: 1px solid var(--border);
    margin-bottom: 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.student-info h2 {
    margin: 0 0 8px;
    color: var(--navy);
    font-size: 21px;
}

.student-info p {
    margin: 5px 0;
    color: var(--muted);
    font-size: 14px;
}

.student-id {
    background: var(--light-blue);
    color: var(--blue);
    padding: 10px 15px;
    border-radius: 8px;
    font-weight: bold;
    font-size: 14px;
}

.summary {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
    margin-bottom: 25px;
}

.summary-card {
    background: white;
    padding: 21px;
    border: 1px solid var(--border);
    border-radius: 12px;
}

.summary-card p {
    color: var(--muted);
    margin: 0 0 10px;
    font-size: 14px;
}

.summary-card h2 {
    color: var(--navy);
    margin: 0;
    font-size: 27px;
}

.grades-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 23px;
}

.grades-card h2 {
    color: var(--navy);
    margin: 0 0 18px;
    font-size: 20px;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th {
    background: var(--navy);
    color: white;
    padding: 15px;
    text-align: left;
    font-size: 13px;
}

td {
    padding: 15px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
}

tbody tr:hover {
    background: #f8fafc;
}

.grade-badge {
    display: inline-block;
    background: #dcfce7;
    color: #166534;
    font-weight: bold;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
}

.marks {
    font-weight: bold;
    color: var(--navy);
}

.semester-badge {
    display: inline-block;
    background: var(--light-blue);
    color: #1e40af;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: bold;
}

.historical-badge {
    display: inline-block;
    background: #f1f5f9;
    color: #475569;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: bold;
}

.message {
    background: var(--light-blue);
    color: #1e40af;
    padding: 16px;
    border-radius: 9px;
    line-height: 1.6;
    font-size: 14px;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 25px;
}

.btn {
    display: inline-block;
    padding: 12px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
    font-size: 14px;
    transition: .2s;
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    background: white;
    color: var(--navy);
    border: 1px solid var(--border);
}

.btn-secondary:hover {
    background: #f1f5f9;
}

.footer {
    text-align: center;
    padding: 25px 10px;
    color: var(--muted);
    font-size: 13px;
}

@media (max-width: 600px) {
    .header {
        padding: 17px 5%;
    }

    .container {
        width: 92%;
        margin: 25px auto;
    }

    .summary {
        grid-template-columns: 1fr;
    }

    .page-heading h1 {
        font-size: 25px;
    }

    .student-card,
    .grades-card {
        padding: 18px;
    }
}
</style>
</head>

<body>

<header class="header">
    <div class="brand">Grade<span>Track</span></div>
    <div class="header-label">Student Portal</div>
</header>

<main class="container">

    <section class="page-heading">
        <h1>My Grades</h1>
        <p>View your academic performance and results in one place.</p>
    </section>

    <?php if ($student): ?>

        <section class="student-card">
            <div class="student-info">
                <h2><?= h($student['student_name']) ?></h2>
                <p>Student account</p>
                <p>Your academic records are displayed below.</p>
            </div>

            <div class="student-id">
                ID: <?= h($student['student_id']) ?>
            </div>
        </section>

        <section class="summary">

            <div class="summary-card">
                <p>Total Subjects</p>
                <h2><?= $gradeCount ?></h2>
            </div>

            <div class="summary-card">
                <p>Average Marks</p>
                <h2>
                    <?= $gradeCount > 0
                        ? number_format($average, 2) . '%'
                        : 'N/A' ?>
                </h2>
            </div>

        </section>

        <section class="grades-card">

            <h2>Academic Results</h2>

            <?php if ($gradeCount > 0): ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject / Unit</th>
                                <th>Academic Year</th>
                                <th>Semester</th>
                                <th>Marks</th>
                                <th>Grade</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($grades as $grade): ?>
                                <tr>
                                    <td><?= h($grade['subject']) ?></td>

                                    <td>
                                        <?= $grade['year_name'] !== null
                                            ? h($grade['year_name'])
                                            : 'Historical / Unassigned' ?>
                                    </td>

                                    <td>
                                        <?php if ($grade['semester_id'] === null): ?>
                                            <span class="historical-badge">
                                                Historical / Unassigned
                                            </span>
                                        <?php else: ?>
                                            <span class="semester-badge">
                                                <?= h($grade['semester_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="marks">
                                        <?= h($grade['marks']) ?> / 100
                                    </td>

                                    <td>
                                        <span class="grade-badge">
                                            <?= h($grade['grade']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <p class="message">
                    No grades have been entered for you yet.
                    Please check again later or contact your teacher.
                </p>

            <?php endif; ?>

        </section>

    <?php else: ?>

        <p class="message">
            Your student record could not be found.
            Please contact the administrator.
        </p>

    <?php endif; ?>

    <div class="actions">
        <a href="dashboard_redirect.php" class="btn btn-primary">
            Back to Dashboard
        </a>

        <a href="logout.php" class="btn btn-secondary">
            Log Out
        </a>
    </div>

</main>

<footer class="footer">
    &copy; <?= date('Y') ?> GradeTrack. Student Grading Management System.
</footer>

</body>
</html>
