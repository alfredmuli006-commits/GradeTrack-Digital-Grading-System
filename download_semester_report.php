
<?php
require_once 'auth.php';
require_once 'db.php';

// Admin access only.
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

// Validate semester ID.
$semesterId = $_GET['semester_id'] ?? '';

if (!ctype_digit((string)$semesterId) || (int)$semesterId < 1) {
    exit('Please select a valid semester.');
}

$semesterId = (int)$semesterId;

// Retrieve semester information.
$semesterStmt = $conn->prepare("
    SELECT
        sem.semester_name,
        sem.start_date,
        sem.end_date,
        ay.year_name
    FROM semesters sem
    INNER JOIN academic_years ay
        ON sem.academic_year_id = ay.id
    WHERE sem.id = ?
");

if (!$semesterStmt) {
    exit('Could not prepare semester query.');
}

$semesterStmt->bind_param("i", $semesterId);
$semesterStmt->execute();

$semester = $semesterStmt->get_result()->fetch_assoc();
$semesterStmt->close();

if (!$semester) {
    exit('Semester not found.');
}

// Retrieve results for the selected semester.
$gradeStmt = $conn->prepare("
    SELECT
        g.student_id,
        s.student_name,
        s.course,
        g.subject,
        g.marks,
        g.grade
    FROM grades g
    LEFT JOIN students s
        ON g.student_id = s.student_id
    WHERE g.semester_id = ?
    ORDER BY s.student_name ASC, g.subject ASC
");

if (!$gradeStmt) {
    exit('Could not prepare grades query.');
}

$gradeStmt->bind_param("i", $semesterId);
$gradeStmt->execute();

$result = $gradeStmt->get_result();

// Create a safe CSV filename.
$filename = 'GradeTrack_' .
    preg_replace('/[^A-Za-z0-9_-]/', '_', $semester['year_name']) .
    '_' .
    preg_replace('/[^A-Za-z0-9_-]/', '_', $semester['semester_name']) .
    '.csv';

// CSV output headers.
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Open output stream.
$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility.
fwrite($output, "\xEF\xBB\xBF");

// Report information.
fputcsv($output, ['GradeTrack - Academic Semester Report']);
fputcsv($output, ['Academic Year', $semester['year_name']]);
fputcsv($output, ['Semester', $semester['semester_name']]);
fputcsv($output, ['Start Date', $semester['start_date']]);
fputcsv($output, ['End Date', $semester['end_date']]);
fputcsv($output, ['Generated On', date('d F Y')]);
fputcsv($output, []);

// Column headings.
fputcsv($output, [
    'Student ID',
    'Student Name',
    'Course',
    'Subject / Unit',
    'Marks',
    'Grade'
]);

$totalMarks = 0;
$totalRecords = 0;
$passedRecords = 0;
$failedRecords = 0;

while ($row = $result->fetch_assoc()) {

    $marks = (float)$row['marks'];

    $totalMarks += $marks;
    $totalRecords++;

    if ($marks >= 50) {
        $passedRecords++;
    } else {
        $failedRecords++;
    }

    // Prevent spreadsheet formula injection in text fields.
    $safe = function ($value) {
        $value = (string)$value;

        if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
            return "'" . $value;
        }

        return $value;
    };

    fputcsv($output, [
        $safe($row['student_id']),
        $safe($row['student_name'] ?? 'Unknown'),
        $safe($row['course'] ?? 'N/A'),
        $safe($row['subject']),
        number_format($marks, 2, '.', ''),
        $safe($row['grade'])
    ]);
}

// Summary.
$average = $totalRecords > 0
    ? $totalMarks / $totalRecords
    : 0;

$passRate = $totalRecords > 0
    ? ($passedRecords / $totalRecords) * 100
    : 0;

$failRate = $totalRecords > 0
    ? ($failedRecords / $totalRecords) * 100
    : 0;

fputcsv($output, []);
fputcsv($output, ['PERFORMANCE SUMMARY']);
fputcsv($output, ['Total Grade Records', $totalRecords]);
fputcsv($output, ['Passed Records', $passedRecords]);
fputcsv($output, ['Failed Records', $failedRecords]);
fputcsv($output, ['Average Marks', number_format($average, 2) . '%']);
fputcsv($output, ['Pass Rate', number_format($passRate, 2) . '%']);
fputcsv($output, ['Fail Rate', number_format($failRate, 2) . '%']);

fclose($output);
$gradeStmt->close();
exit;
