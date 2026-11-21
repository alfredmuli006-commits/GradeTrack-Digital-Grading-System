
<?php

function logActivity(
    $conn,
    $action,
    $entity_type,
    $entity_id = null,
    $description = null,
    $target_user_id = null,
    $old_values = null,
    $new_values = null
) {

    $actor_user_id = $_SESSION['user_id'] ?? null;

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $old_values = $old_values !== null
        ? json_encode($old_values)
        : null;

    $new_values = $new_values !== null
        ? json_encode($new_values)
        : null;

    $stmt = $conn->prepare("
        INSERT INTO system_audit_logs (
            actor_user_id,
            target_user_id,
            action,
            entity_type,
            entity_id,
            description,
            old_values,
            new_values,
            ip_address,
            user_agent
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log("Audit log preparation failed: " . $conn->error);
        return false;
    }

    $stmt->bind_param(
        "iississsss",
        $actor_user_id,
        $target_user_id,
        $action,
        $entity_type,
        $entity_id,
        $description,
        $old_values,
        $new_values,
        $ip_address,
        $user_agent
    );

    $success = $stmt->execute();

    if (!$success) {
        error_log("Audit log insertion failed: " . $stmt->error);
    }

    $stmt->close();

    return $success;
}
