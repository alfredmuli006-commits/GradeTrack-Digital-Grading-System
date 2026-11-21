
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "db.php";

// ONLY TEACHERS CAN EDIT GRADES.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    exit("Access denied. Only teachers can edit student grades.");
}

$teacher_id = (int)$_SESSION['user_id'];

// Validate grade ID.
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    exit("Invalid grade ID.");
}

$grade_id = (int)$_GET['id'];

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
    } else {
        return "F";
    }
}

// Generate CSRF token.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Retrieve grade only if it belongs to an active assigned unit.
$stmt = $conn->prepare(
    "SELECT g.id, g.student_id, g.subject, g.marks, g.grade
     FROM grades g
     WHERE g.id = ?
     AND EXISTS (
         SELECT 1
         FROM teacher_units tu
         INNER JOIN units u ON tu.unit_id = u.id
         WHERE tu.teacher_id = ?
           AND tu.status = 'active'
           AND u.status = 'active'
           AND u.unit_name = g.subject
     )"
);

if (!$stmt) {
    error_log("Grade authorization query failed: " . $conn->error);
    exit("Could not retrieve the grade record.");
}

$stmt->bind_param("ii", $grade_id, $teacher_id);
$stmt->execute();

$result = $stmt->get_result();
$grade_record = $result->fetch_assoc();

$stmt->close();

if (!$grade_record) {
    http_response_code(403);
    exit("Access denied. This grade does not belong to one of your assigned units.");
}

$error = "";
$new_marks_input = $grade_record['marks'];

