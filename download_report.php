
<?php
require_once 'auth.php';
require_once 'db.php';

// Only administrators can download student reports
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can download student reports.');
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Get all registered students
$students = $conn->query(
    "SELECT student_id, student_name, course
     FROM students
     ORDER BY student_name ASC"
);

if (!$students) {
    die("Could not retrieve registered students.");
}

$selected_id = trim($_GET['student_id'] ?? '');
$student = null;
$grades = [];
$average = null;
$studentError = "";

// Generate selected student's report
if ($selected_id !== '') {

    // Find selected student
    $studentStmt = $conn->prepare(
        "SELECT student_id, student_name, course
         FROM students
         WHERE student_id = ?"
    );

    if (!$studentStmt) {
        die("Could not prepare student query.");
    }

    $studentStmt->bind_param("s", $selected_id);
    $studentStmt->execute();

    $studentResult = $studentStmt->get_result();
    $student = $studentResult->fetch_assoc();
    $studentStmt->close();

    if ($student) {

        // Retrieve the student's grades
        $gradeStmt = $conn->prepare(
            "SELECT subject, marks, grade
             FROM grades
             WHERE student_id = ?
             ORDER BY subject ASC"
        );

        if (!$gradeStmt) {
            die("Could not prepare grade query.");
        }

        $gradeStmt->bind_param("s", $selected_id);
        $gradeStmt->execute();

        $gradeResult = $gradeStmt->get_result();

        $total = 0;
        $count = 0;

        while ($row = $gradeResult->fetch_assoc()) {
            $grades[] = $row;
            $total += (float)$row['marks'];
            $count++;
        }

        if ($count > 0) {
            $average = $total / $count;
        }

        $gradeStmt->close();

    } else {
        $studentError = "Student not found. Please select a valid student.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Student Reports | GradeTrack</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f4f7fb;
    color: #172033;
    min-height: 100vh;
}

.topbar {
    background: #101d3a;
    color: white;
    padding: 18px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.brand {
    font-size: 23px;
    font-weight: bold;
}

.brand span {
    color: #4da3ff;
}

.topbar small {
    color: #cbd5e1;
    font-size: 13px;
}

.container {
    max-width: 1000px;
    margin: 40px auto;
    padding: 0 20px;
}

.page-heading {
    margin-bottom: 25px;
}

.page-heading h1 {
    color: #101d3a;
    font-size: 29px;
    margin-bottom: 9px;
}

.page-heading p {
    color: #64748b;
    line-height: 1.6;
}

.card {
    background: white;
    padding: 27px;
    border-radius: 12px;
    margin-bottom: 23px;
    border: 1px solid #e5eaf2;
    box-shadow: 0 4px 15px rgba(15, 23, 42, .04);
}

.card h2 {
    color: #101d3a;
    font-size: 20px;
    margin-bottom: 9px;
}

.card-description {
    color: #64748b;
    font-size: 13px;
    margin-bottom: 22px;
    line-height: 1.6;
}

label {
    display: block;
    margin-bottom: 10px;
    font-size: 14px;
    font-weight: bold;
    color: #334155;
}

select {
    width: 100%;
    padding: 13px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: white;
    font-size: 14px;
    outline: none;
}

select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
}

button, .back-link {
    display: inline-block;
    padding: 12px 19px;
    margin-top: 17px;
    border: none;
    border-radius: 7px;
    background: #1d4ed8;
    color: white;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
}

button:hover {
    background: #2563eb;
}

.back-link {
    background: #101d3a;
    margin-left: 8px;
}

.back-link:hover {
    background: #1c315b;
}

.error {
    background: #fff0f0;
    color: #b42318;
    border: 1px solid #f2c2c2;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.report {
    padding: 35px;
}

.report-header {
    text-align: center;
    border-bottom: 2px solid #101d3a;
    padding-bottom: 22px;
    margin-bottom: 25px;
}

.report-logo {
    font-size: 25px;
    font-weight: bold;
    color: #101d3a;
    margin-bottom: 12px;
}

.report-logo span {
    color: #1d4ed8;
}

.report-header h2 {
    font-size: 21px;
    margin-bottom: 8px;
    letter-spacing: .5px;
}

.report-header p {
    color: #64748b;
    font-size: 13px;
}

.details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.detail-item {
    background: #f8fafc;
    border: 1px solid #e5eaf2;
    padding: 14px;
    border-radius: 7px;
}

.detail-item span {
    display: block;
    color: #64748b;
    font-size: 12px;
    margin-bottom: 7px;
}

.detail-item strong {
    color: #172033;
    font-size: 14px;
}

.section-title {
    color: #101d3a;
    font-size: 17px;
    margin-bottom: 15px;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 450px;
}

th, td {
    border: 1px solid #e2e8f0;
    padding: 13px;
    text-align: left;
    font-size: 13px;
}

th {
    background: #101d3a;
    color: white;
}

tbody tr:nth-child(even) {
    background: #f8fafc;
}

.summary {
    margin-top: 25px;
    padding: 20px;
    background: #eaf3ff;
    border: 1px solid #cfe2ff;
    border-radius: 9px;
}

.summary h3 {
    color: #101d3a;
    margin-bottom: 13px;
    font-size: 17px;
}

.summary p {
    margin: 9px 0;
    font-size: 14px;
    color: #334155;
}

.signature {
    margin-top: 45px;
    font-size: 13px;
    color: #334155;
}

.print-area {
    text-align: center;
    margin: 20px 0 35px;
}

.print-button {
    background: #168044;
    padding: 14px 23px;
}

.print-button:hover {
    background: #126b37;
}

.empty-message {
    text-align: center;
    padding: 22px;
    color: #64748b;
}

footer {
    text-align: center;
    padding: 25px;
    color: #94a3b8;
    font-size: 12px;
}

@media (max-width: 600px) {
    .topbar {
        padding: 17px 20px;
    }

    .container {
        margin: 25px auto;
    }

    .page-heading h1 {
        font-size: 24px;
    }

    .card {
        padding: 19px;
    }

    .report {
        padding: 20px;
    }

    .back-link {
        margin-left: 0;
    }
}

@media print {

    @page {
        size: A4;
        margin: 18mm;
    }

    body {
        background: white;
        color: black;
        padding: 0;
    }

    .no-print {
        display: none !important;
    }

    .container {
        max-width: 100%;
        margin: 0;
        padding: 0;
    }

    .report {
        border: none;
        box-shadow: none;
        padding: 0;
        margin: 0;
    }

    .report-header,
    .details,
    .summary,
    table {
        break-inside: avoid;
    }

    th {
        background: #101d3a !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .summary {
        background: #eaf3ff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

</head>

<body>

<header class="topbar no-print">
    <div class="brand">Grade<span>Track</span></div>
    <small>Administrator Portal</small>
</header>

<div class="container">

    <section class="page-heading no-print">
        <h1>Download Student Reports</h1>
        <p>Generate and save individual student academic performance reports.</p>
    </section>

    <section class="card no-print">

        <h2>Generate Academic Report</h2>

        <p class="card-description">
            Select a registered student below to generate their academic report.
        </p>

        <form method="GET" action="download_report.php">

            <label for="student_id">Select Student</label>

            <select name="student_id" id="student_id" required>

                <option value="">-- Choose Student --</option>

                <?php while ($s = $students->fetch_assoc()): ?>

                    <option
                        value="<?php echo h($s['student_id']); ?>"
                        <?php echo $selected_id === $s['student_id'] ? 'selected' : ''; ?>
                    >
                        <?php echo h($s['student_name']); ?>
                        (<?php echo h($s['student_id']); ?>)
                    </option>

                <?php endwhile; ?>

            </select>

            <button type="submit">Generate Report</button>

            <a href="dashboard_redirect.php" class="back-link">← Back to Dashboard</a>

        </form>

    </section>

    <?php if ($studentError !== ''): ?>

        <div class="error no-print">
            <?php echo h($studentError); ?>
        </div>

    <?php elseif ($student): ?>

        <section class="card report" id="student-report">

            <div class="report-header">

                <div class="report-logo">
                    Grade<span>Track</span>
                </div>

                <h2>STUDENT ACADEMIC REPORT</h2>

                <p>Official Academic Performance Record</p>

            </div>

            <div class="details">

                <div class="detail-item">
                    <span>Student Name</span>
                    <strong><?php echo h($student['student_name']); ?></strong>
                </div>

                <div class="detail-item">
                    <span>Student ID</span>
                    <strong><?php echo h($student['student_id']); ?></strong>
                </div>

                <div class="detail-item">
                    <span>Course</span>
                    <strong><?php echo h($student['course']); ?></strong>
                </div>

                <div class="detail-item">
                    <span>Report Date</span>
                    <strong><?php echo date('d F Y'); ?></strong>
                </div>

            </div>

            <h3 class="section-title">Academic Results</h3>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Marks</th>
                            <th>Grade</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($grades) > 0): ?>

                            <?php foreach ($grades as $g): ?>

                                <tr>
                                    <td><?php echo h($g['subject']); ?></td>
                                    <td><?php echo h($g['marks']); ?>%</td>
                                    <td><?php echo h($g['grade']); ?></td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="3" class="empty-message">
                                    No grades recorded for this student.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div class="summary">

                <h3>Performance Summary</h3>

                <p>
                    <strong>Total Subjects:</strong>
                    <?php echo count($grades); ?>
                </p>

                <p>
                    <strong>Average Marks:</strong>
                    <?php
                    echo $average !== null
                        ? number_format($average, 2) . '%'
                        : 'Not available';
                    ?>
                </p>

            </div>

            <div class="signature">
                <strong>Administrator's Signature:</strong>
                ______________________________
            </div>

        </section>

        <?php if (count($grades) > 0): ?>

            <div class="print-area no-print">
                <button class="print-button" onclick="window.print()">
                    Download / Save Report as PDF
                </button>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<footer class="no-print">
    © <?php echo date('Y'); ?> GradeTrack. Digital Grading Management System.
</footer>

</body>
</html>
