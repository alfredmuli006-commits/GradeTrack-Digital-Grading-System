
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "db.php";

// Admin access only
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}

$search = trim($_GET["search"] ?? "");
$logs = [];
$error = "";

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

try {

    if ($search !== "") {

        $sql = "SELECT
                    a.id,
                    a.grade_id,
                    a.student_id,
                    a.subject,
                    a.action,
                    a.old_marks,
                    a.new_marks,
                    a.old_grade,
                    a.new_grade,
                    a.created_at,
                    u.username
                FROM grade_audit_logs a
                LEFT JOIN users u ON a.performed_by = u.id
                WHERE a.student_id LIKE ?
                   OR a.subject LIKE ?
                   OR u.username LIKE ?
                ORDER BY a.created_at DESC";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Could not prepare audit search query.");
        }

        $term = "%" . $search . "%";
        $stmt->bind_param("sss", $term, $term, $term);

    } else {

        $sql = "SELECT
                    a.id,
                    a.grade_id,
                    a.student_id,
                    a.subject,
                    a.action,
                    a.old_marks,
                    a.new_marks,
                    a.old_grade,
                    a.new_grade,
                    a.created_at,
                    u.username
                FROM grade_audit_logs a
                LEFT JOIN users u ON a.performed_by = u.id
                ORDER BY a.created_at DESC";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Could not prepare audit query.");
        }
    }

    if (!$stmt->execute()) {
        throw new Exception("Could not execute audit query.");
    }

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log("Audit logs error: " . $e->getMessage());
    $error = "Unable to load audit records. Please check the database.";
}