// Process grade update.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token.
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit("Invalid security token.");
    }

    $new_marks_input = trim($_POST['marks'] ?? '');

    $new_marks = filter_var(
        $new_marks_input,
        FILTER_VALIDATE_FLOAT
    );

    if (
        $new_marks === false ||
        !is_finite((float)$new_marks) ||
        $new_marks < 0 ||
        $new_marks > 100
    ) {
        $error = "Marks must be a valid number between 0 and 100.";
    } else {

        $new_grade = calculateGrade($new_marks);

        $old_marks = (float)$grade_record['marks'];
        $old_grade = $grade_record['grade'];

        $student_id = $grade_record['student_id'];
        $subject = $grade_record['subject'];

        $user_id = $teacher_id;
        $action = "UPDATE";

        $conn->begin_transaction();

        try {

            // Update only if the teacher still has an active assignment.
            $update = $conn->prepare(
                "UPDATE grades
                 SET marks = ?, grade = ?
                 WHERE id = ?
                 AND EXISTS (
                     SELECT 1
                     FROM teacher_units tu
                     INNER JOIN units u ON tu.unit_id = u.id
                     WHERE tu.teacher_id = ?
                       AND tu.status = 'active'
                       AND u.status = 'active'
                       AND u.unit_name = grades.subject
                 )"
            );

            if (!$update) {
                throw new Exception("Could not prepare grade update.");
            }

            $update->bind_param(
                "dsii",
                $new_marks,
                $new_grade,
                $grade_id,
                $teacher_id
            );

            if (!$update->execute()) {
                throw new Exception("Could not update grade.");
            }

            if ($update->affected_rows !== 1) {
                $update->close();
                throw new Exception("The grade could not be updated. Your unit assignment may have changed.");
            }

            $update->close();

            // Record the change in grade audit logs.
            $audit = $conn->prepare(
                "INSERT INTO grade_audit_logs
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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$audit) {
                throw new Exception("Could not prepare grade audit log.");
            }

            $audit->bind_param(
                "iisssddss",
                $grade_id,
                $user_id,
                $student_id,
                $subject,
                $action,
                $old_marks,
                $new_marks,
                $old_grade,
                $new_grade
            );

            if (!$audit->execute()) {
                throw new Exception("Could not record grade audit log.");
            }

            $audit->close();

            // Commit both operations.
            $conn->commit();

            header("Location: view_grades.php?updated=1");
            exit();

        } catch (Throwable $e) {

            $conn->rollback();

            error_log("Grade update error: " . $e->getMessage());

            $error = "Could not update the grade. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Grade | GradeTrack</title>

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
            max-width: 950px;
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
            grid-template-columns: 1.3fr 1fr;
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
            color: #102a43;
            font-size: 19px;
            margin-bottom: 18px;
        }

        .details-list {
            list-style: none;
        }

        .details-list li {
            padding: 13px 0;
            border-bottom: 1px solid #edf2f7;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        .details-list li:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #64748b;
        }

        .detail-value {
            font-weight: bold;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .current-grade {
            display: inline-block;
            background: #e0edff;
            color: #1d4ed8;
            padding: 5px 11px;
            border-radius: 20px;
        }

        .form-description {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 22px;
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

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 14px;
            outline: none;
        }

        input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .field-hint {
            display: block;
            color: #64748b;
            font-size: 12px;
            margin-top: 7px;
        }

        .update-button {
            width: 100%;
            padding: 14px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 7px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        .update-button:hover {
            background: #1d4ed8;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .grade-preview {
            background: #eff6ff;
            color: #1e40af;
            padding: 16px;
            border-radius: 8px;
            margin: 20px 0;
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

        .grading-scale {
            background: #102a43;
            color: white;
            margin-top: 22px;
            padding: 22px;
            border-radius: 12px;
        }

        .grading-scale h3 {
            margin-bottom: 15px;
            font-size: 17px;
        }

        .grading-scale div {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            font-size: 13px;
        }

        .grading-scale div:last-child {
            border-bottom: none;
        }

        .footer-actions {
            margin-top: 25px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
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
    <small>Teacher Portal | Grade Editing</small>
</header>

<main class="container">

    <section class="page-heading">
        <h1>Edit Student Grade</h1>
        <p>Update a student's marks and automatically recalculate the letter grade.</p>
    </section>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="content-grid">

        <section class="form-card">

            <h2>Student Record Details</h2>

            <ul class="details-list">

                <li>
                    <span class="detail-label">Grade Record ID</span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars((string)$grade_record['id']); ?>
                    </span>
                </li>

                <li>
                    <span class="detail-label">Student ID</span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars($grade_record['student_id']); ?>
                    </span>
                </li>

                <li>
                    <span class="detail-label">Subject</span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars($grade_record['subject']); ?>
                    </span>
                </li>

                <li>
                    <span class="detail-label">Current Marks</span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars((string)$grade_record['marks']); ?>/100
                    </span>
                </li>

                <li>
                    <span class="detail-label">Current Grade</span>
                    <span class="detail-value">
                        <span class="current-grade">
                            <?php echo htmlspecialchars($grade_record['grade']); ?>
                        </span>
                    </span>
                </li>

            </ul>

        </section>

        <section class="form-card">

            <h2>Update Marks</h2>

            <p class="form-description">
                Enter the corrected marks below. GradeTrack will calculate
                the new letter grade automatically.
            </p>

            <form method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
                >

                <div class="form-group">

                    <label for="marks">New Marks (0–100)</label>

                    <input
                        type="number"
                        id="marks"
                        name="marks"
                        min="0"
                        max="100"
                        step="0.01"
                        required
                        value="<?php echo htmlspecialchars((string)$new_marks_input); ?>"
                        placeholder="Enter new marks"
                    >

                    <span class="field-hint">
                        Accepted marks range: 0–100.
                    </span>

                </div>

                <div class="grade-preview" id="gradePreview">
                    <span>New Calculated Letter Grade</span>
                    <strong id="gradeValue">-</strong>
                </div>

                <button type="submit" class="update-button">
                    Update Grade
                </button>

            </form>

        </section>

    </div>

    <section class="grading-scale">

        <h3>Grading Scale</h3>

        <div><span>80 – 100</span><strong>A</strong></div>
        <div><span>70 – 79</span><strong>B</strong></div>
        <div><span>60 – 69</span><strong>C</strong></div>
        <div><span>50 – 59</span><strong>D</strong></div>
        <div><span>Below 50</span><strong>F</strong></div>

    </section>

    <div class="footer-actions">

        <a href="view_grades.php" class="back-button">
            Back to Grades
        </a>

        <a href="teacher_dashboard.php" class="back-button">
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
