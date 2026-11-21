
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load the database connection.
require_once __DIR__ . '/db.php';

// Function to end an invalid session.
function gradeTrackLogout()
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header('Location: login.php');
    exit;
}

// Check that a valid user ID exists.
if (
    !isset($_SESSION['user_id']) ||
    !is_numeric($_SESSION['user_id']) ||
    (int) $_SESSION['user_id'] <= 0
) {
    gradeTrackLogout();
}

$user_id = (int) $_SESSION['user_id'];

// Retrieve the user's current database information.
$stmt = $conn->prepare("
    SELECT id, username, role, student_id, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    error_log("Authentication query preparation failed: " . $conn->error);
    http_response_code(503);
    exit("Authentication service is temporarily unavailable.");
}

$stmt->bind_param("i", $user_id);

if (!$stmt->execute()) {
    error_log("Authentication query execution failed: " . $stmt->error);
    $stmt->close();

    http_response_code(503);
    exit("Authentication service is temporarily unavailable.");
}

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

// Reject deleted or missing accounts.
if (!$user) {
    gradeTrackLogout();
}

// Reject deactivated accounts.
if ($user['status'] !== 'active') {
    gradeTrackLogout();
}

// Allow only recognized user roles.
if (!in_array($user['role'], ['admin', 'teacher', 'student'], true)) {
    gradeTrackLogout();
}

// Ensure student accounts are linked to student records.
if (
    $user['role'] === 'student' &&
    empty($user['student_id'])
) {
    gradeTrackLogout();
}

// Regenerate the session ID if the user's role has changed.
if (
    isset($_SESSION['role']) &&
    $_SESSION['role'] !== $user['role']
) {
    session_regenerate_id(true);
}

// Refresh session information using the database.
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];
$_SESSION['student_id'] = $user['student_id'];

?>
