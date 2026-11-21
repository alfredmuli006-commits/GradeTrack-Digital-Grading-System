
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "db.php";

// Only teachers can manage student grades.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    exit("Access denied. Only teachers can manage student grades.");
}

$teacher_id = (int)$_SESSION['user_id'];

// Generate CSRF token.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Retrieve only grades belonging to the teacher's active assigned units.
$sql = "
    SELECT
        g.id,
        g.student_id,
        s.student_name,
        g.subject,
        g.marks,
        g.grade
    FROM grades g
    LEFT JOIN students s
        ON g.student_id = s.student_id
    WHERE EXISTS (
        SELECT 1
        FROM teacher_units tu
        INNER JOIN units u
            ON tu.unit_id = u.id
        WHERE tu.teacher_id = ?
          AND tu.status = 'active'
          AND u.status = 'active'
          AND u.unit_name = g.subject
    )
    ORDER BY g.id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Grade retrieval preparation failed: " . $conn->error);
    exit("Could not retrieve grades. Please try again.");
}

$stmt->bind_param("i", $teacher_id);
$stmt->execute();

$result = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Grades | GradeTrack</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #17243a;
        }

        .topbar {
            background: #102a43;
            color: white;
            padding: 20px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .brand {
            font-size: 23px;
            font-weight: bold;
        }

        .brand span {
            color: #55a6ff;
        }

        .topbar small {
            color: #cbd5e1;
            font-size: 13px;
        }

        .container {
            width: 92%;
            max-width: 1250px;
            margin: 35px auto;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #64748b;
            line-height: 1.6;
        }

        .info-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
        }

        .info-card strong {
            color: #102a43;
            font-size: 17px;
        }

        .info-card p {
            color: #64748b;
            margin-top: 7px;
            line-height: 1.5;
        }

        .message {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .table-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
        }

        .table-heading {
            padding: 20px 22px;
            border-bottom: 1px solid #e2e8f0;
        }

        .table-heading h2 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .table-heading p {
            color: #64748b;
            font-size: 13px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th {
            background: #f1f5f9;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 15px 13px;
            text-align: left;
        }

        td {
            padding: 15px 13px;
            border-bottom: 1px solid #edf2f7;
            font-size: 14px;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .grade-badge {
            display: inline-block;
            background: #e0edff;
            color: #1d4ed8;
            padding: 6px 11px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
        }

        .marks {
            font-weight: bold;
            color: #102a43;
        }

        .action-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .edit-button,
        .delete-button {
            display: inline-block;
            padding: 8px 13px;
            border: none;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            cursor: pointer;
            font-weight: bold;
        }

        .edit-button {
            background: #2563eb;
        }

        .edit-button:hover {
            background: #1d4ed8;
        }

        .delete-button {
            background: #dc2626;
        }

        .delete-button:hover {
            background: #b91c1c;
        }

        .empty {
            text-align: center;
            padding: 35px;
            color: #64748b;
        }

        .footer-actions {
            margin-top: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .back-button {
            display: inline-block;
            background: #102a43;
            color: white;
            padding: 11px 18px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
        }

        .back-button:hover {
            background: #243f5a;
        }

        .footer-note {
            color: #64748b;
            font-size: 12px;
        }

        @media (max-width: 600px) {
            .container {
                width: 94%;
                margin: 25px auto;
            }

            .page-heading h1 {
                font-size: 23px;
            }

            .topbar {
                padding: 18px 5%;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">Grade<span>Track</span></div>
    <small>Teacher Portal | Grade Management</small>
</header>

<main class="container">

    <section class="page-heading">
        <h1>Manage Student Grades</h1>
        <p>View and manage grades for your assigned units only.</p>
    </section>

    <?php if (isset($_GET['updated'])): ?>
        <div class="message success">
            Grade updated successfully!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="message success">
            Grade deleted successfully and recorded in Audit Logs!
        </div>
    <?php endif; ?>

    <section class="info-card">
        <strong>Your Assigned Units</strong>
        <p>
            Only grades belonging to your active assigned units
            are displayed here. Other teachers' unit records
            are not available in this workspace.
        </p>
    </section>

    <section class="table-card">

        <div class="table-heading">
            <h2>Academic Records</h2>
            <p>Your latest grade records appear first.</p>
        </div>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Subject</th>
                        <th>Marks</th>
                        <th>Grade</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($result->num_rows > 0): ?>

                    <?php while ($row = $result->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <?= (int)$row['id'] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['student_id'] ?? '') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['subject']) ?>
                            </td>

                            <td class="marks">
                                <?= htmlspecialchars((string)$row['marks']) ?>/100
                            </td>

                            <td>
                                <span class="grade-badge">
                                    <?= htmlspecialchars($row['grade']) ?>
                                </span>
                            </td>

                            <td>

                                <div class="action-group">

                                    <a
                                        class="edit-button"
                                        href="edit_grade.php?id=<?= (int)$row['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="delete_grade.php"
                                        onsubmit="return confirm('Are you sure you want to delete this grade? This action cannot be undone.');"
                                    >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$row['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="delete-button"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="empty">
                            No grades are currently available for your assigned units.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <div class="footer-actions">

        <a href="teacher_dashboard.php" class="back-button">
            Back to Dashboard
        </a>

        <span class="footer-note">
            GradeTrack | Student Grading System
        </span>

    </div>

</main>

</body>
</html>

<?php
$stmt->close();
?>

</body>
</html>
