<?php

class ActivityLogService
{
    public static function log(?array $user, string $action, string $status = 'success', ?string $entityType = null, ?int $entityId = null, array $metadata = []): void
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO activity_logs (user_id, role_code, action, entity_type, entity_id, ip_address, user_agent, status, metadata)
                 VALUES (:user_id, :role_code, :action, :entity_type, :entity_id, :ip_address, :user_agent, :status, :metadata)'
            );
            $stmt->execute([
                ':user_id' => $user['id'] ?? null,
                ':role_code' => $user['role_code'] ?? null,
                ':action' => $action,
                ':entity_type' => $entityType,
                ':entity_id' => $entityId,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ':status' => $status,
                ':metadata' => $metadata ? json_encode($metadata) : null,
            ]);
        } catch (Throwable) {
            // Never break an API response because logging failed.
        }
    }
}
