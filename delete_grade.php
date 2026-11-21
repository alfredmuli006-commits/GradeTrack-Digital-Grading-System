
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "db.php";

// Only teachers can delete grades.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    exit("Access denied. Only teachers can delete grades.");
}

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header("Allow: POST");
    exit("Invalid request method.");
}

// Verify CSRF token.
if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    http_response_code(403);
    exit("Invalid security token. Please return to the grades page and try again.");
}

// Validate grade ID.
if (!isset($_POST['id']) || !ctype_digit($_POST['id'])) {
    http_response_code(400);
    exit("Invalid grade ID.");
}

$grade_id = (int) $_POST['id'];
$user_id = (int) $_SESSION['user_id'];

try {

    // Start transaction.
    $conn->begin_transaction();

    // Retrieve the grade only if the teacher is assigned
    // to its unit and both the unit and assignment are active.
    $stmt = $conn->prepare(
        "SELECT g.student_id, g.subject, g.marks, g.grade
         FROM grades g
         WHERE g.id = ?
         AND EXISTS (
             SELECT 1
             FROM teacher_units tu
             INNER JOIN units u ON u.id = tu.unit_id
             WHERE tu.teacher_id = ?
             AND tu.status = 'active'
             AND u.status = 'active'
             AND u.unit_name = g.subject
         )
         FOR UPDATE"
    );

    $stmt->bind_param("ii", $grade_id, $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $record = $result->fetch_assoc();
    $stmt->close();

    // Stop if the grade does not exist or is not assigned
    // to this teacher.
    if (!$record) {
        $conn->rollback();

        http_response_code(403);
        exit("Access denied. This grade does not belong to one of your assigned units.");
    }

    $student_id = $record['student_id'];
    $subject = $record['subject'];
    $old_marks = (float) $record['marks'];
    $old_grade = $record['grade'];
    $action = "DELETE";

    // Record the deletion in the grade audit log.
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
        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NULL)"
    );

    $audit->bind_param(
        "iisssds",
        $grade_id,
        $user_id,
        $student_id,
        $subject,
        $action,
        $old_marks,
        $old_grade
    );

    $audit->execute();
    $audit->close();

    // Delete only if the teacher's active assignment still exists.
    $delete = $conn->prepare(
        "DELETE FROM grades
         WHERE id = ?
         AND EXISTS (
             SELECT 1
             FROM teacher_units tu
             INNER JOIN units u ON u.id = tu.unit_id
             WHERE tu.teacher_id = ?
             AND tu.status = 'active'
             AND u.status = 'active'
             AND u.unit_name = grades.subject
         )"
    );

    $delete->bind_param("ii", $grade_id, $user_id);
    $delete->execute();

    if ($delete->affected_rows !== 1) {
        $delete->close();
        throw new Exception("Grade deletion failed or teacher assignment is no longer active.");
    }

    $delete->close();

    // Commit deletion and audit record together.
    $conn->commit();

    header("Location: view_grades.php?deleted=1");
    exit();

} catch (Throwable $e) {

    $conn->rollback();

    error_log("Grade deletion error: " . $e->getMessage());

    http_response_code(500);
    exit("Could not delete the grade. Please try again.");
}
?>
