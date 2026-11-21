
<?php
require_once 'auth.php';
require_once 'db.php';

// Admin access only.
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can manage reports.');
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Safely execute prepared report queries.
function reportQuery($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die('Could not prepare report query.');
    }

    if ($types !== '' && !empty($params)) {
        $bindValues = [];

        foreach ($params as $key => &$value) {
            $bindValues[$key] = &$value;
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            array_merge([$types], $bindValues)
        );
    }

    if (!$stmt->execute()) {
        $stmt->close();
        die('Could not execute report query.');
    }

    $result = $stmt->get_result();
    $stmt->close();

    return $result;
}

// --------------------------------------------------
// FILTERS
// --------------------------------------------------

$search = trim($_GET['search'] ?? '');
$semesterFilter = trim($_GET['semester_id'] ?? '');

if ($semesterFilter !== '' && !ctype_digit($semesterFilter)) {
    $semesterFilter = '';
}

$conditions = [];
$params = [];
$types = '';

if ($semesterFilter !== '') {
    $conditions[] = "g.semester_id = ?";
    $params[] = (int)$semesterFilter;
    $types .= 'i';
}

if ($search !== '') {
    $conditions[] = "(
        g.student_id LIKE ?
        OR s.student_name LIKE ?
        OR g.subject LIKE ?
        OR u.unit_name LIKE ?
        OR u.unit_code LIKE ?
    )";

    $term = '%' . $search . '%';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $term;
    }

    $types .= 'sssss';
}

$filterSql = !empty($conditions)
    ? ' WHERE ' . implode(' AND ', $conditions)
    : '';


// --------------------------------------------------
// SEMESTER OPTIONS
// --------------------------------------------------

