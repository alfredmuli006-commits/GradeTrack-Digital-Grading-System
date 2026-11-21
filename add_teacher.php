
<?php

require_once 'auth.php';
require_once 'db.php';
require_once 'audit_log.php';

// Only administrators can register teachers.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can register teachers.');
}

$message = '';
$messageType = '';
$username = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {

        $message = 'Invalid request. Please refresh the page.';
        $messageType = 'error';

    } elseif (empty($username) || empty($password) || empty($confirm_password)) {

        $message = 'Please fill in all fields.';
        $messageType = 'error';

    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {

        $message = 'Username must be 3–50 characters and use only letters, numbers, dots, underscores or hyphens.';
        $messageType = 'error';

    } elseif (strlen($password) < 12) {

        $message = 'Password must contain at least 12 characters.';
        $messageType = 'error';

    } elseif ($password !== $confirm_password) {

        $message = 'Passwords do not match.';
        $messageType = 'error';

    } else {

        $check = $conn->prepare(
            "SELECT id FROM users WHERE username = ?"
        );

        if (!$check) {

            $message = 'Unable to verify username. Please try again.';
            $messageType = 'error';

        } else {

            $check->bind_param("s", $username);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {

                $message = 'That username already exists.';
                $messageType = 'error';

            } else {

                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $role = 'teacher';
                $student_id = NULL;

                $stmt = $conn->prepare(
                    "INSERT INTO users (username, password, role, student_id)
                     VALUES (?, ?, ?, ?)"
                );

                if ($stmt) {

                    $stmt->bind_param(
                        "ssss",
                        $username,
                        $hashed_password,
                        $role,
                        $student_id
                    );

                    if ($stmt->execute()) {

                        // Get the newly created teacher ID.
                        $teacher_id = $stmt->insert_id;

                        // Record teacher registration in the audit log.
                        $audit_success = logActivity(
                            $conn,
                            'TEACHER_REGISTERED',
                            'USER',
                            $teacher_id,
                            'Administrator registered teacher account: ' . $username,
                            $teacher_id,
                            null,
                            [
                                'username' => $username,
                                'role' => 'teacher'
                            ]
                        );

                        if (!$audit_success) {
                            error_log(
                                'Teacher was registered, but audit logging failed. Teacher ID: '
                                . $teacher_id
                            );
                        }

                        $message = 'Teacher account created successfully!';
                        $messageType = 'success';

                        $username = '';

                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    } else {

                        $message = 'Could not create teacher account. Please try again.';
                        $messageType = 'error';
                    }

                    $stmt->close();

                } else {

                    $message = 'Unable to process registration.';
                    $messageType = 'error';
                }
            }

            $check->close();
        }
    }
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Register Teacher | GradeTrack</title>

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
    font-size: 24px;
    font-weight: 800;
    color: #14213d;
    text-decoration: none;
}

.logo span {
    color: #2879df;
}

