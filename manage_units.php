
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "db.php";

// ADMIN ACCESS ONLY
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}

// Safe output function
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = '';

// Display flash message
if (isset($_SESSION['unit_message'])) {
    $message = $_SESSION['unit_message'];
    $message_type = $_SESSION['unit_message_type'] ?? 'success';

    unset($_SESSION['unit_message']);
    unset($_SESSION['unit_message_type']);
}

// HANDLE FORM SUBMISSIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $_SESSION['unit_message'] = "Invalid request. Please try again.";
        $_SESSION['unit_message_type'] = "error";

        header("Location: manage_units.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    // REGISTER NEW UNIT
    if ($action === 'add') {

        $unit_code = strtoupper(trim($_POST['unit_code'] ?? ''));
        $unit_name = trim($_POST['unit_name'] ?? '');

        if ($unit_code === '' || $unit_name === '') {
            $_SESSION['unit_message'] = "Please fill in all fields.";
            $_SESSION['unit_message_type'] = "error";

        } elseif (strlen($unit_code) > 30 || strlen($unit_name) > 150) {
            $_SESSION['unit_message'] = "The unit code or name is too long.";
            $_SESSION['unit_message_type'] = "error";

        } else {

            $check = $conn->prepare(
                "SELECT id FROM units WHERE unit_code = ?"
            );

            if (!$check) {
                $_SESSION['unit_message'] = "Unable to check the unit code.";
                $_SESSION['unit_message_type'] = "error";

            } else {
                $check->bind_param("s", $unit_code);
                $check->execute();
                $existing = $check->get_result();

                if ($existing->num_rows > 0) {

                    $_SESSION['unit_message'] =
                        "This unit code already exists.";

                    $_SESSION['unit_message_type'] = "error";

                } else {

                    $insert = $conn->prepare(
                        "INSERT INTO units (unit_code, unit_name, status)
                         VALUES (?, ?, 'active')"
                    );

                    if ($insert) {
                        $insert->bind_param(
                            "ss",
                            $unit_code,
                            $unit_name
                        );

                        if ($insert->execute()) {
                            $_SESSION['unit_message'] =
                                "Academic unit registered successfully!";

                            $_SESSION['unit_message_type'] = "success";
                        } else {
                            $_SESSION['unit_message'] =
                                "Unable to register the academic unit.";

                            $_SESSION['unit_message_type'] = "error";
                        }

                        $insert->close();

                    } else {
                        $_SESSION['unit_message'] =
                            "Unable to prepare the registration request.";

                        $_SESSION['unit_message_type'] = "error";
                    }
                }

                $check->close();
            }
        }

        header("Location: manage_units.php");
        exit;
    }

    // ACTIVATE OR DEACTIVATE UNIT
    if ($action === 'toggle') {

        $unit_id = filter_var(
            $_POST['unit_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$unit_id || $unit_id < 1) {

            $_SESSION['unit_message'] = "Invalid academic unit.";
            $_SESSION['unit_message_type'] = "error";

        } else {

            $check = $conn->prepare(
                "SELECT status FROM units WHERE id = ?"
            );

            if (!$check) {
                $_SESSION['unit_message'] =
                    "Unable to retrieve the academic unit.";

                $_SESSION['unit_message_type'] = "error";

            } else {

                $check->bind_param("i", $unit_id);
                $check->execute();

                $result = $check->get_result();
                $unit = $result->fetch_assoc();

                $check->close();

                if (!$unit) {

                    $_SESSION['unit_message'] =
                        "Academic unit not found.";

                    $_SESSION['unit_message_type'] = "error";

                } else {

                    $new_status = (
                        $unit['status'] === 'active'
                    ) ? 'inactive' : 'active';

                    $update = $conn->prepare(
                        "UPDATE units SET status = ? WHERE id = ?"
                    );

                    if ($update) {

                        $update->bind_param(
                            "si",
                            $new_status,
                            $unit_id
                        );

                        if ($update->execute()) {

                            $_SESSION['unit_message'] =
                                "Academic unit status changed to " .
                                $new_status . ".";

                            $_SESSION['unit_message_type'] = "success";

                        } else {

                            $_SESSION['unit_message'] =
                                "Unable to update the academic unit.";

                            $_SESSION['unit_message_type'] = "error";
                        }

                        $update->close();

                    } else {

                        $_SESSION['unit_message'] =
                            "Unable to prepare the status update.";

                        $_SESSION['unit_message_type'] = "error";
                    }
                }
            }
        }

        header("Location: manage_units.php");
        exit;
    }
}

// FETCH ALL ACADEMIC UNITS
$units = [];

$result = $conn->query(
    "SELECT id, unit_code, unit_name, status, created_at
     FROM units
     ORDER BY unit_code ASC"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $units[] = $row;
    }
}

$total_units = count($units);
$active_units = 0;
$inactive_units = 0;