$semesterOptions = $conn->query("
    SELECT
        sem.id,
        sem.semester_name,
        ay.year_name
    FROM semesters sem
    INNER JOIN academic_years ay
        ON sem.academic_year_id = ay.id
    ORDER BY ay.start_date DESC, sem.start_date DESC
");

if (!$semesterOptions) {
    die('Could not retrieve semester options.');
}


// --------------------------------------------------
// OVERALL STATISTICS
// --------------------------------------------------

$statsSql = "
    SELECT
        COUNT(*) AS total_records,
        COUNT(DISTINCT g.student_id) AS total_students,
        AVG(g.marks) AS average_marks,
        SUM(CASE WHEN g.marks >= 50 THEN 1 ELSE 0 END) AS passed_records,
        SUM(CASE WHEN g.marks < 50 THEN 1 ELSE 0 END) AS failed_records
    FROM grades g
    LEFT JOIN students s
        ON g.student_id = s.student_id
    LEFT JOIN units u
        ON g.unit_id = u.id
    $filterSql
";

$statsResult = reportQuery(
    $conn,
    $statsSql,
    $types,
    $params
);

$stats = $statsResult->fetch_assoc();

$totalRecords = (int)$stats['total_records'];
$totalStudents = (int)$stats['total_students'];
$passedRecords = (int)($stats['passed_records'] ?? 0);
$failedRecords = (int)($stats['failed_records'] ?? 0);

$passRate = $totalRecords > 0
    ? ($passedRecords / $totalRecords) * 100
    : 0;

$failRate = $totalRecords > 0
    ? ($failedRecords / $totalRecords) * 100
    : 0;


// --------------------------------------------------
// GRADE DISTRIBUTION
// --------------------------------------------------

$distributionSql = "
    SELECT
        UPPER(g.grade) AS grade,
        COUNT(*) AS total
    FROM grades g
    LEFT JOIN students s
        ON g.student_id = s.student_id
    LEFT JOIN units u
        ON g.unit_id = u.id
    $filterSql
    GROUP BY UPPER(g.grade)
";

$distributionResult = reportQuery(
    $conn,
    $distributionSql,
    $types,
    $params
);

$gradeDistribution = [
    'A' => 0,
    'B' => 0,
    'C' => 0,
    'D' => 0,
    'F' => 0
];

while ($distribution = $distributionResult->fetch_assoc()) {
    $grade = strtoupper($distribution['grade']);

    if (isset($gradeDistribution[$grade])) {
        $gradeDistribution[$grade] = (int)$distribution['total'];
    }
}

$gradeColors = [
    'A' => '#168044',
    'B' => '#2563eb',
    'C' => '#0891b2',
    'D' => '#d97706',
    'F' => '#dc2626'
];


// --------------------------------------------------
// STUDENT PERFORMANCE RANKINGS
// --------------------------------------------------

$rankingSql = "
    SELECT
        g.student_id,
        s.student_name,
        s.course,
        COUNT(g.id) AS total_units,
        AVG(g.marks) AS average_marks,
        SUM(CASE WHEN g.marks >= 50 THEN 1 ELSE 0 END) AS passed,
        SUM(CASE WHEN g.marks < 50 THEN 1 ELSE 0 END) AS failed
    FROM grades g
    LEFT JOIN students s
        ON g.student_id = s.student_id
    LEFT JOIN units u
        ON g.unit_id = u.id
    $filterSql
    GROUP BY
        g.student_id,
        s.student_name,
        s.course
";

// Highest-performing students.
$topStudents = reportQuery(
    $conn,
    $rankingSql . "
        ORDER BY average_marks DESC, g.student_id ASC
        LIMIT 10
    ",
    $types,
    $params
);

// Lowest-performing students.
$lowestStudents = reportQuery(
    $conn,
    $rankingSql . "
        ORDER BY average_marks ASC, g.student_id ASC
        LIMIT 10
    ",
    $types,
    $params
);


// --------------------------------------------------
// UNIT PERFORMANCE
// --------------------------------------------------

$unitSql = "
    SELECT
        g.unit_id,
        COALESCE(NULLIF(u.unit_code, ''), 'Historical') AS unit_code,
        COALESCE(NULLIF(u.unit_name, ''), g.subject) AS unit_name,
        COUNT(g.id) AS total_records,
        COUNT(DISTINCT g.student_id) AS total_students,
        AVG(g.marks) AS average_marks,
        SUM(CASE WHEN g.marks >= 50 THEN 1 ELSE 0 END) AS passed,
        SUM(CASE WHEN g.marks < 50 THEN 1 ELSE 0 END) AS failed
    FROM grades g
    LEFT JOIN students s
        ON g.student_id = s.student_id
    LEFT JOIN units u
        ON g.unit_id = u.id
    $filterSql
    GROUP BY
        g.unit_id,
        u.unit_code,
        u.unit_name,
        g.subject
    ORDER BY average_marks DESC
";

$unitResult = reportQuery(
    $conn,
    $unitSql,
    $types,
    $params
);

$unitRows = [];

while ($unit = $unitResult->fetch_assoc()) {
    $unitRows[] = $unit;
}


// --------------------------------------------------
// GRADE RECORDS
// --------------------------------------------------

$recordsSql = "
    SELECT
        g.id,
        g.student_id,
        s.student_name,
        s.course,
        g.subject,
        u.unit_code,
        u.unit_name,
        g.marks,
        g.grade,
        g.semester_id,
        sem.semester_name,
        ay.year_name
    FROM grades g
    LEFT JOIN students s
        ON g.student_id = s.student_id
    LEFT JOIN units u
        ON g.unit_id = u.id
    LEFT JOIN semesters sem
        ON g.semester_id = sem.id
    LEFT JOIN academic_years ay
        ON sem.academic_year_id = ay.id
    $filterSql
    ORDER BY
        s.student_name ASC,
        ay.start_date DESC,
        sem.start_date DESC,
        g.subject ASC
";

$result = reportQuery(
    $conn,
    $recordsSql,
    $types,
    $params
);

$displayedRecords = $result->num_rows;


// --------------------------------------------------
// SEMESTER PERFORMANCE COMPARISON
// This remains an all-semester overview.
// --------------------------------------------------

$comparisonSql = "
    SELECT
        ay.year_name,
        sem.semester_name,
        sem.id AS semester_id,
        COUNT(g.id) AS total_records,
        COUNT(DISTINCT g.student_id) AS total_students,
        AVG(g.marks) AS average_marks,
        SUM(CASE WHEN g.marks >= 50 THEN 1 ELSE 0 END) AS passed_records,
        SUM(CASE WHEN g.marks < 50 THEN 1 ELSE 0 END) AS failed_records
    FROM semesters sem
    INNER JOIN academic_years ay
        ON sem.academic_year_id = ay.id
    LEFT JOIN grades g
        ON g.semester_id = sem.id
    GROUP BY
        sem.id,
        ay.year_name,
        sem.semester_name,
        ay.start_date,
        sem.start_date
    ORDER BY ay.start_date DESC, sem.start_date DESC
";

$comparisonResult = $conn->query($comparisonSql);

if (!$comparisonResult) {
    die('Could not retrieve semester comparison.');
}


// --------------------------------------------------
// CSV DOWNLOAD SEMESTER OPTIONS
// --------------------------------------------------

$downloadSemesters = $conn->query("
    SELECT
        sem.id,
        sem.semester_name,
        ay.year_name
    FROM semesters sem
    INNER JOIN academic_years ay
        ON sem.academic_year_id = ay.id
    ORDER BY ay.start_date DESC, sem.start_date DESC
");

if (!$downloadSemesters) {
    die('Could not retrieve download semester options.');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Academic Reports | GradeTrack</title>

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

header {
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

.header-label {
    color: #cbd5e1;
    font-size: 13px;
}

main {
    max-width: 1300px;
    margin: 40px auto;
    padding: 0 20px;
}

.page-heading {
    margin-bottom: 25px;
}

.page-heading h1 {
    font-size: 29px;
    color: #101d3a;
    margin-bottom: 9px;
}

.page-heading p {
    color: #64748b;
    line-height: 1.6;
}

.back-link {
    display: inline-block;
    background: #1d4ed8;
    color: white;
    padding: 11px 16px;
    border-radius: 7px;
    text-decoration: none;
    font-size: 13px;
    font-weight: bold;
}

.back-link:hover {
    background: #2563eb;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 18px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 23px;
    border-radius: 12px;
    border: 1px solid #e5eaf2;
    box-shadow: 0 4px 15px rgba(15, 23, 42, .04);
}

.stat-card h3 {
    color: #64748b;
    font-size: 13px;
    font-weight: normal;
    margin-bottom: 13px;
}

.stat-card p {
    font-size: 27px;
    font-weight: bold;
    color: #1769c2;
}

.report-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    border: 1px solid #e5eaf2;
    box-shadow: 0 4px 15px rgba(15, 23, 42, .04);
    margin-bottom: 25px;
}

.report-heading {
    margin-bottom: 20px;
}

.report-heading h2 {
    color: #101d3a;
    font-size: 19px;
    margin-bottom: 7px;
}

.report-heading p {
    color: #64748b;
    font-size: 13px;
    line-height: 1.6;
}

.search-form {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.search-form input,
.search-form select {
    flex: 1;
    min-width: 190px;
    padding: 13px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    outline: none;
    font-size: 14px;
    background: white;
}

.search-form input:focus,
.search-form select:focus {
    border-color: #2563eb;
}

button {
    padding: 12px 18px;
    background: #1d4ed8;
    color: white;
    border: none;
    border-radius: 7px;
    font-weight: bold;
    cursor: pointer;
}

button:hover {
    background: #2563eb;
}

.clear {
    display: inline-flex;
    align-items: center;
    padding: 12px 15px;
    color: #475569;
    background: #f1f5f9;
    border-radius: 7px;
    text-decoration: none;
    font-size: 13px;
}

.clear:hover {
    background: #e2e8f0;
}

.results-info {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 14px;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

th {
    background: #f1f5f9;
    color: #475569;
    padding: 15px;
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

td {
    padding: 15px;
    border-bottom: 1px solid #edf0f5;
    font-size: 13px;
    color: #334155;
}

tbody tr:hover {
    background: #f8fafc;
}

.student-name {
    font-weight: bold;
    color: #172033;
}

.grade-badge {
    display: inline-block;
    background: #eaf3ff;
    color: #1769c2;
    padding: 6px 11px;
    border-radius: 20px;
    font-weight: bold;
    font-size: 12px;
}

.no-results {
    text-align: center;
    padding: 35px;
    color: #64748b;
}

.download-form {
    padding: 18px;
    background: #f8fafc;
    border: 1px solid #e5eaf2;
    border-radius: 9px;
    margin-bottom: 22px;
}

.download-form label {
    display: block;
    color: #334155;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 10px;
}

.download-form select {
    width: 100%;
    max-width: 450px;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: white;
    font-size: 14px;
}

.download-button {
    display: inline-block;
    margin-top: 12px;
    padding: 12px 18px;
    background: #168044;
    color: white;
    border: none;
    border-radius: 7px;
    font-weight: bold;
    cursor: pointer;
}

.download-button:hover {
    background: #126b37;
}

/* Grade distribution chart */

.chart-row {
    display: grid;
    grid-template-columns: 35px 1fr 55px;
    align-items: center;
    gap: 12px;
    margin-bottom: 17px;
}

.chart-grade {
    font-weight: bold;
    font-size: 14px;
}

.bar-background {
    height: 19px;
    background: #edf2f7;
    border-radius: 20px;
    overflow: hidden;
}

.bar-fill {
    height: 100%;
    border-radius: 20px;
    min-width: 0;
}

.chart-value {
    font-size: 13px;
    color: #475569;
    text-align: right;
}

/* Performance ranking */

.ranking-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 22px;
}

.ranking-panel {
    border: 1px solid #e5eaf2;
    border-radius: 9px;
    overflow: hidden;
}

.ranking-panel h3 {
    padding: 16px;
    background: #f1f5f9;
    color: #172033;
    font-size: 15px;
}

.ranking-panel table {
    min-width: 0;
}

.ranking-panel th,
.ranking-panel td {
    padding: 12px 10px;
    font-size: 12px;
}

/* Unit performance */

.unit-performance {
    min-width: 900px;
}

.unit-performance td {
    vertical-align: middle;
}

.average-bar {
    width: 120px;
    height: 9px;
    background: #edf2f7;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 6px;
}

.average-bar div {
    height: 100%;
    background: #2563eb;
    border-radius: 10px;
}

footer {
    text-align: center;
    padding: 25px;
    color: #94a3b8;
    font-size: 12px;
}

@media (max-width: 600px) {

    header {
        padding: 17px 20px;
    }

    main {
        margin: 25px auto;
    }

    .page-heading h1 {
        font-size: 24px;
    }

    .report-card {
        padding: 17px;
    }

    .search-form button,
    .search-form input,
    .search-form select {
        width: 100%;
    }

    .download-form select {
        max-width: 100%;
    }

    .ranking-grid {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>

<header>
    <div class="brand">Grade<span>Track</span></div>
    <div class="header-label">Administrator Portal</div>
</header>

<main>

    <section class="page-heading">

        <h1>Academic Reports</h1>

        <p>
            Review student academic performance, grade distribution,
            unit results and semester statistics.
        </p>

        <br>

        <a href="dashboard_redirect.php" class="back-link">
            ← Back to Dashboard
        </a>

    </section>


    <!-- FILTERS -->

    <section class="report-card">

        <div class="report-heading">
            <h2>Report Filters</h2>
            <p>Filter academic reports by semester, student ID, student name or unit.</p>
        </div>

        <form method="GET" action="manage_reports.php" class="search-form">

            <input
                type="text"
                name="search"
                placeholder="Search by student ID, name or unit..."
                value="<?php echo h($search); ?>"
            >

            <select name="semester_id">

                <option value="">All Semesters and Historical Records</option>

                <?php while ($semester = $semesterOptions->fetch_assoc()): ?>

                    <option
                        value="<?php echo (int)$semester['id']; ?>"
                        <?php echo $semesterFilter === (string)$semester['id'] ? 'selected' : ''; ?>
                    >
                        <?php
                        echo h($semester['year_name'] . ' - ' . $semester['semester_name']);
                        ?>
                    </option>

                <?php endwhile; ?>

            </select>

            <button type="submit">Apply Filters</button>

            <a href="manage_reports.php" class="clear">Clear Filters</a>

        </form>

        <div class="results-info">
            <?php if ($semesterFilter !== ''): ?>
                Showing reports for the selected semester.
            <?php else: ?>
                Showing reports for all semesters and historical records.
            <?php endif; ?>

            <?php if ($search !== ''): ?>
                Search: <strong><?php echo h($search); ?></strong>
            <?php endif; ?>
        </div>

    </section>


    <!-- MAIN STATISTICS -->

    <section class="stats">

        <div class="stat-card">
            <h3>Total Grade Records</h3>
            <p><?php echo $totalRecords; ?></p>
        </div>

        <div class="stat-card">
            <h3>Students With Grades</h3>
            <p><?php echo $totalStudents; ?></p>
        </div>

        <div class="stat-card">
            <h3>Average Marks</h3>
            <p>
                <?php
                echo $stats['average_marks'] !== null
                    ? number_format((float)$stats['average_marks'], 2) . '%'
                    : 'N/A';
                ?>
            </p>
        </div>

        <div class="stat-card">
            <h3>Passed Records</h3>
            <p><?php echo $passedRecords; ?></p>
        </div>

        <div class="stat-card">
            <h3>Failed Records</h3>
            <p><?php echo $failedRecords; ?></p>
        </div>

        <div class="stat-card">
            <h3>Pass Rate</h3>
            <p><?php echo number_format($passRate, 2); ?>%</p>
        </div>

        <div class="stat-card">
            <h3>Fail Rate</h3>
            <p><?php echo number_format($failRate, 2); ?>%</p>
        </div>

    </section>


    <!-- GRADE DISTRIBUTION -->

    <section class="report-card">

        <div class="report-heading">
            <h2>Grade Distribution</h2>
            <p>Number of A, B, C, D and F grades in the filtered records.</p>
        </div>

        <?php foreach ($gradeDistribution as $grade => $count): ?>

            <?php
            $percentage = $totalRecords > 0
                ? ($count / $totalRecords) * 100
                : 0;
            ?>

            <div class="chart-row">

                <div class="chart-grade">
                    <?php echo h($grade); ?>
                </div>

                <div class="bar-background">

                    <div
                        class="bar-fill"
                        style="
                            width: <?php echo $percentage; ?>%;
                            background: <?php echo $gradeColors[$grade]; ?>;
                        "
                    ></div>

                </div>

                <div class="chart-value">
                    <?php echo $count; ?>
                </div>

            </div>

        <?php endforeach; ?>

        <p class="results-info">
            Total grade records represented: <?php echo $totalRecords; ?>
        </p>

    </section>


    <!-- TOP AND LOWEST STUDENTS -->

    <section class="report-card">

        <div class="report-heading">
            <h2>Student Performance Rankings</h2>
            <p>
                Rankings are based on each student's average marks
                across the currently filtered grade records.
            </p>
        </div>

        <div class="ranking-grid">

            <div class="ranking-panel">

                <h3>Top 10 Performing Students</h3>

                <div class="table-wrapper">

                    <table>

                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Student</th>
                                <th>Average</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php
                            $rank = 1;
                            ?>

                            <?php if ($topStudents->num_rows > 0): ?>

                                <?php while ($student = $topStudents->fetch_assoc()): ?>

                                    <tr>

                                        <td><?php echo $rank++; ?></td>

                                        <td>
                                            <strong><?php echo h($student['student_name'] ?? 'Unknown'); ?></strong>
                                            <br>
                                            <?php echo h($student['student_id']); ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?php echo number_format((float)$student['average_marks'], 2); ?>%
                                            </strong>
                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="3" class="no-results">
                                        No student results available.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>


            <div class="ranking-panel">

                <h3>Lowest 10 Performing Students</h3>

                <div class="table-wrapper">

                    <table>

                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Student</th>
                                <th>Average</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php
                            $rank = 1;
                            ?>

                            <?php if ($lowestStudents->num_rows > 0): ?>

                                <?php while ($student = $lowestStudents->fetch_assoc()): ?>

                                    <tr>

                                        <td><?php echo $rank++; ?></td>

                                        <td>
                                            <strong><?php echo h($student['student_name'] ?? 'Unknown'); ?></strong>
                                            <br>
                                            <?php echo h($student['student_id']); ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?php echo number_format((float)$student['average_marks'], 2); ?>%
                                            </strong>
                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="3" class="no-results">
                                        No student results available.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </section>


    <!-- UNIT PERFORMANCE -->

    <section class="report-card">

        <div class="report-heading">
            <h2>Unit Performance Analysis</h2>
            <p>
                Compare average marks, pass rates and fail rates
                for individual units.
            </p>
        </div>

        <div class="table-wrapper">

            <table class="unit-performance">

                <thead>

                    <tr>
                        <th>Unit Code</th>
                        <th>Unit Name</th>
                        <th>Students</th>
                        <th>Grade Records</th>
                        <th>Average Marks</th>
                        <th>Passed</th>
                        <th>Failed</th>
                        <th>Pass Rate</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (count($unitRows) > 0): ?>

                        <?php foreach ($unitRows as $unit): ?>

                            <?php
                            $unitTotal = (int)$unit['total_records'];
                            $unitPassed = (int)$unit['passed'];
                            $unitFailed = (int)$unit['failed'];

                            $unitPassRate = $unitTotal > 0
                                ? ($unitPassed / $unitTotal) * 100
                                : 0;

                            $unitAverage = (float)$unit['average_marks'];

                            $unitAverageWidth = max(
                                0,
                                min(100, $unitAverage)
                            );
                            ?>

                            <tr>

                                <td><?php echo h($unit['unit_code']); ?></td>

                                <td><?php echo h($unit['unit_name']); ?></td>

                                <td><?php echo (int)$unit['total_students']; ?></td>

                                <td><?php echo $unitTotal; ?></td>

                                <td>

                                    <?php echo number_format($unitAverage, 2); ?>%

                                    <div class="average-bar">
                                        <div style="width: <?php echo $unitAverageWidth; ?>%;"></div>
                                    </div>

                                </td>

                                <td><?php echo $unitPassed; ?></td>

                                <td><?php echo $unitFailed; ?></td>

                                <td><?php echo number_format($unitPassRate, 2); ?>%</td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="no-results">
                                No unit performance records available.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- STUDENT GRADE RECORDS -->

    <section class="report-card">

        <div class="report-heading">
            <h2>Student Grade Records</h2>
            <p>Detailed student grades matching the selected filters.</p>
        </div>

        <div class="results-info">
            Showing <?php echo (int)$displayedRecords; ?> matching grade record(s).
        </div>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Course</th>
                        <th>Subject / Unit</th>
                        <th>Academic Year</th>
                        <th>Semester</th>
                        <th>Marks</th>
                        <th>Grade</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($displayedRecords > 0): ?>

                        <?php while ($row = $result->fetch_assoc()): ?>

                            <tr>

                                <td><?php echo h($row['student_id']); ?></td>

                                <td class="student-name">
                                    <?php echo h($row['student_name'] ?? 'Unknown'); ?>
                                </td>

                                <td><?php echo h($row['course'] ?? 'N/A'); ?></td>

                                <td>
                                    <?php
                                    if (!empty($row['unit_name'])) {
                                        echo h($row['unit_name']);

                                        if (!empty($row['unit_code'])) {
                                            echo ' (' . h($row['unit_code']) . ')';
                                        }
                                    } else {
                                        echo h($row['subject']);
                                    }
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo $row['semester_id'] === null
                                        ? 'Historical / Unassigned'
                                        : h($row['year_name'] ?? 'N/A');
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo $row['semester_id'] === null
                                        ? 'Historical / Unassigned'
                                        : h($row['semester_name'] ?? 'N/A');
                                    ?>
                                </td>

                                <td><?php echo h($row['marks']); ?>%</td>

                                <td>
                                    <span class="grade-badge">
                                        <?php echo h($row['grade']); ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="no-results">
                                No matching grade records found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- SEMESTER CSV DOWNLOAD -->

    <section class="report-card">

        <div class="report-heading">
            <h2>Download Semester Report</h2>
            <p>Download academic results for a selected semester as a CSV file.</p>
        </div>

        <div class="download-form">

            <form method="GET" action="download_semester_report.php">

                <label for="download_semester">
                    Select Semester to Download
                </label>

                <select
                    name="semester_id"
                    id="download_semester"
                    required
                >

                    <option value="">-- Choose Semester --</option>

                    <?php while ($ds = $downloadSemesters->fetch_assoc()): ?>

                        <option value="<?php echo (int)$ds['id']; ?>">
                            <?php
                            echo h($ds['year_name'] . ' - ' . $ds['semester_name']);
                            ?>
                        </option>

                    <?php endwhile; ?>

                </select>

                <br>

                <button type="submit" class="download-button">
                    Download Semester CSV
                </button>

            </form>

        </div>

    </section>


    <!-- SEMESTER COMPARISON -->

    <section class="report-card">

        <div class="report-heading">
            <h2>Semester Performance Comparison</h2>
            <p>
                Compare average marks, pass rates and student results
                across all academic semesters.
            </p>
        </div>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>
                        <th>Academic Year</th>
                        <th>Semester</th>
                        <th>Students</th>
                        <th>Grade Records</th>
                        <th>Average Marks</th>
                        <th>Pass Rate</th>
                        <th>Fail Rate</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($comparisonResult->num_rows > 0): ?>

                        <?php while ($comparison = $comparisonResult->fetch_assoc()): ?>

                            <?php
                            $comparisonTotal = (int)$comparison['total_records'];
                            $comparisonPassed = (int)$comparison['passed_records'];
                            $comparisonFailed = (int)$comparison['failed_records'];

                            $comparisonPassRate = $comparisonTotal > 0
                                ? ($comparisonPassed / $comparisonTotal) * 100
                                : 0;

                            $comparisonFailRate = $comparisonTotal > 0
                                ? ($comparisonFailed / $comparisonTotal) * 100
                                : 0;
                            ?>

                            <tr>

                                <td><?php echo h($comparison['year_name']); ?></td>

                                <td><?php echo h($comparison['semester_name']); ?></td>

                                <td><?php echo (int)$comparison['total_students']; ?></td>

                                <td><?php echo $comparisonTotal; ?></td>

                                <td>
                                    <?php
                                    echo $comparison['average_marks'] !== null
                                        ? number_format((float)$comparison['average_marks'], 2) . '%'
                                        : 'N/A';
                                    ?>
                                </td>

                                <td>
                                    <?php echo number_format($comparisonPassRate, 2); ?>%
                                </td>

                                <td>
                                    <?php echo number_format($comparisonFailRate, 2); ?>%
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="no-results">
                                No semester records are available yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<footer>
    © <?php echo date('Y'); ?> GradeTrack. Digital Grading Management System.
</footer>

</body>
</html>
