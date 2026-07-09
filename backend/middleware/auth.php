<?php

function current_user(bool $required = true): ?array
{
    $token = Request::bearerToken();
    if (!$token) {
        if ($required) {
            Response::error('Authentication token required', 401);
        }
        return null;
    }

    $hash = hash('sha256', $token);
    $stmt = Database::connection()->prepare(
        "SELECT u.id, u.full_name, u.email, u.status, r.code AS role_code, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.api_token_hash = :hash AND (u.token_expires_at IS NULL OR u.token_expires_at > NOW())
         LIMIT 1"
    );
    $stmt->execute([':hash' => $hash]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        if ($required) {
            Response::error('Invalid or expired token', 401);
        }
        return null;
    }

    if (class_exists('TenantService')) {
        $institution = TenantService::forUser($user);
        if ($institution) {
            $user['institution_id'] = (int)$institution['id'];
            $user['institution_name'] = $institution['name'];
            $user['institution_slug'] = $institution['slug'];
            $user['institution_role'] = $institution['institution_role'] ?? null;
        }
    }

    return $user;
}
