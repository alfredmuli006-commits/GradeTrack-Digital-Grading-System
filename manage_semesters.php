
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "db.php";
require_once "audit_log.php";

// Admin access only.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit("Access denied. Admins only.");
}

// CSRF protection.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = "";
$error = "";

// Add a semester.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid security token. Refresh the page and try again.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $academic_year_id = (int)($_POST['academic_year_id'] ?? 0);
            $semester_name = trim($_POST['semester_name'] ?? '');
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';

            if (
                $academic_year_id <= 0 ||
                $semester_name === '' ||
                $start_date === '' ||
                $end_date === ''
            ) {
                $error = "Please fill in all fields.";
            } elseif ($start_date >= $end_date) {
                $error = "The end date must be after the start date.";
            } else {
                $yearCheck = $conn->prepare(
                    "SELECT start_date, end_date
                     FROM academic_years
                     WHERE id = ?"
                );
                $yearCheck->bind_param("i", $academic_year_id);
                $yearCheck->execute();
                $year = $yearCheck->get_result()->fetch_assoc();
                $yearCheck->close();

                if (!$year) {
                    $error = "Please select a valid academic year.";
                } elseif (
                    $start_date < $year['start_date'] ||
                    $end_date > $year['end_date']
                ) {
                    $error = "Semester dates must fall within the selected academic year.";
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO semesters
                         (academic_year_id, semester_name, start_date, end_date)
                         VALUES (?, ?, ?, ?)"
                    );

                    $stmt->bind_param(
                        "isss",
                        $academic_year_id,
                        $semester_name,
                        $start_date,
                        $end_date
                    );

                    if ($stmt->execute()) {
                        $newId = $conn->insert_id;

                        if (function_exists('logActivity')) {
                            logActivity(
                                $conn,
                                $_SESSION['user_id'],
                                'SEMESTER_CREATED',
                                'semester',
                                $newId,
                                "Created semester: " . $semester_name
                            );
                        }

                        $message = "Semester added successfully.";
                    } else {
                        $error = ($stmt->errno === 1062)
                            ? "This semester already exists for that academic year."
                            : "Could not add semester. Please check the details.";
                    }

                    $stmt->close();
                }
            }
        }

        // Change semester status.
        if ($action === 'status') {
            $semester_id = (int)($_POST['semester_id'] ?? 0);
            $new_status = $_POST['status'] ?? '';

            if ($semester_id <= 0 || !in_array(
                $new_status,
                ['active', 'inactive', 'completed'],
                true
            )) {
                $error = "Invalid semester selection.";
            } else {
                $conn->begin_transaction();

                try {
                    $check = $conn->prepare(
                        "SELECT id FROM semesters WHERE id = ? FOR UPDATE"
                    );
                    $check->bind_param("i", $semester_id);
                    $check->execute();
                    $exists = $check->get_result()->fetch_assoc();
                    $check->close();

                    if (!$exists) {
                        throw new Exception("Semester not found.");
                    }

                    if ($new_status === 'active') {
                        // Only one active semester at a time.
                        $deactivate = $conn->prepare(
                            "UPDATE semesters
                             SET status = 'inactive'
                             WHERE status = 'active' AND id != ?"
                        );
                        $deactivate->bind_param("i", $semester_id);
                        $deactivate->execute();
                        $deactivate->close();
                    }

                    $update = $conn->prepare(
                        "UPDATE semesters SET status = ? WHERE id = ?"
                    );
                    $update->bind_param("si", $new_status, $semester_id);
                    $update->execute();
                    $update->close();

                    $conn->commit();
                    $message = "Semester status updated successfully.";

                    if (function_exists('logActivity')) {
                        logActivity(
                            $conn,
                            $_SESSION['user_id'],
                            'SEMESTER_STATUS_UPDATED',
                            'semester',
                            $semester_id,
                            "Changed semester status to " . $new_status
                        );
                    }
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = "Could not update semester status: " . $e->getMessage();
                }
            }
        }
    }
}

// Get academic years for the form.
$yearsResult = $conn->query(
    "SELECT id, year_name, start_date, end_date
     FROM academic_years
     ORDER BY start_date DESC"
);

// Get all semesters.
$semestersResult = $conn->query(
    "SELECT s.*, a.year_name
     FROM semesters s
     INNER JOIN academic_years a ON s.academic_year_id = a.id
     ORDER BY a.start_date DESC, s.start_date ASC"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Semesters - GradeTrack</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #222;
        }

        .container {
            max-width: 1050px;
            margin: auto;
        }

        .card {
            background: white;
            padding: 22px;
            margin-bottom: 22px;
            border-radius: 9px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        }

        h2 {
            margin-top: 0;
            color: #183153;
        }

        label {
            display: block;
            margin-top: 13px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            margin-top: 6px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button {
            background: #183153;
            color: white;
            border: none;
            padding: 11px 18px;
            margin-top: 16px;
            border-radius: 5px;
            cursor: pointer;
        }

        button:hover {
            background: #254b78;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #183153;
            color: white;
        }

        .back {
            display: inline-block;
            margin-bottom: 18px;
            color: #183153;
            text-decoration: none;
            font-weight: bold;
        }

        .status-form {
            margin: 0;
        }

        .status-form select {
            min-width: 120px;
        }
    </style>
</head>

<body>
<div class="container">

    <a class="back" href="dashboard_redirect.php">← Back to Dashboard</a>

    <div class="card">
        <h2>Manage Semesters</h2>
        <p>Create semesters and link them to academic years.</p>

        <?php if ($message): ?>
            <p class="success"><?= e($message) ?></p>
        <?php endif; ?>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add">

            <label>Academic Year</label>
            <select name="academic_year_id" required>
                <option value="">Select Academic Year</option>
                <?php while ($year = $yearsResult->fetch_assoc()): ?>
                    <option value="<?= (int)$year['id'] ?>">
                        <?= e($year['year_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <label>Semester Name</label>
            <input type="text" name="semester_name"
                   placeholder="e.g. Semester 1" maxlength="50" required>

            <label>Start Date</label>
            <input type="date" name="start_date" required>

            <label>End Date</label>
            <input type="date" name="end_date" required>

            <button type="submit">Add Semester</button>
        </form>
    </div>

    <div class="card">
        <h2>Existing Semesters</h2>

        <table>
            <thead>
                <tr>
                    <th>Academic Year</th>
                    <th>Semester</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Status</th>
                    <th>Update Status</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($semestersResult->num_rows > 0): ?>
                <?php while ($semester = $semestersResult->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($semester['year_name']) ?></td>
                        <td><?= e($semester['semester_name']) ?></td>
                        <td><?= e($semester['start_date']) ?></td>
                        <td><?= e($semester['end_date']) ?></td>
                        <td><?= e(ucfirst($semester['status'])) ?></td>
                        <td>
                            <form method="POST" class="status-form">
                                <input type="hidden" name="csrf_token"
                                    value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="semester_id"
                                    value="<?= (int)$semester['id'] ?>">

                                <select name="status" required>
                                    <option value="inactive"
                                        <?= $semester['status'] === 'inactive' ? 'selected' : '' ?>>
                                        Inactive
                                    </option>
                                    <option value="active"
                                        <?= $semester['status'] === 'active' ? 'selected' : '' ?>>
                                        Active
                                    </option>
                                    <option value="completed"
                                        <?= $semester['status'] === 'completed' ? 'selected' : '' ?>>
                                        Completed
                                    </option>
                                </select>

                                <button type="submit">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6">No semesters have been created yet.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
