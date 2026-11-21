
<?php
session_start();
require_once "auth.php";
require_once "db.php";

// Only administrators can manage teachers
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

// Activate or deactivate a teacher
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = "Invalid security token. Please refresh the page.";
    } else {
        $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? '';

        if (!$teacher_id || !in_array($status, ['active', 'inactive'], true)) {
            $error = "Invalid request.";
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET status = ? WHERE id = ? AND role = 'teacher'"
            );

            if ($stmt) {
                $stmt->bind_param("si", $status, $teacher_id);

                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    $message = "Teacher account status updated successfully.";
                } else {
                    $error = "Could not update teacher account.";
                }

                $stmt->close();
            } else {
                $error = "Database query failed.";
            }
        }
    }
}

// Retrieve teacher accounts
$teachers = $conn->query(
    "SELECT id, username, status FROM users
     WHERE role = 'teacher'
     ORDER BY username"
);

if (!$teachers) {
    die("Could not retrieve teacher accounts. Check that the users table has a status column.");
}

$totalTeachers = $teachers->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Teachers | GradeTrack</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #172033;
            min-height: 100vh;
        }

        .topbar {
            background: #101d3a;
            color: white;
            padding: 18px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .brand {
            font-size: 23px;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        .brand span {
            color: #4da3ff;
        }

        .topbar small {
            color: #cbd5e1;
            font-size: 13px;
        }

        .page {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .heading {
            margin-bottom: 25px;
        }

        .heading h1 {
            font-size: 29px;
            margin-bottom: 9px;
            color: #101d3a;
        }

        .heading p {
            color: #64748b;
            line-height: 1.6;
        }

        .summary {
            background: white;
            border: 1px solid #e5eaf2;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.04);
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            background: #eaf3ff;
            color: #1769c2;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
        }

        .summary h2 {
            font-size: 25px;
            color: #101d3a;
            margin-bottom: 4px;
        }

        .summary p {
            font-size: 13px;
            color: #64748b;
        }

        .panel {
            background: white;
            border: 1px solid #e5eaf2;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.04);
        }

        .panel-heading {
            margin-bottom: 20px;
        }

        .panel-heading h2 {
            font-size: 19px;
            color: #101d3a;
            margin-bottom: 6px;
        }

        .panel-heading p {
            color: #64748b;
            font-size: 13px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .success {
            background: #e9f8ef;
            border: 1px solid #b8e8c8;
            color: #18733b;
        }

        .error {
            background: #fff0f0;
            border: 1px solid #f2c2c2;
            color: #b42318;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 550px;
        }

        th {
            background: #f1f5f9;
            color: #475569;
            text-align: left;
            padding: 15px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 16px 15px;
            border-bottom: 1px solid #edf0f5;
            font-size: 14px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .username {
            font-weight: bold;
            color: #172033;
        }

        .status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .active {
            background: #e5f7ec;
            color: #168044;
        }

        .inactive {
            background: #feecec;
            color: #c0392b;
        }

        button {
            border: none;
            padding: 9px 15px;
            border-radius: 7px;
            color: white;
            font-weight: bold;
            font-size: 12px;
            cursor: pointer;
            transition: 0.2s;
        }

        button:hover {
            opacity: 0.85;
            transform: translateY(-1px);
        }

        .activate {
            background: #168044;
        }

        .deactivate {
            background: #dc3545;
        }

        .empty {
            text-align: center;
            padding: 35px;
            color: #64748b;
        }

        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 25px;
        }

        .back-link {
            display: inline-block;
            text-decoration: none;
            background: #101d3a;
            color: white;
            padding: 12px 19px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: bold;
        }

        .back-link:hover {
            background: #1c315b;
        }

        .note {
            font-size: 12px;
            color: #64748b;
        }

        footer {
            text-align: center;
            padding: 25px 15px;
            color: #94a3b8;
            font-size: 12px;
        }

        @media (max-width: 600px) {
            .topbar {
                padding: 17px 20px;
            }

            .page {
                margin: 25px auto;
            }

            .heading h1 {
                font-size: 24px;
            }

            .panel {
                padding: 17px;
            }

            .summary {
                padding: 17px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">Grade<span>Track</span></div>
    <small>Administrator Portal</small>
</header>

<main class="page">

    <div class="heading">
        <h1>Manage Teachers</h1>
        <p>Manage teacher accounts and control their access to the grading system.</p>
    </div>

    <section class="summary">
        <div class="summary-icon">👨‍🏫</div>
        <div>
            <h2><?php echo (int)$totalTeachers; ?></h2>
            <p>Registered teacher accounts</p>
        </div>
    </section>

    <section class="panel">

        <div class="panel-heading">
            <h2>Teacher Accounts</h2>
            <p>Activate accounts to allow teachers to log in, or deactivate accounts to restrict future logins.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert success">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Teacher Username</th>
                        <th>Account Status</th>
                        <th>Manage Account</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($totalTeachers === 0): ?>
                        <tr>
                            <td colspan="3" class="empty">
                                No teacher accounts have been registered yet.
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php while ($teacher = $teachers->fetch_assoc()): ?>
                            <tr>
                                <td class="username">
                                    <?php echo htmlspecialchars($teacher['username']); ?>
                                </td>

                                <td>
                                    <?php if ($teacher['status'] === 'active'): ?>
                                        <span class="status active">Active</span>
                                    <?php else: ?>
                                        <span class="status inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <form method="POST"
                                          onsubmit="return confirm('Are you sure you want to change this teacher account status?');">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                                        <input type="hidden"
                                               name="teacher_id"
                                               value="<?php echo (int)$teacher['id']; ?>">

                                        <?php if ($teacher['status'] === 'active'): ?>

                                            <input type="hidden"
                                                   name="status"
                                                   value="inactive">

                                            <button type="submit" class="deactivate">
                                                Deactivate Account
                                            </button>

                                        <?php else: ?>

                                            <input type="hidden"
                                                   name="status"
                                                   value="active">

                                            <button type="submit" class="activate">
                                                Activate Account
                                            </button>

                                        <?php endif; ?>

                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="actions">
            <a href="dashboard_redirect.php" class="back-link">
                ← Back to Dashboard
            </a>

            <span class="note">
                Only administrators can manage teacher accounts.
            </span>
        </div>

    </section>

</main>

<footer>
    © <?php echo date('Y'); ?> GradeTrack. Digital Grading Management System.
</footer>

</body>
</html>
