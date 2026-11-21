
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "db.php";

$error = "";
$success = "";
$validToken = false;

// Get reset token from URL or form.
$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? trim($_POST['token'] ?? '')
    : trim($_GET['token'] ?? '');

// Validate token format.
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = "Invalid or missing password reset link.";
} else {

    $tokenHash = hash('sha256', $token);

    // Check whether token exists and has not expired.
    $stmt = $conn->prepare(
        "SELECT id
         FROM users
         WHERE reset_token_hash = ?
           AND reset_token_expires > NOW()
           AND status = 'active'
         LIMIT 1"
    );

    if (!$stmt) {
        $error = "A system error occurred. Please try again.";
    } else {

        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        $stmt->close();

        if ($user) {
            $validToken = true;
        } else {
            $error =
                "This reset link is invalid, expired, or has already been used. Please start password recovery again.";
        }
    }
}

// Handle password submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {

    // Verify CSRF token.
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['reset_csrf_token']) ||
        !hash_equals(
            $_SESSION['reset_csrf_token'],
            $_POST['csrf_token']
        )
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 8) {
            $error = "Your password must contain at least 8 characters.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "The passwords do not match.";
        } else {

            // Hash the new password.
            $passwordHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                $error = "Could not securely process your password.";
            } else {

                // Update password and consume the token.
                $stmt = $conn->prepare(
                    "UPDATE users
                     SET password = ?,
                         reset_token_hash = NULL,
                         reset_token_expires = NULL
                     WHERE reset_token_hash = ?
                       AND reset_token_expires > NOW()
                       AND status = 'active'"
                );

                if (!$stmt) {
                    $error = "A system error occurred. Please try again.";
                } else {

                    $stmt->bind_param(
                        "ss",
                        $passwordHash,
                        $tokenHash
                    );

                    if ($stmt->execute() &&
                        $stmt->affected_rows === 1) {

                        $success =
                            "Your password has been reset successfully. You can now log in using your new password.";

                        $validToken = false;

                        unset(
                            $_SESSION['reset_csrf_token'],
                            $_SESSION['recovery_reset_link']
                        );

                        // Prevent token reuse.
                        $token = "";

                    } else {
                        $error =
                            "The reset link is no longer valid. Please start password recovery again.";
                        $validToken = false;
                    }

                    $stmt->close();
                }
            }
        }
    }
}

// Generate CSRF token for the password form.
if ($validToken && empty($_SESSION['reset_csrf_token'])) {
    $_SESSION['reset_csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reset Password | GradeTrack</title>

    <style>
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
            background: #f4f7fb;
            color: #1e293b;
        }

        .card {
            width: 100%;
            max-width: 460px;
            padding: 35px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 15px;
            box-shadow: 0 10px 35px rgba(16, 36, 71, 0.08);
        }

        .brand {
            text-align: center;
            color: #102447;
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 30px;
        }

        .brand span {
            color: #2563eb;
        }

        h1 {
            text-align: center;
            color: #102447;
            font-size: 25px;
        }

        .intro {
            color: #64748b;
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
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
        }

        button, .login-link {
            display: block;
            width: 100%;
            margin-top: 20px;
            padding: 13px;
            text-align: center;
            text-decoration: none;
            color: white;
            background: #2563eb;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover, .login-link:hover {
            background: #1d4ed8;
        }

        .success {
            padding: 15px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            border-radius: 8px;
            line-height: 1.6;
            font-size: 14px;
        }

        .error {
            padding: 15px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 8px;
            line-height: 1.6;
            font-size: 14px;
        }

        .footer {
            margin-top: 25px;
            text-align: center;
            color: #64748b;
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

    <h1>Reset Password</h1>

    <p class="intro">
        Create a new password for your GradeTrack account.
    </p>

    <?php if ($success !== ''): ?>

        <div class="success">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <a href="login.php" class="login-link">
            Go to Login
        </a>

    <?php elseif ($error !== ''): ?>

        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <a href="forgot_password.php" class="login-link">
            Start Again
        </a>

    <?php elseif ($validToken): ?>

        <form method="POST" action="reset_password.php">

            <input type="hidden" name="token"
                   value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['reset_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <label for="new_password">
                New Password
            </label>

            <input
                type="password"
                id="new_password"
                name="new_password"
                minlength="8"
                required
                autocomplete="new-password"
                placeholder="Enter your new password"
            >

            <label for="confirm_password">
                Confirm New Password
            </label>

            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                minlength="8"
                required
                autocomplete="new-password"
                placeholder="Confirm your new password"
            >

            <button type="submit">
                Reset Password
            </button>

        </form>

    <?php endif; ?>

    <div class="footer">
        &copy; <?= date('Y') ?> GradeTrack. All rights reserved.
    </div>

</main>

</body>
</html>
