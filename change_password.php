
<?php
require_once 'auth.php';
require_once 'db.php';

// Only teachers and students can use this page.
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'student'], true)) {
    http_response_code(403);
    exit('Access denied. Only teachers and students can change passwords here.');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    http_response_code(403);
    exit('Invalid user session. Please log in again.');
}

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = $_POST['csrf_token'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        $message = 'Invalid request. Refresh the page and try again.';
        $messageType = 'error';

    } elseif (
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {
        $message = 'Please fill in all password fields.';
        $messageType = 'error';

    } elseif (strlen($newPassword) < 8) {
        $message = 'Your new password must contain at least 8 characters.';
        $messageType = 'error';

    } elseif (strlen($newPassword) > 4096) {
        $message = 'Your new password is too long.';
        $messageType = 'error';

    } elseif ($newPassword !== $confirmPassword) {
        $message = 'The new passwords do not match.';
        $messageType = 'error';

    } elseif ($currentPassword === $newPassword) {
        $message = 'Your new password must be different from your current password.';
        $messageType = 'error';

    } else {

        // Retrieve the logged-in user's password.
        $stmt = $conn->prepare(
            "SELECT password FROM users
             WHERE id = ? AND role = ? AND status = 'active'
             LIMIT 1"
        );

        if (!$stmt) {
            $message = 'Unable to process your request. Please try again.';
            $messageType = 'error';

        } else {

            $stmt->bind_param("is", $user_id, $role);
            $stmt->execute();

            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            $stmt->close();

            if (!$user || !password_verify($currentPassword, $user['password'])) {

                $message = 'Your current password is incorrect.';
                $messageType = 'error';

            } else {

                $hashedPassword = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                if ($hashedPassword === false) {

                    $message = 'Unable to secure your new password.';
                    $messageType = 'error';

                } else {

                    $update = $conn->prepare(
                        "UPDATE users
                         SET password = ?
                         WHERE id = ? AND role = ? AND status = 'active'"
                    );

                    if (!$update) {

                        $message = 'Unable to update your password. Please try again.';
                        $messageType = 'error';

                    } else {

                        $update->bind_param(
                            "sis",
                            $hashedPassword,
                            $user_id,
                            $role
                        );

                        if ($update->execute() && $update->affected_rows === 1) {

                            $message = 'Your password has been changed successfully!';
                            $messageType = 'success';

                            // Regenerate CSRF token after successful update.
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        } else {

                            $message = 'Password was not changed. Please try again.';
                            $messageType = 'error';
                        }

                        $update->close();
                    }
                }
            }
        }
    }
}

// Choose the correct dashboard.
$dashboardLink = ($role === 'teacher')
    ? 'teacher_dashboard.php'
    : 'index.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Change Password | GradeTrack</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: #f4f7fc;
    color: #172033;
    min-height: 100vh;
}

header {
    background: white;
    padding: 20px 8%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 15px rgba(0,0,0,.05);
}

.logo {
    color: #14213d;
    font-size: 24px;
    font-weight: 800;
    text-decoration: none;
}

.logo span {
    color: #2879df;
}

.header-link {
    color: #2879df;
    text-decoration: none;
    font-weight: 600;
}

.container {
    max-width: 650px;
    margin: 55px auto;
    padding: 0 20px;
}

.card {
    background: white;
    padding: 35px;
    border: 1px solid #e7edf5;
    border-radius: 13px;
    box-shadow: 0 8px 25px rgba(20,45,85,.05);
}

h1 {
    color: #14213d;
    font-size: 27px;
    margin-bottom: 10px;
}

.description {
    color: #718096;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 25px;
}

.form-group {
    margin-bottom: 20px;
}

label {
    display: block;
    margin-bottom: 8px;
    color: #334155;
    font-weight: 650;
    font-size: 14px;
}

input {
    width: 100%;
    padding: 14px;
    border: 1px solid #d5deeb;
    border-radius: 8px;
    background: #fbfcfe;
    font-size: 15px;
}

input:focus {
    outline: none;
    border-color: #2879df;
    box-shadow: 0 0 0 3px rgba(40,121,223,.12);
}

button {
    width: 100%;
    background: #2879df;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 15px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}

button:hover {
    background: #175fb9;
}

.message {
    padding: 13px 15px;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 22px;
    line-height: 1.5;
}

.success {
    background: #e9f9ef;
    border: 1px solid #b7ebc8;
    color: #176b38;
}

.error {
    background: #fff0f0;
    border: 1px solid #ffcaca;
    color: #b42318;
}

.back-link {
    display: inline-block;
    margin-top: 24px;
    color: #2879df;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}

footer {
    text-align: center;
    color: #8290a3;
    padding: 25px;
    font-size: 12px;
}

@media(max-width: 600px) {
    header {
        padding: 18px 5%;
    }

    .card {
        padding: 23px;
    }
}
</style>
</head>

<body>

<header>
    <a href="landing.php" class="logo">Grade<span>Track.</span></a>
    <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="header-link">
        ← Dashboard
    </a>
</header>

<div class="container">

    <div class="card">

        <h1>Change Password</h1>

        <p class="description">
            Update your GradeTrack account password.
            Enter your current password and choose a new one.
        </p>

        <?php if ($message !== ''): ?>
            <div class="message <?php echo htmlspecialchars($messageType); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="change_password.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
            >

            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input
                    type="password"
                    id="current_password"
                    name="current_password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <input
                    type="password"
                    id="new_password"
                    name="new_password"
                    minlength="8"
                    maxlength="4096"
                    autocomplete="new-password"
                    required
                >
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    maxlength="4096"
                    autocomplete="new-password"
                    required
                >
            </div>

            <button type="submit">Update Password</button>

        </form>

        <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="back-link">
            ← Return to Dashboard
        </a>

    </div>

</div>

<footer>
    &copy; <?php echo date('Y'); ?>
    GradeTrack Digital Grading System. All rights reserved.
</footer>

</body>
</html>
