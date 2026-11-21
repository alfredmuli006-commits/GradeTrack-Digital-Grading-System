
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "db.php";

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";
$securityQuestion = "";
$showQuestion = false;
$resetLink = "";

// Reset link is only displayed after successful verification.
if (!empty($_SESSION['recovery_reset_link'])) {
    $resetLink = $_SESSION['recovery_reset_link'];
}

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token.
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $action = $_POST['action'] ?? '';

        // STEP 1: Find account and display security question.
        if ($action === 'find_account') {

            $username = trim($_POST['username'] ?? '');

            // Clear previous recovery session.
            unset(
                $_SESSION['recovery_user_id'],
                $_SESSION['recovery_reset_link']
            );

            if ($username === '') {
                $error = "Please enter your username.";
            } else {

                $stmt = $conn->prepare(
                    "SELECT id, security_question
                     FROM users
                     WHERE username = ? AND status = 'active'
                     LIMIT 1"
                );

                if (!$stmt) {
                    $error = "A system error occurred. Please try again.";
                } else {

                    $stmt->bind_param("s", $username);
                    $stmt->execute();

                    $result = $stmt->get_result();
                    $user = $result->fetch_assoc();

                    $stmt->close();

                    if (
                        $user &&
                        !empty($user['security_question'])
                    ) {
                        $_SESSION['recovery_user_id'] =
                            (int) $user['id'];

                        $_SESSION['recovery_question'] =
                            $user['security_question'];

                        $_SESSION['recovery_attempts'] = 0;

                        $securityQuestion =
                            $user['security_question'];

                        $showQuestion = true;

                    } else {
                        $error =
                            "We could not start recovery for that account. " .
                            "Check your username or contact your administrator.";
                    }
                }
            }
        }

        // STEP 2: Verify answer and generate reset link.
        elseif ($action === 'verify_answer') {

            $userId = (int) (
                $_SESSION['recovery_user_id'] ?? 0
            );

            $answer = strtolower(
                trim($_POST['security_answer'] ?? '')
            );

            if ($userId <= 0) {
                $error = "Your recovery session has expired. Start again.";
            } elseif ($answer === '') {
                $error = "Please enter your security answer.";
                $showQuestion = true;
                $securityQuestion =
                    $_SESSION['recovery_question'] ?? '';
            } elseif (
                ($_SESSION['recovery_attempts'] ?? 0) >= 5
            ) {
                $error =
                    "Too many attempts. Please start a new recovery session later.";
            } else {

                $_SESSION['recovery_attempts'] =
                    ($_SESSION['recovery_attempts'] ?? 0) + 1;

                $stmt = $conn->prepare(
                    "SELECT security_answer_hash
                     FROM users
                     WHERE id = ? AND status = 'active'
                     LIMIT 1"
                );

                if (!$stmt) {
                    $error = "A system error occurred. Please try again.";
                } else {

                    $stmt->bind_param("i", $userId);
                    $stmt->execute();

                    $result = $stmt->get_result();
                    $user = $result->fetch_assoc();

                    $stmt->close();

                    if (
                        $user &&
                        !empty($user['security_answer_hash']) &&
                        password_verify(
                            $answer,
                            $user['security_answer_hash']
                        )
                    ) {

                        // Generate a secure random reset token.
                        $rawToken = bin2hex(random_bytes(32));

                        // Store only its SHA-256 hash.
                        $tokenHash = hash('sha256', $rawToken);

                        $update = $conn->prepare(
                            "UPDATE users
                             SET reset_token_hash = ?,
                                 reset_token_expires =
                                     DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                             WHERE id = ? AND status = 'active'"
                        );

                        if (!$update) {
                            $error =
                                "Could not generate the reset link. Please try again.";
                        } else {

                            $update->bind_param(
                                "si",
                                $tokenHash,
                                $userId
                            );

                            if ($update->execute() &&
                                $update->affected_rows === 1) {

                                // Build relative reset URL.
                                $_SESSION['recovery_reset_link'] =
                                    "reset_password.php?token=" .
                                    urlencode($rawToken);

                                $resetLink =
                                    $_SESSION['recovery_reset_link'];

                                $message =
                                    "Your security answer is correct. " .
                                    "Your password reset link is ready.";

                                $showQuestion = false;

                                unset(
                                    $_SESSION['recovery_user_id'],
                                    $_SESSION['recovery_question'],
                                    $_SESSION['recovery_attempts']
                                );

                            } else {
                                $error =
                                    "Could not generate the reset link. Please try again.";
                            }

                            $update->close();
                        }

                    } else {
                        $error = "Incorrect security answer. Please try again.";

                        $showQuestion = true;

                        $securityQuestion =
                            $_SESSION['recovery_question'] ?? '';
                    }
                }
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

    <title>Forgot Password | GradeTrack</title>

    <style>
        :root {
            --navy: #102447;
            --blue: #2563eb;
            --background: #f4f7fb;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: Arial, sans-serif;
            background: var(--background);
            color: var(--text);
        }

        .card {
            width: 100%;
            max-width: 460px;
            padding: 35px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 15px;
            box-shadow: 0 10px 35px rgba(16, 36, 71, 0.08);
        }

        .brand {
            text-align: center;
            color: var(--navy);
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 30px;
        }

        .brand span {
            color: var(--blue);
        }

        h1 {
            margin-bottom: 12px;
            text-align: center;
            color: var(--navy);
            font-size: 25px;
        }

        .intro {
            color: var(--muted);
            text-align: center;
            line-height: 1.7;
            font-size: 14px;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin: 17px 0 8px;
            font-weight: bold;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
        }

        button, .reset-link {
            display: block;
            width: 100%;
            margin-top: 20px;
            padding: 13px;
            text-align: center;
            text-decoration: none;
            color: white;
            background: var(--blue);
            border: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover, .reset-link:hover {
            background: #1d4ed8;
        }

        .notice {
            padding: 15px;
            background: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: 10px;
            margin-top: 20px;
            line-height: 1.6;
            font-size: 14px;
        }

        .success {
            padding: 14px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.6;
        }

        .error {
            padding: 14px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 22px;
            color: var(--blue);
            text-decoration: none;
            font-size: 14px;
        }

        .footer {
            margin-top: 25px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        @media (max-width: 480px) {
            .card {
                padding: 25px 20px;
            }
        }
    </style>
</head>

<body>

<main class="card">

    <div class="brand">
        Grade<span>Track</span>
    </div>

    <h1>Forgot Password?</h1>

    <p class="intro">
        Verify your identity using the security question
        registered with your GradeTrack account.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="success">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($resetLink !== ''): ?>

        <div class="notice">
            Your reset link is valid for 15 minutes and can
            only be used once.

            <a class="reset-link"
               href="<?= htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') ?>">
                Continue to Reset Password
            </a>
        </div>

    <?php elseif ($showQuestion): ?>

        <form method="POST" action="forgot_password.php">

            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <input type="hidden" name="action"
                   value="verify_answer">

            <label>Security Question</label>

            <div class="notice">
                <?= htmlspecialchars($securityQuestion, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <label for="security_answer">
                Your Security Answer
            </label>

            <input
                type="text"
                id="security_answer"
                name="security_answer"
                required
                autocomplete="off"
                placeholder="Enter your answer"
            >

            <button type="submit">
                Verify Answer
            </button>

        </form>

    <?php else: ?>

        <form method="POST" action="forgot_password.php">

            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <input type="hidden" name="action"
                   value="find_account">

            <label for="username">
                Registered Username
            </label>

            <input
                type="text"
                id="username"
                name="username"
                required
                autocomplete="username"
                placeholder="Enter your username"
            >

            <button type="submit">
                Continue
            </button>

        </form>

    <?php endif; ?>

    <a href="login.php" class="back-link">
        Back to Login
    </a>

    <div class="footer">
        &copy; <?= date('Y') ?> GradeTrack. All rights reserved.
    </div>

</main>

</body>
</html>
