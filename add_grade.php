
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "db.php";

// Only teachers can enter grades.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    exit("Access denied. Only teachers can enter student grades.");
}

$teacher_id = (int) $_SESSION['user_id'];

// Generate CSRF token.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Calculate grade automatically.
function calculateGrade($marks) {
    if ($marks >= 80) {
        return "A";
    } elseif ($marks >= 70) {
        return "B";
    } elseif ($marks >= 60) {
        return "C";
    } elseif ($marks >= 50) {
        return "D";
    }
    return "F";
}

$message = "";
$error = "";

// Retrieve registered students.
$students = $conn->query(
    "SELECT student_id, student_name
     FROM students
     ORDER BY student_name ASC"
);

if (!$students) {
    die("Could not retrieve students.");
}

// Retrieve only active units assigned to this teacher.
$assigned_units_stmt = $conn->prepare("
    SELECT u.id, u.unit_code, u.unit_name
    FROM teacher_units tu
    INNER JOIN units u ON tu.unit_id = u.id
    WHERE tu.teacher_id = ?
      AND tu.status = 'active'
      AND u.status = 'active'
    ORDER BY u.unit_code ASC
");

if (!$assigned_units_stmt) {
    error_log("Assigned units query failed: " . $conn->error);
    die("Could not retrieve your assigned units.");
}

$assigned_units_stmt->bind_param("i", $teacher_id);
$assigned_units_stmt->execute();
$assigned_units = $assigned_units_stmt->get_result();

// Process grade submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token.
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit("Invalid security token. Please refresh the page.");
    }

    $student_id = trim($_POST['student_id'] ?? '');
    $unit_id = filter_input(INPUT_POST, 'unit_id', FILTER_VALIDATE_INT);
    $marks_input = $_POST['marks'] ?? '';

    if ($student_id === '' || !$unit_id || $marks_input === '') {

        $error = "Please fill in all fields.";

    } elseif (!is_numeric($marks_input)) {

        $error = "Marks must be a valid number.";

    } else {

        $marks = (float) $marks_input;

        if (!is_finite($marks) || $marks < 0 || $marks > 100) {

            $error = "Marks must be between 0 and 100.";

        } else {

            // Confirm that the student exists.
            $check = $conn->prepare(
                "SELECT student_id
                 FROM students
                 WHERE student_id = ?"
            );

            if (!$check) {
                error_log("Student check failed: " . $conn->error);
                $error = "Could not verify the selected student.";
            } else {

                $check->bind_param("s", $student_id);
                $check->execute();

                $student_result = $check->get_result();
                $student_exists = $student_result->num_rows > 0;

                $check->close();

                if (!$student_exists) {

                    $error = "Student does not exist. Please select a registered student.";

                } else {

                    // Verify that the selected unit is actively assigned
                    // to the logged-in teacher.
                    $unit_check = $conn->prepare("
                        SELECT u.unit_name
                        FROM teacher_units tu
                        INNER JOIN units u ON tu.unit_id = u.id
                        WHERE tu.teacher_id = ?
                          AND tu.unit_id = ?
                          AND tu.status = 'active'
                          AND u.status = 'active'
                        LIMIT 1
                    ");

                    if (!$unit_check) {
                        error_log("Unit check failed: " . $conn->error);
                        $error = "Could not verify your unit assignment.";
                    } else {

                        $unit_check->bind_param("ii", $teacher_id, $unit_id);
                        $unit_check->execute();

                        $unit_result = $unit_check->get_result();
                        $assigned_unit = $unit_result->fetch_assoc();

                        $unit_check->close();

                        if (!$assigned_unit) {

                            $error = "You are not authorized to enter grades for this unit.";

                        } else {

                            $subject = $assigned_unit['unit_name'];
                            $grade = calculateGrade($marks);
                            $user_id = $teacher_id;
                            $action = "INSERT";

                            // Save grade and audit record together.
                            $conn->begin_transaction();

                            try {

                                // Check the active semester inside the transaction.
                                // Exactly one active semester must exist.
                                $semester_check = $conn->query("
                                    SELECT id, semester_name
                                    FROM semesters
                                    WHERE status = 'active'
                                    ORDER BY id ASC
                                    FOR UPDATE
                                ");

                                if (!$semester_check) {
                                    throw new Exception("Could not check active semester.");
                                }

                                $active_semesters = $semester_check->fetch_all(MYSQLI_ASSOC);

                                if (count($active_semesters) === 0) {
                                    throw new Exception(
                                        "There is no active semester. Contact your administrator."
                                    );
                                }

                                if (count($active_semesters) > 1) {
                                    throw new Exception(
                                        "More than one semester is active. Contact your administrator."
                                    );
                                }

                                $semester_id = (int) $active_semesters[0]['id'];
                                $semester_name = $active_semesters[0]['semester_name'];

                                // Prevent duplicate grades for the same student,
                                // unit and semester.
                                $duplicate_check = $conn->prepare("
                                    SELECT id
                                    FROM grades
                                    WHERE student_id = ?
                                      AND unit_id = ?
                                      AND semester_id = ?
                                    LIMIT 1
                                    FOR UPDATE
                                ");

                                if (!$duplicate_check) {
                                    throw new Exception(
                                        "Could not check for duplicate grades."
                                    );
                                }

                                $duplicate_check->bind_param(
                                    "sii",
                                    $student_id,
                                    $unit_id,
                                    $semester_id
                                );

                                if (!$duplicate_check->execute()) {
                                    throw new Exception(
                                        "Could not verify existing grades."
                                    );
                                }

                                $duplicate_result = $duplicate_check->get_result();
                                $duplicate_exists = $duplicate_result->num_rows > 0;

                                $duplicate_check->close();

                                if ($duplicate_exists) {
                                    throw new Exception(
                                        "This student already has a grade for this unit in the active semester."
                                    );
                                }

                                // Insert grade and record the teacher's user ID.
                                $insert = $conn->prepare("
                                    INSERT INTO grades
                                    (
                                        student_id,
                                        subject,
                                        unit_id,
                                        semester_id,
                                        marks,
                                        grade,
                                        entered_by
                                    )
                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                ");

                                if (!$insert) {
                                    throw new Exception($conn->error);
                                }

                                $insert->bind_param(
                                    "ssiidsi",
                                    $student_id,
                                    $subject,
                                    $unit_id,
                                    $semester_id,
                                    $marks,
                                    $grade,
                                    $user_id
                                );

                                if (!$insert->execute()) {

                                    // MySQL error 1062 means duplicate entry.
                                    if ($insert->errno === 1062) {
                                        throw new Exception("DUPLICATE_GRADE");
                                    }

                                    throw new Exception($insert->error);
                                }

                                $grade_id = $conn->insert_id;
                                $insert->close();

                                // Record the action in the grade audit log.
                                $audit = $conn->prepare("
                                    INSERT INTO grade_audit_logs
                                    (
                                        grade_id,
                                        performed_by,
                                        student_id,
                                        subject,
                                        action,
                                        old_marks,
                                        new_marks,
                                        old_grade,
                                        new_grade
                                    )
                                    VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, ?)
                                ");

                                if (!$audit) {
                                    throw new Exception($conn->error);
                                }

                                $audit->bind_param(
                                    "iisssds",
                                    $grade_id,
                                    $user_id,
                                    $student_id,
                                    $subject,
                                    $action,
                                    $marks,
                                    $grade
                                );

                                if (!$audit->execute()) {
                                    throw new Exception($audit->error);
                                }

                                $audit->close();

                                $conn->commit();

                                $message = "Grade entered successfully! Grade awarded: "
                                    . $grade . ". Semester: " . $semester_name;

                            } catch (Throwable $e) {

                                $conn->rollback();

                                error_log("Grade entry error: " . $e->getMessage());

                                $exception_message = $e->getMessage();

                                if ($exception_message === "DUPLICATE_GRADE") {

                                    $error = "This student already has a grade for this unit in the active semester. Duplicate grades are not allowed.";

                                } elseif (
                                    strpos($exception_message, "There is no active semester") !== false ||
                                    strpos($exception_message, "More than one semester is active") !== false ||
                                    strpos($exception_message, "already has a grade") !== false
                                ) {

                                    $error = $exception_message;

                                } else {

                                    $error = "Could not save the grade. Please try again.";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// Retrieve the active semester for display.
$active_semester_result = $conn->query("
    SELECT s.id, s.semester_name, s.start_date, s.end_date,
           a.year_name
    FROM semesters s
    INNER JOIN academic_years a ON s.academic_year_id = a.id
    WHERE s.status = 'active'
    ORDER BY s.id ASC
");

$active_semesters_display = $active_semester_result
    ? $active_semester_result->fetch_all(MYSQLI_ASSOC)
    : [];

$active_semester = count($active_semesters_display) === 1
    ? $active_semesters_display[0]
    : null;

$semester_problem = "";

if (count($active_semesters_display) === 0) {
    $semester_problem = "No active semester is available. Contact your administrator before entering grades.";
} elseif (count($active_semesters_display) > 1) {
    $semester_problem = "Multiple active semesters were detected. Contact your administrator before entering grades.";
}

$selected_student = $_POST['student_id'] ?? '';
$selected_unit = $_POST['unit_id'] ?? '';
$entered_marks = $_POST['marks'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Grade Entry | GradeTrack</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #17243a;
        }

        .topbar {
            background: #102a43;
            color: white;
            padding: 20px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .brand {
            font-size: 23px;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        .brand span {
            color: #55a6ff;
        }

        .topbar small {
            color: #cbd5e1;
            font-size: 13px;
        }

        .container {
            width: 92%;
            max-width: 1050px;
            margin: 35px auto;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #64748b;
            line-height: 1.6;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 22px;
            align-items: start;
        }

        .form-card,
        .info-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
        }

        .form-card h2,
        .info-card h2 {
            font-size: 19px;
            margin-bottom: 8px;
            color: #102a43;
        }

        .card-description {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 25px;
        }

        .semester-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.6;
            font-size: 14px;
        }

        .semester-warning {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.6;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 9px;
            color: #334155;
        }

        input,
        select {
            width: 100%;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 14px;
            background: white;
            color: #17243a;
            outline: none;
        }

        input:focus,
        select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .field-hint {
            display: block;
            color: #64748b;
            font-size: 12px;
            margin-top: 7px;
        }

        .save-button {
            width: 100%;
            padding: 14px;
            background: #2563eb;
            border: none;
            border-radius: 7px;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }

        .save-button:hover {
            background: #1d4ed8;
        }

        .save-button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .message {
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .info-card {
            background: #102a43;
            color: white;
        }

        .info-card h2 {
            color: white;
        }

        .info-card .card-description {
            color: #cbd5e1;
        }

        .grading-list {
            list-style: none;
            margin-top: 15px;
        }

        .grading-list li {
            display: flex;
            justify-content: space-between;
            padding: 13px 0;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            font-size: 14px;
        }

        .grading-list li:last-child {
            border-bottom: none;
        }

        .grade-preview {
            background: #eff6ff;
            color: #1e40af;
            padding: 17px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: center;
            display: none;
        }

        .grade-preview span {
            display: block;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .grade-preview strong {
            font-size: 28px;
        }

        .footer-actions {
            margin-top: 25px;
        }

        .back-button {
            display: inline-block;
            background: #102a43;
            color: white;
            padding: 11px 18px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
        }

        .back-button:hover {
            background: #243f5a;
        }

        @media (max-width: 750px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .container {
                width: 94%;
                margin: 25px auto;
            }

            .page-heading h1 {
                font-size: 23px;
            }

            .topbar {
                padding: 18px 5%;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">Grade<span>Track</span></div>
    <small>Teacher Portal | Grade Entry</small>
</header>

<main class="container">

    <section class="page-heading">
        <h1>Student Grade Entry</h1>
        <p>Enter student marks and let GradeTrack calculate the corresponding letter grade.</p>
    </section>

    <?php if ($message !== ''): ?>
        <div class="message success">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($active_semester): ?>
        <div class="semester-box">
            <strong>Currently Active Semester:</strong>
            <?php echo htmlspecialchars($active_semester['semester_name'], ENT_QUOTES, 'UTF-8'); ?>
            |
            <?php echo htmlspecialchars($active_semester['year_name'], ENT_QUOTES, 'UTF-8'); ?>
            <br>
            <strong>Period:</strong>
            <?php echo htmlspecialchars($active_semester['start_date'], ENT_QUOTES, 'UTF-8'); ?>
            to
            <?php echo htmlspecialchars($active_semester['end_date'], ENT_QUOTES, 'UTF-8'); ?>
            <br>
            All new grades will automatically be recorded under this semester.
        </div>
    <?php else: ?>
        <div class="semester-warning">
            <?php echo htmlspecialchars($semester_problem, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="content-grid">

        <section class="form-card">

            <h2>Enter Academic Results</h2>

            <p class="card-description">
                Complete the fields below to record a student's academic performance.
            </p>

            <form method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"
                >

                <div class="form-group">
                    <label for="student_id">Select Student</label>

                    <select name="student_id" id="student_id" required>
                        <option value="">-- Select Registered Student --</option>

                        <?php while ($student = $students->fetch_assoc()): ?>
                            <option
                                value="<?php echo htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo ($selected_student === $student['student_id']) ? 'selected' : ''; ?>
                            >
                                <?php
                                echo htmlspecialchars(
                                    $student['student_id'] . " - " . $student['student_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </option>
                        <?php endwhile; ?>

                    </select>

                    <span class="field-hint">
                        Only registered students are available for selection.
                    </span>
                </div>

                <div class="form-group">
                    <label for="unit_id">Select Assigned Unit</label>

                    <select name="unit_id" id="unit_id" required>
                        <option value="">-- Select Your Assigned Unit --</option>

                        <?php while ($unit = $assigned_units->fetch_assoc()): ?>
                            <option
                                value="<?php echo (int)$unit['id']; ?>"
                                <?php echo (
                                    (string)$selected_unit === (string)$unit['id']
                                ) ? 'selected' : ''; ?>
                            >
                                <?php
                                echo htmlspecialchars(
                                    $unit['unit_code'] . ' - ' . $unit['unit_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </option>
                        <?php endwhile; ?>

                    </select>

                    <span class="field-hint">
                        Only units actively assigned to your teacher account are displayed.
                    </span>
                </div>

                <div class="form-group">
                    <label for="marks">Marks</label>

                    <input
                        type="number"
                        id="marks"
                        name="marks"
                        min="0"
                        max="100"
                        step="0.01"
                        required
                        value="<?php echo htmlspecialchars((string)$entered_marks, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="Enter marks between 0 and 100"
                    >

                    <span class="field-hint">
                        Accepted marks range: 0–100.
                    </span>
                </div>

                <div class="grade-preview" id="gradePreview">
                    <span>Calculated Letter Grade</span>
                    <strong id="gradeValue">-</strong>
                </div>

                <button
                    type="submit"
                    class="save-button"
                    <?php echo !$active_semester ? 'disabled' : ''; ?>
                >
                    Save Student Grade
                </button>

            </form>

            <?php if ($assigned_units->num_rows === 0): ?>
                <p class="field-hint" style="margin-top:15px;">
                    You currently have no active unit assignments.
                    Contact your administrator.
                </p>
            <?php endif; ?>

        </section>

        <aside class="info-card">

            <h2>Grading Scale</h2>

            <p class="card-description">
                GradeTrack automatically awards a letter grade according to the marks entered.
            </p>

            <ul class="grading-list">
                <li><span>80 – 100</span><strong>A</strong></li>
                <li><span>70 – 79</span><strong>B</strong></li>
                <li><span>60 – 69</span><strong>C</strong></li>
                <li><span>50 – 59</span><strong>D</strong></li>
                <li><span>Below 50</span><strong>F</strong></li>
            </ul>

        </aside>

    </div>

    <div class="footer-actions">
        <a href="dashboard_redirect.php" class="back-button">
            Back to Dashboard
        </a>
    </div>

</main>

<script>
    const marksInput = document.getElementById("marks");
    const gradePreview = document.getElementById("gradePreview");
    const gradeValue = document.getElementById("gradeValue");

    function updateGradePreview() {
        const marks = parseFloat(marksInput.value);

        if (
            marksInput.value.trim() === "" ||
            !Number.isFinite(marks) ||
            marks < 0 ||
            marks > 100
        ) {
            gradePreview.style.display = "none";
            return;
        }

        let grade;

        if (marks >= 80) {
            grade = "A";
        } else if (marks >= 70) {
            grade = "B";
        } else if (marks >= 60) {
            grade = "C";
        } else if (marks >= 50) {
            grade = "D";
        } else {
            grade = "F";
        }

        gradeValue.textContent = grade;
        gradePreview.style.display = "block";
    }

    marksInput.addEventListener("input", updateGradePreview);
    updateGradePreview();
</script>

</body>
</html>