.header-link {
    color: #2879df;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}

.container {
    max-width: 1000px;
    margin: 45px auto;
    padding: 0 20px;
}

.page-heading {
    margin-bottom: 28px;
}

.page-heading h1 {
    font-size: 29px;
    color: #14213d;
    margin-bottom: 9px;
}

.page-heading p {
    color: #718096;
    font-size: 14px;
    line-height: 1.6;
}

.form-layout {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 25px;
    align-items: start;
}

.info-card {
    background: linear-gradient(145deg, #142b4d, #1d4e91);
    color: white;
    padding: 28px;
    border-radius: 13px;
}

.info-icon {
    font-size: 35px;
    margin-bottom: 22px;
}

.info-card h2 {
    font-size: 20px;
    margin-bottom: 12px;
}

.info-card p {
    color: #d2e1f3;
    line-height: 1.8;
    font-size: 14px;
}

.form-card {
    background: white;
    padding: 32px;
    border: 1px solid #e7edf5;
    border-radius: 13px;
    box-shadow: 0 8px 25px rgba(20,45,85,.04);
}

.form-card h2 {
    font-size: 19px;
    margin-bottom: 8px;
}

.description {
    color: #8290a3;
    font-size: 13px;
    margin-bottom: 25px;
    line-height: 1.6;
}

.form-group {
    margin-bottom: 20px;
}

label {
    display: block;
    margin-bottom: 8px;
    color: #334155;
    font-size: 14px;
    font-weight: 650;
}

.input-wrapper {
    position: relative;
}

input {
    width: 100%;
    padding: 14px;
    border: 1px solid #d5deeb;
    border-radius: 8px;
    background: #fbfcfe;
    font-size: 15px;
    outline: none;
    transition: .2s;
}

input:focus {
    border-color: #2879df;
    box-shadow: 0 0 0 3px rgba(40,121,223,.12);
    background: white;
}

.input-wrapper input {
    padding-right: 70px;
}

.toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: #2879df;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.hint {
    font-size: 12px;
    color: #8290a3;
    margin-top: 7px;
}

.submit-btn {
    width: 100%;
    background: #2879df;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 15px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: .2s;
}

.submit-btn:hover {
    background: #175fb9;
    transform: translateY(-1px);
}

.message {
    padding: 13px 15px;
    border-radius: 8px;
    margin-bottom: 22px;
    font-size: 14px;
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
    margin-top: 25px;
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

@media(max-width: 750px) {

    .form-layout {
        grid-template-columns: 1fr;
    }

    header {
        padding: 18px 5%;
    }

    .container {
        margin: 30px auto;
    }

    .form-card {
        padding: 23px;
    }
}

</style>

</head>

<body>

<header>

    <a href="landing.php" class="logo">
        Grade<span>Track.</span>
    </a>

    <a href="dashboard_redirect.php" class="header-link">
        ← Back to Dashboard
    </a>

</header>

<div class="container">

    <div class="page-heading">
        <h1>Teacher Registration</h1>
        <p>Create secure accounts for teachers who will manage student grades.</p>
    </div>

    <div class="form-layout">

        <div class="info-card">

            <div class="info-icon">♙</div>

            <h2>Teacher Accounts</h2>

            <p>
                Create teacher accounts to allow authorized
                teaching staff to enter, review and manage
                student academic grades.
            </p>

        </div>

        <div class="form-card">

            <h2>Create Teacher Account</h2>

            <p class="description">
                Enter the username and password details below.
                Passwords must contain at least 12 characters.
            </p>

            <?php if ($message !== ''): ?>

                <div class="message <?= h($messageType) ?>">
                    <?= h($message) ?>
                </div>

            <?php endif; ?>

            <form method="POST" action="add_teacher.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['csrf_token']) ?>"
                >

                <div class="form-group">

                    <label for="username">Teacher Username</label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter teacher username"
                        required
                        minlength="3"
                        maxlength="50"
                        pattern="[a-zA-Z0-9_.-]{3,50}"
                        value="<?= h($username) ?>"
                    >

                    <p class="hint">
                        Use letters, numbers, dots, underscores or hyphens.
                    </p>

                </div>

                <div class="form-group">

                    <label for="password">Password</label>

                    <div class="input-wrapper">

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Create a strong password"
                            required
                            minlength="12"
                        >

                        <button
                            type="button"
                            class="toggle"
                            onclick="togglePassword('password', this)"
                        >Show</button>

                    </div>

                </div>

                <div class="form-group">

                    <label for="confirm_password">Confirm Password</label>

                    <div class="input-wrapper">

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Enter password again"
                            required
                            minlength="12"
                        >

                        <button
                            type="button"
                            class="toggle"
                            onclick="togglePassword('confirm_password', this)"
                        >Show</button>

                    </div>

                </div>

                <button type="submit" class="submit-btn">
                    Create Teacher Account →
                </button>

            </form>

        </div>

    </div>

    <a href="dashboard_redirect.php" class="back-link">
        ← Return to Dashboard
    </a>

</div>

<footer>
    &copy; <?= date('Y') ?>
    GradeTrack Digital Grading System. All rights reserved.
</footer>

<script>

function togglePassword(fieldId, button) {

    const field = document.getElementById(fieldId);

    if (field.type === 'password') {

        field.type = 'text';
        button.textContent = 'Hide';

    } else {

        field.type = 'password';
        button.textContent = 'Show';

    }
}

</script>

</body>
</html>