$totalLogs = count($logs);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Audit Logs | GradeTrack</title>

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
        }

        .brand span {
            color: #4da3ff;
        }

        .topbar small {
            color: #cbd5e1;
            font-size: 13px;
        }

        .container {
            max-width: 1250px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #101d3a;
            font-size: 29px;
            margin-bottom: 9px;
        }

        .page-heading p {
            color: #64748b;
            line-height: 1.6;
        }

        .back {
            display: inline-block;
            margin-top: 18px;
            padding: 11px 17px;
            background: #101d3a;
            color: white;
            border-radius: 7px;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
        }

        .back:hover {
            background: #1c315b;
        }

        .summary {
            background: white;
            padding: 22px;
            border: 1px solid #e5eaf2;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: 0 4px 15px rgba(15, 23, 42, .04);
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            background: #eaf3ff;
            color: #1769c2;
            border-radius: 12px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
        }

        .summary h2 {
            font-size: 25px;
            color: #101d3a;
            margin-bottom: 5px;
        }

        .summary p {
            color: #64748b;
            font-size: 13px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            border: 1px solid #e5eaf2;
            box-shadow: 0 4px 15px rgba(15, 23, 42, .04);
        }

        .card-heading {
            margin-bottom: 20px;
        }

        .card-heading h2 {
            color: #101d3a;
            font-size: 19px;
            margin-bottom: 7px;
        }

        .description {
            color: #64748b;
            line-height: 1.6;
            font-size: 13px;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin: 22px 0;
            flex-wrap: wrap;
        }

        .search-form input {
            flex: 1;
            min-width: 220px;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            outline: none;
            font-size: 14px;
        }

        .search-form input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
        }

        button, .clear {
            padding: 12px 17px;
            border: none;
            border-radius: 7px;
            background: #1d4ed8;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
        }

        button:hover {
            background: #2563eb;
        }

        .clear {
            background: #64748b;
            display: inline-flex;
            align-items: center;
        }

        .clear:hover {
            background: #475569;
        }

        .count {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 15px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        th, td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
            white-space: nowrap;
        }

        th {
            background: #f1f5f9;
            color: #475569;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: .4px;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .action {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .insert {
            background: #e5f7ec;
            color: #168044;
        }

        .update {
            background: #eaf3ff;
            color: #1769c2;
        }

        .delete {
            background: #feecec;
            color: #c0392b;
        }

        .other {
            background: #f1f5f9;
            color: #475569;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #64748b;
        }

        .error {
            background: #fff0f0;
            color: #b42318;
            border: 1px solid #f2c2c2;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .print-button {
            background: #168044;
            margin-top: 20px;
        }

        .print-button:hover {
            background: #126b37;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #94a3b8;
            font-size: 12px;
        }

        @media (max-width: 600px) {

            .topbar {
                padding: 17px 20px;
            }

            .container {
                margin: 25px auto;
            }

            .page-heading h1 {
                font-size: 24px;
            }

            .card {
                padding: 17px;
            }

            .search-form input,
            .search-form button {
                width: 100%;
            }

        }

        @media print {

            body {
                background: white;
            }

            .no-print {
                display: none !important;
            }

            .container {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            .card, .summary {
                box-shadow: none;
                border: none;
            }

            table {
                min-width: 100%;
            }

            th, td {
                font-size: 9px;
                padding: 7px;
                white-space: normal;
            }

            th {
                background: #101d3a !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

        }

    </style>

</head>

<body>

    <header class="topbar no-print">

        <div class="brand">
            Grade<span>Track</span>
        </div>

        <small>Administrator Portal</small>

    </header>

    <main class="container">

        <section class="page-heading no-print">

            <h1>Grade Audit Logs</h1>

            <p>
                Monitor grading activities and review changes made by
                authorized users in the system.
            </p>

            <a href="dashboard_redirect.php" class="back">
                ← Back to Dashboard
            </a>

        </section>

        <section class="summary">

            <div class="summary-icon">📋</div>

            <div>

                <h2><?php echo (int)$totalLogs; ?></h2>

                <p>
                    <?php echo $search !== '' ? 'Matching audit records' : 'Audit records displayed'; ?>
                </p>

            </div>

        </section>

        <section class="card">

            <div class="card-heading">

                <h2>Audit History</h2>

                <p class="description">
                    Review the user responsible for each recorded action,
                    student details, previous marks, updated marks,
                    grades and date of activity.
                </p>

            </div>

            <form method="GET" action="audit_logs.php" class="search-form no-print">

                <input
                    type="text"
                    name="search"
                    placeholder="Search student ID, subject or username..."
                    value="<?php echo h($search); ?>"
                >

                <button type="submit">Search Logs</button>

                <a href="audit_logs.php" class="clear">Clear Search</a>

            </form>

            <?php if ($error !== ""): ?>

                <div class="error">
                    <?php echo h($error); ?>
                </div>

            <?php else: ?>

                <p class="count">
                    Total records displayed: <?php echo (int)$totalLogs; ?>
                </p>

                <div class="table-wrapper">

                    <table>

                        <thead>

                            <tr>
                                <th>Log ID</th>
                                <th>Grade ID</th>
                                <th>Performed By</th>
                                <th>Student ID</th>
                                <th>Subject</th>
                                <th>Action</th>
                                <th>Old Marks</th>
                                <th>New Marks</th>
                                <th>Old Grade</th>
                                <th>New Grade</th>
                                <th>Date & Time</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (empty($logs)): ?>

                                <tr>
                                    <td colspan="11" class="empty">
                                        No audit records found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($logs as $log): ?>

                                    <?php
                                    $actionClass = strtolower($log["action"]);

                                    if (!in_array($actionClass, ["insert", "update", "delete"], true)) {
                                        $actionClass = "other";
                                    }
                                    ?>

                                    <tr>

                                        <td><?php echo h($log["id"]); ?></td>

                                        <td><?php echo h($log["grade_id"] ?? "-"); ?></td>

                                        <td>
                                            <?php echo h($log["username"] ?? "Unknown"); ?>
                                        </td>

                                        <td><?php echo h($log["student_id"]); ?></td>

                                        <td><?php echo h($log["subject"]); ?></td>

                                        <td>
                                            <span class="action <?php echo h($actionClass); ?>">
                                                <?php echo h($log["action"]); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo $log["old_marks"] !== null
                                                ? h($log["old_marks"]) : "-"; ?>
                                        </td>

                                        <td>
                                            <?php echo $log["new_marks"] !== null
                                                ? h($log["new_marks"]) : "-"; ?>
                                        </td>

                                        <td><?php echo h($log["old_grade"] ?? "-"); ?></td>

                                        <td><?php echo h($log["new_grade"] ?? "-"); ?></td>

                                        <td><?php echo h($log["created_at"]); ?></td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

            <div class="no-print">

                <button type="button" class="print-button" onclick="window.print()">
                    Print Audit Logs
                </button>

            </div>

        </section>

    </main>

    <footer class="no-print">
        © <?php echo date('Y'); ?> GradeTrack. Digital Grading Management System.
    </footer>

</body>

</html>
