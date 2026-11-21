
<?php
require_once 'auth.php';
require_once 'db.php';

// Admin access only.
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can register students.');
}

$message = '';
$messageType = '';

$student_id = '';
$student_name = '';
$course = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $student_id = trim($_POST['student_id'] ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        $student_id === '' ||
        $student_name === '' ||
        $course === '' ||
        $password === ''
    ) {
        $message = 'Please fill in all fields.';
        $messageType = 'error';

    } elseif (strlen($password) < 8) {
        $message = 'Password must contain at least 8 characters.';
        $messageType = 'error';

    } elseif (strlen($password) > 4096) {
        $message = 'Password is too long.';
        $messageType = 'error';

    } else {

        // Hash password securely.
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        if ($hashedPassword === false) {
            $message = 'Unable to secure the password. Please try again.';
            $messageType = 'error';

        } else {

            try {
                $conn->begin_transaction();

                // Check whether the student ID already exists.
                $check = $conn->prepare(
                    "SELECT student_id FROM students WHERE student_id = ?"
                );

                if (!$check) {
                    throw new Exception('Unable to check student records.');
                }

                $check->bind_param("s", $student_id);
                $check->execute();
                $existingStudent = $check->get_result()->fetch_assoc();
                $check->close();

                if ($existingStudent) {
                    throw new Exception('This Student ID is already registered.');
                }

                // Check whether a login account already uses this username or student ID.
                $checkUser = $conn->prepare(
                    "SELECT id FROM users
                     WHERE username = ? OR student_id = ?"
                );

                if (!$checkUser) {
                    throw new Exception('Unable to check login accounts.');
                }

                $checkUser->bind_param("ss", $student_id, $student_id);
                $checkUser->execute();
                $existingUser = $checkUser->get_result()->fetch_assoc();
                $checkUser->close();

                if ($existingUser) {
                    throw new Exception(
                        'A login account already exists with this Student ID.'
                    );
                }

                // Create the academic student record.
                $studentStmt = $conn->prepare(
                    "INSERT INTO students (student_id, student_name, course)
                     VALUES (?, ?, ?)"
                );

                if (!$studentStmt) {
                    throw new Exception('Unable to prepare student registration.');
                }

                $studentStmt->bind_param(
                    "sss",
                    $student_id,
                    $student_name,
                    $course
                );

                if (!$studentStmt->execute()) {
                    throw new Exception('Student registration failed.');
                }

                $studentStmt->close();

                // Create the student's login account.
                $role = 'student';
                $status = 'active';

                $userStmt = $conn->prepare(
                    "INSERT INTO users
                     (username, password, role, student_id, status)
                     VALUES (?, ?, ?, ?, ?)"
                );

                if (!$userStmt) {
                    throw new Exception('Unable to prepare student login account.');
                }

                $userStmt->bind_param(
                    "sssss",
                    $student_id,
                    $hashedPassword,
                    $role,
                    $student_id,
                    $status
                );

                if (!$userStmt->execute()) {
                    throw new Exception('Student login account could not be created.');
                }

                $userStmt->close();

                // Save both records together.
                $conn->commit();

                $message = 'Student registered successfully! Login account created. Username: '
                    . $student_id;
                $messageType = 'success';

                $student_id = '';
                $student_name = '';
                $course = '';

            } catch (Throwable $e) {

                $conn->rollback();

                error_log('Student registration error: ' . $e->getMessage());

                $message = $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register Student | GradeTrack</title>

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
    font-size: 14px;
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
    margin-bottom: 9px;
    color: #14213d;
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

.info-card .icon {
    font-size: 34px;
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

.form-description {
    color: #8290a3;
    font-size: 13px;
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
    outline: none;
}

input:focus {
    border-color: #2879df;
    box-shadow: 0 0 0 3px rgba(40,121,223,.12);
    background: white;
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
}

.submit-btn:hover {
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
    <a href="landing.php" class="logo">Grade<span>Track.</span></a>
    <a href="dashboard_redirect.php" class="header-link">← Back to Dashboard</a>
</header>

<div class="container">

    <div class="page-heading">
        <h1>Student Registration</h1>
        <p>Register students and create their login accounts.</p>
    </div>

    <div class="form-layout">

        <div class="info-card">
            <div class="icon">♙</div>
            <h2>Student Information</h2>
            <p>
                Register the student's academic details and create
                their GradeTrack login account at the same time.
            </p>
        </div>

        <div class="form-card">

            <h2>Register a New Student</h2>

            <p class="form-description">
                Enter the student's details and choose an initial password.
            </p>

            <?php if ($message !== ''): ?>
                <div class="message <?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="add_student.php">

                <div class="form-group">
                    <label for="student_id">Student ID</label>
                    <input
                        type="text"
                        id="student_id"
                        name="student_id"
                        placeholder="e.g. ZTU004"
                        maxlength="20"
                        value="<?php echo htmlspecialchars($student_id); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="student_name">Student Full Name</label>
                    <input
                        type="text"
                        id="student_name"
                        name="student_name"
                        placeholder="Enter student's full name"
                        maxlength="100"
                        value="<?php echo htmlspecialchars($student_name); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="course">Course / Programme</label>
                    <input
                        type="text"
                        id="course"
                        name="course"
                        placeholder="e.g. Diploma in IT"
                        maxlength="100"
                        value="<?php echo htmlspecialchars($course); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Initial Student Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Create a password (minimum 8 characters)"
                        minlength="8"
                        maxlength="4096"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit" class="submit-btn">
                    Register Student & Create Login →
                </button>

            </form>

        </div>

    </div>

    <a href="dashboard_redirect.php" class="back-link">
        ← Return to Dashboard
    </a>

</div>

<footer>
    &copy; <?php echo date('Y'); ?>
    GradeTrack Digital Grading System. All rights reserved.
</footer>

</body>
</html>
