
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
    exit("Access denied. Administrators only.");
}

$error = '';
$success = '';

// CSRF protection.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Add academic year.
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_academic_year'])) {

    if (!hash_equals(
        $_SESSION['csrf_token'],
        $_POST['csrf_token'] ?? ''
    )) {
        exit("Invalid security token. Refresh and try again.");
    }

    $year_name = trim($_POST['year_name'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';

    if ($year_name === '' || $start_date === '' || $end_date === '') {
        $error = "Please fill in all fields.";
    } elseif (!preg_match('/^\d{4}\/\d{4}$/', $year_name)) {
        $error = "Enter the academic year in this format: 2026/2027.";
    } elseif (strtotime($start_date) === false ||
              strtotime($end_date) === false ||
              $end_date <= $start_date) {
        $error = "Please enter valid dates. End date must be after start date.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO academic_years
             (year_name, start_date, end_date)
             VALUES (?, ?, ?)"
        );

        if (!$stmt) {
            $error = "Database error. Please try again.";
        } else {
            $stmt->bind_param(
                "sss",
                $year_name,
                $start_date,
                $end_date
            );

            if ($stmt->execute()) {
                $success = "Academic year added successfully.";

                if (function_exists('logActivity')) {
                    logActivity(
                        $conn,
                        $_SESSION['user_id'],
                        'ACADEMIC_YEAR_CREATED',
                        'academic_year',
                        $stmt->insert_id,
                        "Created academic year $year_name"
                    );
                }
            } else {
                $error = $stmt->errno === 1062
                    ? "This academic year already exists."
                    : "Could not add academic year.";
            }

            $stmt->close();
        }
    }
}

// Change academic year status.
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['change_status'])) {

    if (!hash_equals(
        $_SESSION['csrf_token'],
        $_POST['csrf_token'] ?? ''
    )) {
        exit("Invalid security token. Refresh and try again.");
    }

    $year_id = filter_var(
        $_POST['year_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    $new_status = $_POST['new_status'] ?? '';

    if (!$year_id || !in_array(
        $new_status,
        ['active', 'inactive', 'completed'],
        true
    )) {
        $error = "Invalid academic year selection.";
    } else {
        $stmt = $conn->prepare(
            "UPDATE academic_years SET status = ? WHERE id = ?"
        );

        $stmt->bind_param("si", $new_status, $year_id);

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $success = "Academic year status updated.";
        } else {
            $error = "Could not update academic year status.";
        }

        $stmt->close();
    }
}

// Retrieve academic years.
$years = $conn->query(
    "SELECT * FROM academic_years ORDER BY start_date DESC"
);

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Academic Years | GradeTrack</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        h2 {
            color: #1e293b;
        }

        .card {
            background: white;
            padding: 22px;
            margin-bottom: 22px;
            border-radius: 9px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        input, select {
            width: 100%;
            padding: 11px;
            margin: 8px 0 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }

        button, .back-link {
            background: #2563eb;
            color: white;
            border: none;
            padding: 11px 17px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
        }

        button:hover, .back-link:hover {
            background: #1d4ed8;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #f1f5f9;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
        }

        .status {
            font-weight: bold;
            text-transform: capitalize;
        }

        @media(max-width: 700px) {
            body {
                padding: 10px;
            }

            .card {
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h2>Academic Year Management</h2>
    <p>Create and manage academic years in GradeTrack.</p>

    <a href="dashboard_redirect.php" class="back-link">
        Back to Dashboard
    </a>

    <br><br>

    <?php if ($success): ?>
        <div class="success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Add New Academic Year</h3>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <label>Academic Year</label>
            <input
                type="text"
                name="year_name"
                placeholder="Example: 2026/2027"
                pattern="[0-9]{4}/[0-9]{4}"
                required
            >

            <label>Start Date</label>
            <input type="date" name="start_date" required>

            <label>End Date</label>
            <input type="date" name="end_date" required>

            <button type="submit" name="add_academic_year">
                Add Academic Year
            </button>

        </form>
    </div>

    <div class="card">
        <h3>Existing Academic Years</h3>

        <table>
            <thead>
                <tr>
                    <th>Academic Year</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Status</th>
                    <th>Manage Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if ($years && $years->num_rows > 0): ?>

                <?php while ($year = $years->fetch_assoc()): ?>

                    <tr>
                        <td><?= e($year['year_name']) ?></td>
                        <td><?= e($year['start_date']) ?></td>
                        <td><?= e($year['end_date']) ?></td>

                        <td class="status">
                            <?= e($year['status']) ?>
                        </td>

                        <td>
                            <form method="POST">

                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?= e($_SESSION['csrf_token']) ?>">

                                <input type="hidden"
                                       name="year_id"
                                       value="<?= (int)$year['id'] ?>">

                                <select name="new_status">
                                    <option value="active"
                                        <?= $year['status'] === 'active' ? 'selected' : '' ?>>
                                        Active
                                    </option>

                                    <option value="inactive"
                                        <?= $year['status'] === 'inactive' ? 'selected' : '' ?>>
                                        Inactive
                                    </option>

                                    <option value="completed"
                                        <?= $year['status'] === 'completed' ? 'selected' : '' ?>>
                                        Completed
                                    </option>
                                </select>

                                <button type="submit" name="change_status">
                                    Update
                                </button>

                            </form>
                        </td>
                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>
                    <td colspan="5">No academic years have been added yet.</td>
                </tr>

            <?php endif; ?>

            </tbody>
        </table>
    </div>

</div>

</body>
</html>