foreach ($units as $unit) {
    if ($unit['status'] === 'active') {
        $active_units++;
    } else {
        $inactive_units++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>GradeTrack | Manage Academic Units</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        header {
            background: #172554;
            color: white;
            padding: 25px 35px;
        }

        .header-content {
            max-width: 1200px;
            margin: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        header h1 {
            margin: 0;
            font-size: 26px;
        }

        header p {
            color: #cbd5e1;
            margin-bottom: 0;
        }

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #2563eb;
            text-decoration: none;
            font-weight: bold;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .stats {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            border-left: 5px solid #2563eb;
        }

        .stat-card.green {
            border-left-color: #16a34a;
        }

        .stat-card.red {
            border-left-color: #dc2626;
        }

        .stat-card p {
            margin: 0;
            color: #64748b;
        }

        .stat-card h2 {
            margin: 10px 0 0;
            font-size: 30px;
            color: #172554;
        }

        .panel {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }

        .panel h2 {
            margin-top: 0;
            color: #172554;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 2fr auto;
            gap: 15px;
            align-items: end;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #2563eb;
        }

        button {
            border: none;
            cursor: pointer;
            border-radius: 7px;
            font-weight: bold;
            padding: 13px 18px;
            font-size: 14px;
        }

        .add-button {
            background: #2563eb;
            color: white;
        }

        .add-button:hover {
            background: #1d4ed8;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
            color: #172554;
        }

        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .active {
            background: #dcfce7;
            color: #166534;
        }

        .inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .activate-button {
            background: #dcfce7;
            color: #166534;
        }

        .deactivate-button {
            background: #fee2e2;
            color: #991b1b;
        }

        .message {
            padding: 15px;
            border-radius: 8px;
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

        .empty {
            text-align: center;
            padding: 30px;
            color: #64748b;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #64748b;
        }

        @media (max-width: 750px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            header {
                padding: 20px;
            }

            .container {
                padding: 0 12px;
            }

            .panel {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="header-content">

        <div>
            <h1>GradeTrack</h1>
            <p>Manage Academic Units</p>
        </div>

        <div>
            Welcome,
            <strong>
                <?php echo h($_SESSION['username'] ?? 'Administrator'); ?>
            </strong>
        </div>

    </div>
</header>

<div class="container">

    <a href="admin_dashboard.php" class="back-link">
        ← Back to Admin Dashboard
    </a>

    <?php if ($message !== ''): ?>
        <div class="message <?php echo h($message_type); ?>">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <div class="stats">

        <div class="stat-card">
            <p>Total Academic Units</p>
            <h2><?php echo $total_units; ?></h2>
        </div>

        <div class="stat-card green">
            <p>Active Units</p>
            <h2><?php echo $active_units; ?></h2>
        </div>

        <div class="stat-card red">
            <p>Inactive Units</p>
            <h2><?php echo $inactive_units; ?></h2>
        </div>

    </div>

    <div class="panel">

        <h2>Register New Academic Unit</h2>

        <p>
            Enter the unit code and unit name to register a new
            academic unit in the system.
        </p>

        <form method="POST" action="manage_units.php">

            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo h($_SESSION['csrf_token']); ?>">

            <input type="hidden"
                   name="action"
                   value="add">

            <div class="form-grid">

                <div>
                    <label for="unit_code">Unit Code</label>

                    <input
                        type="text"
                        id="unit_code"
                        name="unit_code"
                        maxlength="30"
                        placeholder="e.g. DSE 212"
                        required
                    >
                </div>

                <div>
                    <label for="unit_name">Unit Name</label>

                    <input
                        type="text"
                        id="unit_name"
                        name="unit_name"
                        maxlength="150"
                        placeholder="e.g. Cloud Computing"
                        required
                    >
                </div>

                <button type="submit" class="add-button">
                    Register Unit
                </button>

            </div>

        </form>

    </div>

    <div class="panel">

        <h2>Registered Academic Units</h2>

        <div class="table-container">

            <?php if (empty($units)): ?>

                <div class="empty">
                    No academic units have been registered yet.
                </div>

            <?php else: ?>

                <table>

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Unit Code</th>
                            <th>Unit Name</th>
                            <th>Status</th>
                            <th>Date Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($units as $index => $unit): ?>

                            <tr>

                                <td>
                                    <?php echo $index + 1; ?>
                                </td>

                                <td>
                                    <strong>
                                        <?php echo h($unit['unit_code']); ?>
                                    </strong>
                                </td>

                                <td>
                                    <?php echo h($unit['unit_name']); ?>
                                </td>

                                <td>
                                    <span class="status <?php echo h($unit['status']); ?>">
                                        <?php echo ucfirst(h($unit['status'])); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    $date = strtotime($unit['created_at']);

                                    echo $date
                                        ? h(date('d M Y', $date))
                                        : 'Unknown';
                                    ?>
                                </td>

                                <td>

                                    <form method="POST"
                                          action="manage_units.php"
                                          onsubmit="return confirm('Are you sure you want to change this unit status?');">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php echo h($_SESSION['csrf_token']); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="toggle"
                                        >

                                        <input
                                            type="hidden"
                                            name="unit_id"
                                            value="<?php echo (int)$unit['id']; ?>"
                                        >

                                        <?php if ($unit['status'] === 'active'): ?>

                                            <button
                                                type="submit"
                                                class="deactivate-button"
                                            >
                                                Deactivate
                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="submit"
                                                class="activate-button"
                                            >
                                                Activate
                                            </button>

                                        <?php endif; ?>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

    </div>

</div>

<footer>
    GradeTrack Digital Grading System
</footer>

</body>
</html>
