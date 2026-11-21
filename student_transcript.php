
<?php
require_once 'auth.php';
require_once 'db.php';

// Administrators only.
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can generate transcripts.');
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$selectedId = trim($_GET['student_id'] ?? '');
$student = null;
$groups = [];
$grades = [];
$totalMarks = 0;
$totalRecords = 0;
$passed = 0;
$error = '';

// Load registered students.
$students = $conn->query("
    SELECT student_id, student_name, course
    FROM students
    ORDER BY student_name ASC
");

if (!$students) {
    die('Could not retrieve students.');
}

// Generate selected student's transcript.
if ($selectedId !== '') {

    $studentStmt = $conn->prepare("
        SELECT student_id, student_name, course
        FROM students
        WHERE student_id = ?
    ");

    if (!$studentStmt) {
        die('Could not prepare student query.');
    }

    $studentStmt->bind_param("s", $selectedId);
    $studentStmt->execute();

    $student = $studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();

    if (!$student) {
        $error = 'Student not found.';
    } else {

        $gradeStmt = $conn->prepare("
            SELECT
                g.subject,
                g.marks,
                g.grade,
                g.semester_id,
                sem.semester_name,
                ay.year_name,
                ay.start_date,
                sem.start_date AS semester_start
            FROM grades g
            LEFT JOIN semesters sem
                ON g.semester_id = sem.id
            LEFT JOIN academic_years ay
                ON sem.academic_year_id = ay.id
            WHERE g.student_id = ?
            ORDER BY
                ay.start_date ASC,
                sem.start_date ASC,
                g.subject ASC
        ");

        if (!$gradeStmt) {
            die('Could not prepare grades query.');
        }

        $gradeStmt->bind_param("s", $selectedId);
        $gradeStmt->execute();

        $gradeResult = $gradeStmt->get_result();

        while ($row = $gradeResult->fetch_assoc()) {

            if ($row['semester_id'] === null) {
                $groupName = 'Historical / Unassigned';
                $yearName = 'Historical / Unassigned';
            } else {
                $yearName = $row['year_name'] ?? 'Unknown Year';
                $groupName = $yearName . ' - ' .
                    ($row['semester_name'] ?? 'Unknown Semester');
            }

            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [
                    'year' => $yearName,
                    'semester' => $row['semester_name'] ?? 'Historical / Unassigned',
                    'records' => []
                ];
            }

            $groups[$groupName]['records'][] = $row;

            $grades[] = $row;
            $totalMarks += (float)$row['marks'];
            $totalRecords++;

            if ((float)$row['marks'] >= 50) {
                $passed++;
            }
        }

        $gradeStmt->close();
    }
}

$average = $totalRecords > 0
    ? $totalMarks / $totalRecords
    : null;

$passRate = $totalRecords > 0
    ? ($passed / $totalRecords) * 100
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Academic Transcript | GradeTrack</title>

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
}

header {
    background: #101d3a;
    color: white;
    padding: 18px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.brand {
    font-size: 23px;
    font-weight: bold;
}

.brand span {
    color: #4da3ff;
}

header small {
    color: #cbd5e1;
}

.container {
    max-width: 1000px;
    margin: 35px auto;
    padding: 0 20px;
}

.heading {
    margin-bottom: 25px;
}

.heading h1 {
    color: #101d3a;
    margin-bottom: 8px;
}

.heading p {
    color: #64748b;
    line-height: 1.6;
}

.card {
    background: white;
    border: 1px solid #e5eaf2;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 22px;
    box-shadow: 0 4px 15px rgba(15, 23, 42, .04);
}

label {
    display: block;
    font-weight: bold;
    font-size: 14px;
    margin-bottom: 10px;
}

select {
    width: 100%;
    padding: 13px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 14px;
    background: white;
}

button, .back-link {
    display: inline-block;
    margin-top: 15px;
    padding: 12px 18px;
    border: none;
    border-radius: 7px;
    background: #1d4ed8;
    color: white;
    font-weight: bold;
    font-size: 13px;
    text-decoration: none;
    cursor: pointer;
}

.back-link {
    background: #101d3a;
    margin-left: 7px;
}

button:hover {
    background: #2563eb;
}

.error {
    padding: 15px;
    background: #fff0f0;
    color: #b42318;
    border-radius: 7px;
    margin-bottom: 20px;
}

.transcript-header {
    text-align: center;
    padding-bottom: 22px;
    border-bottom: 2px solid #101d3a;
    margin-bottom: 25px;
}

.transcript-header h2 {
    margin: 12px 0 7px;
    color: #101d3a;
}

.transcript-header p {
    color: #64748b;
    font-size: 13px;
}

.student-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 25px;
}

.detail {
    background: #f8fafc;
    padding: 15px;
    border: 1px solid #e5eaf2;
    border-radius: 7px;
}

.detail span {
    display: block;
    color: #64748b;
    font-size: 12px;
    margin-bottom: 7px;
}

.detail strong {
    color: #172033;
    font-size: 14px;
}

.semester-section {
    margin-top: 25px;
}

.semester-section h3 {
    color: #101d3a;
    font-size: 16px;
    margin-bottom: 12px;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 450px;
}

th, td {
    padding: 13px;
    border: 1px solid #e2e8f0;
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
    margin-bottom: 13px;
    color: #101d3a;
}

.summary p {
    margin: 9px 0;
    font-size: 14px;
}

.signature {
    margin-top: 45px;
    font-size: 13px;
}

.print-area {
    text-align: center;
    margin: 25px 0;
}

.print-button {
    background: #168044;
}

.empty {
    padding: 25px;
    text-align: center;
    color: #64748b;
}

footer {
    text-align: center;
    padding: 25px;
    color: #94a3b8;
    font-size: 12px;
}

@media print {
    @page {
        size: A4;
        margin: 15mm;
    }

    body {
        background: white;
    }

    .no-print {
        display: none !important;
    }

    .container {
        max-width: 100%;
        margin: 0;
        padding: 0;
    }

    .card {
        border: none;
        box-shadow: none;
        padding: 0;
    }

    .semester-section,
    .student-details,
    .summary,
    .transcript-header {
        break-inside: avoid;
    }

    th {
        background: #101d3a !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

</head>

<body>

<header class="no-print">
    <div class="brand">Grade<span>Track</span></div>
    <small>Administrator Portal</small>
</header>

<div class="container">

    <section class="heading no-print">
        <h1>Student Academic Transcripts</h1>
        <p>Generate complete academic transcripts grouped by academic year and semester.</p>
    </section>

    <section class="card no-print">

        <form method="GET" action="student_transcript.php">

            <label for="student_id">Select Student</label>

            <select name="student_id" id="student_id" required>

                <option value="">-- Choose Student --</option>

                <?php while ($s = $students->fetch_assoc()): ?>

                    <option
                        value="<?php echo h($s['student_id']); ?>"
                        <?php echo $selectedId === $s['student_id'] ? 'selected' : ''; ?>
                    >
                        <?php echo h($s['student_name']); ?>
                        (<?php echo h($s['student_id']); ?>)
                    </option>

                <?php endwhile; ?>

            </select>

            <button type="submit">Generate Transcript</button>

            <a href="dashboard_redirect.php" class="back-link">
                Back to Dashboard
            </a>

        </form>

    </section>

    <?php if ($error !== ''): ?>

        <div class="error"><?php echo h($error); ?></div>

    <?php elseif ($student): ?>

        <section class="card" id="transcript">

            <div class="transcript-header">

                <div class="brand">
                    Grade<span>Track</span>
                </div>

                <h2>OFFICIAL ACADEMIC TRANSCRIPT</h2>

                <p>Digital Grading Management System</p>

            </div>

            <div class="student-details">

                <div class="detail">
                    <span>Student Name</span>
                    <strong><?php echo h($student['student_name']); ?></strong>
                </div>

                <div class="detail">
                    <span>Student ID</span>
                    <strong><?php echo h($student['student_id']); ?></strong>
                </div>

                <div class="detail">
                    <span>Course</span>
                    <strong><?php echo h($student['course']); ?></strong>
                </div>

                <div class="detail">
                    <span>Transcript Date</span>
                    <strong><?php echo date('d F Y'); ?></strong>
                </div>

            </div>

            <h3>Academic Results</h3>

            <?php if ($totalRecords > 0): ?>

                <?php foreach ($groups as $groupName => $group): ?>

                    <div class="semester-section">

                        <h3><?php echo h($groupName); ?></h3>

                        <div class="table-wrapper">

                            <table>

                                <thead>
                                    <tr>
                                        <th>Subject / Unit</th>
                                        <th>Marks</th>
                                        <th>Grade</th>
                                        <th>Result</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($group['records'] as $record): ?>

                                        <tr>

                                            <td><?php echo h($record['subject']); ?></td>

                                            <td><?php echo number_format((float)$record['marks'], 2); ?>%</td>

                                            <td><?php echo h($record['grade']); ?></td>

                                            <td>
                                                <?php
                                                echo (float)$record['marks'] >= 50
                                                    ? 'Pass'
                                                    : 'Fail';
                                                ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endforeach; ?>

                <div class="summary">

                    <h3>Overall Academic Performance</h3>

                    <p>
                        <strong>Total Grade Records:</strong>
                        <?php echo $totalRecords; ?>
                    </p>

                    <p>
                        <strong>Passed Records:</strong>
                        <?php echo $passed; ?>
                    </p>

                    <p>
                        <strong>Failed Records:</strong>
                        <?php echo $totalRecords - $passed; ?>
                    </p>

                    <p>
                        <strong>Overall Average:</strong>
                        <?php echo number_format($average, 2); ?>%
                    </p>

                    <p>
                        <strong>Pass Rate:</strong>
                        <?php echo number_format($passRate, 2); ?>%
                    </p>

                </div>

            <?php else: ?>

                <div class="empty">
                    No academic grades have been recorded for this student.
                </div>

            <?php endif; ?>

            <div class="signature">
                <strong>Authorized Signature:</strong>
                ______________________________
                <br><br>
                <strong>Institution Stamp:</strong>
                ______________________________
            </div>

        </section>

        <?php if ($totalRecords > 0): ?>

            <div class="print-area no-print">
                <button
                    type="button"
                    class="print-button"
                    onclick="window.print()"
                >
                    Print / Save Transcript as PDF
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
