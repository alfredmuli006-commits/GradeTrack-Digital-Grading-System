
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {

        $error = 'Please enter both username and password.';

    } else {

        $stmt = $conn->prepare(
            "SELECT id, username, password, role, student_id, status
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        if ($stmt) {

            $stmt->bind_param("s", $username);
            $stmt->execute();

            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {

                if ($user['status'] !== 'active') {

                    $error = 'Your account has been deactivated. Please contact the administrator.';

                } elseif (!in_array($user['role'], ['admin', 'teacher', 'student'], true)) {

                    $error = 'Your account has an invalid role. Please contact the administrator.';

                } elseif (
                    $user['role'] === 'student' &&
                    empty($user['student_id'])
                ) {

                    $error = 'Your student account is not linked to a student record. Please contact the administrator.';

                } else {

                    // Regenerate session ID after successful authentication.
                    session_regenerate_id(true);

                    // Store authenticated user information.
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['student_id'] = $user['student_id'];

                    // Redirect according to the user's role.
                    if ($user['role'] === 'admin') {

                        header('Location: admin_dashboard.php');

                    } elseif ($user['role'] === 'teacher') {

                        header('Location: teacher_dashboard.php');

                    } elseif ($user['role'] === 'student') {

                        header('Location: index.php');

                    }

                    exit;
                }

            } else {

                $error = 'Invalid username or password.';
            }

        } else {

            error_log("Login query preparation failed: " . $conn->error);
            $error = 'Login is temporarily unavailable. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Login | GradeTrack</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: #f4f7fc;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    color: #172033;
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
    font-size: 25px;
    font-weight: 800;
    color: #14213d;
    text-decoration: none;
}

.logo span {
    color: #2879df;
}

.home-link {
    text-decoration: none;
    color: #2879df;
    font-weight: 600;
    font-size: 14px;
}

main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 45px 20px;
    background: linear-gradient(135deg, #edf3fc, #f8faff);
}

.login-card {
    width: 100%;
    max-width: 440px;
    background: white;
    padding: 40px;
    border-radius: 15px;
    box-shadow: 0 12px 40px rgba(20, 45, 85, .09);
    border: 1px solid #e8edf5;
}

.login-icon {
    width: 65px;
    height: 65px;
    border-radius: 15px;
    background: #eaf2ff;
    color: #2879df;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 30px;
    margin: 0 auto 22px;
}

h1 {
    text-align: center;
    font-size: 27px;
    color: #14213d;
    margin-bottom: 10px;
}

.subtitle {
    text-align: center;
    color: #718096;
    font-size: 14px;
    margin-bottom: 30px;
    line-height: 1.6;
}

.error {
    background: #fff0f0;
    border: 1px solid #ffcaca;
    color: #b42318;
    padding: 13px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 20px;
    line-height: 1.5;
}

label {
    display: block;
    font-size: 14px;
    font-weight: 650;
    color: #334155;
    margin-bottom: 8px;
}

.input-group {
    margin-bottom: 20px;
}

.input-group input {
    width: 100%;
    padding: 14px;
    border: 1px solid #d5deeb;
    border-radius: 8px;
    font-size: 15px;
    outline: none;
    background: #fbfcfe;
    color: #172033;
    transition: .2s;
}

.input-group input:focus {
    border-color: #2879df;
    box-shadow: 0 0 0 3px rgba(40,121,223,.12);
    background: white;
}

.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 75px;
}

.toggle-password {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #2879df;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
}

.sign-in {
    width: 100%;
    border: none;
    background: #2879df;
    color: white;
    padding: 15px;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: .2s;
    margin-top: 5px;
}

.sign-in:hover {
    background: #175fb9;
    transform: translateY(-1px);
}

.forgot {
    text-align: center;
    margin-top: 20px;
}

.forgot a {
    color: #2879df;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.forgot a:hover {
    text-decoration: underline;
}

.security {
    border-top: 1px solid #edf0f5;
    margin-top: 25px;
    padding-top: 20px;
    text-align: center;
    color: #8290a3;
    font-size: 12px;
}

footer {
    background: #0c1729;
    color: #aab7ca;
    text-align: center;
    padding: 22px;
    font-size: 12px;
}

@media(max-width: 500px) {
    header {
        padding: 17px 5%;
    }

    .login-card {
        padding: 28px 22px;
    }

    h1 {
        font-size: 24px;
    }
}
</style>

</head>

<body>

<header>

    <a href="landing.php" class="logo">
        Grade<span>Track.</span>
    </a>

    <a href="landing.php" class="home-link">
        ← Back to Home
    </a>

</header>

<main>

    <div class="login-card">

        <div class="login-icon">♙</div>

        <h1>Welcome Back</h1>

        <p class="subtitle">
            Sign in to your GradeTrack account to continue.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">

            <div class="input-group">

                <label for="username">Username</label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Enter your username"
                    autocomplete="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    required
                >

            </div>

            <div class="input-group">

                <label for="password">Password</label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        id="togglePassword"
                    >
                        Show
                    </button>

                </div>

            </div>

            <button type="submit" class="sign-in">
                Sign In →
            </button>

        </form>

        <div class="forgot">
            <a href="forgot_password.php">Forgot your password?</a>
        </div>

        <div class="security">
            🔒 Secure login · Authorized users only
        </div>

    </div>

</main>

<footer>
    &copy; <?= date("Y"); ?>
    GradeTrack Digital Grading System. All rights reserved.
</footer>

<script>
const passwordInput = document.getElementById('password');
const toggleButton = document.getElementById('togglePassword');

toggleButton.addEventListener('click', function () {

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleButton.textContent = 'Hide';
    } else {
        passwordInput.type = 'password';
        toggleButton.textContent = 'Show';
    }

});
</script>

</body>
</html>
