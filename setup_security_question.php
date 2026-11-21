
<?php
require_once 'auth.php';
require_once 'db.php';

// Allow only authenticated users.
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    header('Location: login.php');
    exit;
}

// Predefined security questions.
$securityQuestions = [
    "What was your first school's name?",
    "What city were you born in?",
    "What was your first pet's name?",
    "What was your childhood nickname?",
    "What was the name of your favourite teacher?"
];

$message = '';
$messageType = '';

// Generate CSRF token.
if (empty($_SESSION['security_setup_csrf'])) {
    $_SESSION['security_setup_csrf'] = bin2hex(random_bytes(32));
}

// Get current account information.
$stmt = $conn->prepare(
    "SELECT username, password, security_question
     FROM users
     WHERE id = ? AND status = 'active'
     LIMIT 1"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(403);
    exit('Account not found or inactive.');
}

// Process form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['security_setup_csrf'], $csrfToken)
    ) {
        $message = 'Invalid request. Please refresh the page and try again.';
        $messageType = 'error';

    } else {

        $question = trim($_POST['security_question'] ?? '');
        $answer = trim($_POST['security_answer'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';

        // Check that the selected question is predefined.
        if (!in_array($question, $securityQuestions, true)) {

            $message = 'Please select a valid security question.';
            $messageType = 'error';

        } elseif (
            !is_string($answer) ||
            strlen($answer) < 2 ||
            strlen($answer) > 100
        ) {

            $message = 'Your security answer must contain between 2 and 100 characters.';
            $messageType = 'error';

        } elseif (
            !is_string($currentPassword) ||
            !password_verify($currentPassword, $user['password'])
        ) {

            $message = 'Your current password is incorrect.';
            $messageType = 'error';

        } else {

            // Normalize the answer before securely hashing it.
            $normalizedAnswer = strtolower(trim($answer));

            $answerHash = password_hash(
                $normalizedAnswer,
                PASSWORD_DEFAULT
            );

            if ($answerHash === false) {

                $message = 'Unable to secure your answer. Please try again.';
                $messageType = 'error';

            } else {

                // Save the question and hashed answer.
                // Clear any old password-reset tokens.
                $update = $conn->prepare(
                    "UPDATE users
                     SET security_question = ?,
                         security_answer_hash = ?,
                         reset_token_hash = NULL,
                         reset_token_expires = NULL
                     WHERE id = ?
                     AND status = 'active'"
                );

                $update->bind_param(
                    "ssi",
                    $question,
                    $answerHash,
                    $user_id
                );

                if ($update->execute() && $update->affected_rows === 1) {

                    $message = 'Your security question has been saved successfully.';
                    $messageType = 'success';

                    // Refresh CSRF token.
                    $_SESSION['security_setup_csrf'] =
                        bin2hex(random_bytes(32));

                } else {

                    $message = 'Unable to save your security question. Please try again.';
                    $messageType = 'error';
                }

                $update->close();
            }
        }
    }
}

$username = htmlspecialchars(
    $user['username'],
    ENT_QUOTES,
    'UTF-8'
);

$currentQuestion = $user['security_question'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Security Setup | GradeTrack</title>

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
    background: #f4f7fb;
    font-family: Arial, sans-serif;
    color: #1e293b;
}

.container {
    width: 100%;
    max-width: 480px;
    background: white;
    padding: 35px;
    border-radius: 15px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 35px rgba(16,36,71,.08);
}

.brand {
    text-align: center;
    color: #102447;
    font-size: 26px;
    font-weight: bold;
    margin-bottom: 25px;
}

.brand span {
    color: #2563eb;
}

h1 {
    text-align: center;
    color: #102447;
    font-size: 23px;
    margin-bottom: 10px;
}

.description {
    text-align: center;
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 25px;
}

label {
    display: block;
    margin: 16px 0 8px;
    font-size: 14px;
    font-weight: bold;
    color: #102447;
}

input, select {
    width: 100%;
    padding: 13px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    background: white;
}

input:focus, select:focus {
    outline: none;
    border-color: #2563eb;
}

button {
    width: 100%;
    padding: 14px;
    margin-top: 22px;
    background: #2563eb;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
}

button:hover {
    background: #1d4ed8;
}

.message {
    padding: 13px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 14px;
    line-height: 1.5;
}

.success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.error {
    background: #fff1f2;
    color: #9f1239;
    border: 1px solid #fecdd3;
}

.note {
    margin-top: 20px;
    padding: 14px;
    background: #eff6ff;
    border: 1px solid #dbeafe;
    border-radius: 8px;
    font-size: 13px;
    color: #334155;
    line-height: 1.6;
}

.back {
    display: block;
    margin-top: 22px;
    text-align: center;
    text-decoration: none;
    color: #2563eb;
    font-size: 14px;
    font-weight: bold;
}

@media(max-width: 480px) {
    .container {
        padding: 25px 20px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="brand">
        Grade<span>Track</span>
    </div>

    <h1>Set Up Security Question</h1>

    <p class="description">
        Hello, <?= $username ?>.
        Set up a security question to help recover your account
        if you forget your password.
    </p>

    <?php if ($message !== ''): ?>
        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $_SESSION['security_setup_csrf'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <label for="security_question">
            Choose a security question
        </label>

        <select
            name="security_question"
            id="security_question"
            required
        >
            <option value="">-- Select a question --</option>

            <?php foreach ($securityQuestions as $question): ?>
                <option
                    value="<?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $currentQuestion === $question ? 'selected' : '' ?>
                >
                    <?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>

        </select>

        <label for="security_answer">
            Your answer
        </label>

        <input
            type="text"
            name="security_answer"
            id="security_answer"
            minlength="2"
            maxlength="100"
            autocomplete="off"
            placeholder="Enter your answer"
            required
        >

        <label for="current_password">
            Your current password
        </label>

        <input
            type="password"
            name="current_password"
            id="current_password"
            autocomplete="current-password"
            placeholder="Enter your current password"
            required
        >

        <button type="submit">
            Save Security Question
        </button>

    </form>

    <div class="note">
        <strong>Security tip:</strong>
        Choose an answer you can remember. Your answer is stored
        as a secure password hash. Avoid answers that other people
        can easily guess.
    </div>

    <a href="dashboard_redirect.php" class="back">
        Back to Dashboard
    </a>

</div>

</body>
</html>
