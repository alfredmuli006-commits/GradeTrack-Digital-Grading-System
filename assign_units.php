
<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'audit_log.php';

// Only administrators can access this page.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = 'success';

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $message = 'Invalid request. Please refresh the page and try again.';
        $message_type = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        // ASSIGN UNIT
        if ($action === 'assign') {

            $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
            $unit_id = filter_input(INPUT_POST, 'unit_id', FILTER_VALIDATE_INT);

            if (!$teacher_id || !$unit_id) {
                $message = 'Please select a valid teacher and unit.';
                $message_type = 'error';

            } else {

                // Confirm teacher is active.
                $teacher_stmt = $conn->prepare("
                    SELECT id, username
                    FROM users
                    WHERE id = ? AND role = 'teacher' AND status = 'active'
                ");

                $teacher_stmt->bind_param("i", $teacher_id);
                $teacher_stmt->execute();
                $teacher_result = $teacher_stmt->get_result();
                $teacher = $teacher_result->fetch_assoc();
                $teacher_stmt->close();

                // Confirm unit is active.
                $unit_stmt = $conn->prepare("
                    SELECT id, unit_code, unit_name
                    FROM units
                    WHERE id = ? AND status = 'active'
                ");

                $unit_stmt->bind_param("i", $unit_id);
                $unit_stmt->execute();
                $unit_result = $unit_stmt->get_result();
                $unit = $unit_result->fetch_assoc();
                $unit_stmt->close();

                if (!$teacher || !$unit) {
                    $message = 'The selected teacher or unit is inactive or does not exist.';
                    $message_type = 'error';

                } else {

                    // Prevent duplicate active assignments.
                    $check_stmt = $conn->prepare("
                        SELECT id
                        FROM teacher_units
                        WHERE teacher_id = ?
                        AND unit_id = ?
                        AND status = 'active'
                    ");

                    $check_stmt->bind_param("ii", $teacher_id, $unit_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $already_assigned = $check_result->num_rows > 0;
                    $check_stmt->close();

                    if ($already_assigned) {
                        $message = 'This unit is already actively assigned to that teacher.';
                        $message_type = 'error';

                    } else {

                        $admin_id = $_SESSION['user_id'];

                        // Reactivate an old inactive assignment if one exists.
                        $old_stmt = $conn->prepare("
                            SELECT id
                            FROM teacher_units
                            WHERE teacher_id = ? AND unit_id = ?
                            LIMIT 1
                        ");

                        $old_stmt->bind_param("ii", $teacher_id, $unit_id);
                        $old_stmt->execute();
                        $old_result = $old_stmt->get_result();
                        $old_assignment = $old_result->fetch_assoc();
                        $old_stmt->close();

                        if ($old_assignment) {

                            $assignment_id = (int)$old_assignment['id'];

                            $update_stmt = $conn->prepare("
                                UPDATE teacher_units
                                SET status = 'active',
                                    assigned_by = ?,
                                    assigned_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");

                            $update_stmt->bind_param("ii", $admin_id, $assignment_id);
                            $saved = $update_stmt->execute();
                            $update_stmt->close();

                        } else {

                            $insert_stmt = $conn->prepare("
                                INSERT INTO teacher_units
                                (teacher_id, unit_id, assigned_by, status)
                                VALUES (?, ?, ?, 'active')
                            ");

                            $insert_stmt->bind_param(
                                "iii",
                                $teacher_id,
                                $unit_id,
                                $admin_id
                            );

                            $saved = $insert_stmt->execute();
                            $assignment_id = $insert_stmt->insert_id;
                            $insert_stmt->close();
                        }

                        if ($saved) {

                            logActivity(
                                $conn,
                                'UNIT_ASSIGNED',
                                'TEACHER_UNIT',
                                $assignment_id,
                                'Administrator assigned unit ' .
                                $unit['unit_code'] . ' to teacher ' .
                                $teacher['username'],
                                $teacher_id,
                                null,
                                [
                                    'teacher_id' => $teacher_id,
                                    'teacher_username' => $teacher['username'],
                                    'unit_id' => $unit_id,
                                    'unit_code' => $unit['unit_code'],
                                    'unit_name' => $unit['unit_name']
                                ]
                            );

                            $message = 'Unit successfully assigned to ' .
                                htmlspecialchars($teacher['username']) . '.';

                        } else {
                            $message = 'Unable to save the assignment. Please try again.';
                            $message_type = 'error';
                        }
                    }
                }
            }
        }

        // DEACTIVATE ASSIGNMENT
        elseif ($action === 'deactivate') {

            $assignment_id = filter_input(
                INPUT_POST,
                'assignment_id',
                FILTER_VALIDATE_INT
            );

            if (!$assignment_id) {
                $message = 'Invalid assignment selected.';
                $message_type = 'error';

            } else {

                $details_stmt = $conn->prepare("
                    SELECT tu.id, tu.teacher_id, tu.unit_id,
                           u.username, un.unit_code, un.unit_name
                    FROM teacher_units tu
                    JOIN users u ON tu.teacher_id = u.id
                    JOIN units un ON tu.unit_id = un.id
                    WHERE tu.id = ? AND tu.status = 'active'
                ");

                $details_stmt->bind_param("i", $assignment_id);
                $details_stmt->execute();
                $details_result = $details_stmt->get_result();
                $details = $details_result->fetch_assoc();
                $details_stmt->close();

                if (!$details) {
                    $message = 'Assignment not found or already inactive.';
                    $message_type = 'error';

                } else {

                    $update_stmt = $conn->prepare("
                        UPDATE teacher_units
                        SET status = 'inactive'
                        WHERE id = ?
                    ");

                    $update_stmt->bind_param("i", $assignment_id);
                    $saved = $update_stmt->execute();
                    $update_stmt->close();

                    if ($saved) {

                        logActivity(
                            $conn,
                            'UNIT_ASSIGNMENT_DEACTIVATED',
                            'TEACHER_UNIT',
                            $assignment_id,
                            'Administrator deactivated unit ' .
                            $details['unit_code'] . ' for teacher ' .
                            $details['username'],
                            (int)$details['teacher_id'],
                            [
                                'status' => 'active'
                            ],
                            [
                                'status' => 'inactive'
                            ]
                        );

                        $message = 'Assignment successfully deactivated.';

                    } else {
                        $message = 'Unable to deactivate the assignment.';
                        $message_type = 'error';
                    }
                }
            }
        }

        else {
            $message = 'Invalid action.';
            $message_type = 'error';
        }
    }
}

// Get active teachers.
$teachers = $conn->query("
    SELECT id, username, staff_id
    FROM users
    WHERE role = 'teacher' AND status = 'active'
    ORDER BY username ASC
");

// Get active units.
$units = $conn->query("
    SELECT id, unit_code, unit_name
    FROM units
    WHERE status = 'active'
    ORDER BY unit_code ASC
");

// Get all assignments.
$assignments = $conn->query("
    SELECT
        tu.id,
        tu.teacher_id,
        tu.unit_id,
        tu.status,
        tu.assigned_at,
        u.username,
        un.unit_code,
        un.unit_name,
        admin.username AS assigned_by_username
    FROM teacher_units tu
    JOIN users u ON tu.teacher_id = u.id
    JOIN units un ON tu.unit_id = un.id
    LEFT JOIN users admin ON tu.assigned_by = admin.id
    ORDER BY tu.assigned_at DESC
");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Units | GradeTrack</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            color: #263238;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .header {
            margin-bottom: 25px;
        }

        .header h1 {
            margin-bottom: 8px;
            color: #172554;
        }

        .header p {
            color: #64748b;
        }

        .panel {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            margin-bottom: 18px;
            background: white;
        }

        button {
            border: none;
            padding: 11px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        .assign-btn {
            background: #1d4ed8;
            color: white;
        }

        .assign-btn:hover {
            background: #1e40af;
        }

        .deactivate-btn {
            background: #dc2626;
            color: white;
            padding: 8px 12px;
        }

        .message {
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            text-align: left;
            padding: 13px;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f1f5f9;
            color: #334155;
        }

        .active {
            color: #15803d;
            font-weight: bold;
        }

        .inactive {
            color: #dc2626;
            font-weight: bold;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #1d4ed8;
            text-decoration: none;
            font-weight: bold;
        }

        .empty {
            color: #64748b;
            padding: 15px 0;
        }
    </style>
</head>

<body>

<div class="container">

    <a class="back-link" href="admin_dashboard.php">
        &larr; Back to Admin Dashboard
    </a>

    <div class="header">
        <h1>Teacher Unit Assignment</h1>
        <p>Assign academic units to teachers and manage existing assignments.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Assign a Unit</h2>

        <form method="POST" action="assign_units.php">

            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <input type="hidden" name="action" value="assign">

            <label for="teacher_id">Select Teacher</label>

            <select name="teacher_id" id="teacher_id" required>
                <option value="">-- Choose a teacher --</option>

                <?php while ($teacher = $teachers->fetch_assoc()): ?>
                    <option value="<?php echo (int)$teacher['id']; ?>">
                        <?php
                        echo htmlspecialchars($teacher['username']);

                        if (!empty($teacher['staff_id'])) {
                            echo ' (' . htmlspecialchars($teacher['staff_id']) . ')';
                        }
                        ?>
                    </option>
                <?php endwhile; ?>

            </select>

            <label for="unit_id">Select Unit</label>

            <select name="unit_id" id="unit_id" required>
                <option value="">-- Choose a unit --</option>

                <?php while ($unit = $units->fetch_assoc()): ?>
                    <option value="<?php echo (int)$unit['id']; ?>">
                        <?php
                        echo htmlspecialchars(
                            $unit['unit_code'] . ' - ' . $unit['unit_name']
                        );
                        ?>
                    </option>
                <?php endwhile; ?>

            </select>

            <button type="submit" class="assign-btn">
                Assign Unit
            </button>

        </form>
    </div>

    <div class="panel">
        <h2>Existing Unit Assignments</h2>

        <div class="table-wrapper">

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Teacher</th>
                        <th>Unit Code</th>
                        <th>Unit Name</th>
                        <th>Assigned By</th>
                        <th>Date Assigned</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($assignments && $assignments->num_rows > 0): ?>

                    <?php $count = 1; ?>

                    <?php while ($row = $assignments->fetch_assoc()): ?>

                        <tr>
                            <td><?php echo $count++; ?></td>

                            <td>
                                <?php echo htmlspecialchars($row['username']); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row['unit_code']); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row['unit_name']); ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $row['assigned_by_username'] ?? 'Unknown'
                                );
                                ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row['assigned_at']); ?>
                            </td>

                            <td>
                                <span class="<?php echo $row['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                    <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                                </span>
                            </td>

                            <td>

                                <?php if ($row['status'] === 'active'): ?>

                                    <form method="POST"
                                          action="assign_units.php"
                                          onsubmit="return confirm('Are you sure you want to deactivate this assignment?');">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="deactivate">

                                        <input type="hidden"
                                               name="assignment_id"
                                               value="<?php echo (int)$row['id']; ?>">

                                        <button type="submit" class="deactivate-btn">
                                            Deactivate
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span>Not active</span>

                                <?php endif; ?>

                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="empty">
                            No unit assignments found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

</div>

</body>
</html>
