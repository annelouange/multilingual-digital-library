<?php

function require_role(array $user, array $allowed): void
{
    if (!in_array($user['role_code'], $allowed, true)) {
        ActivityLogService::log($user, 'unauthorized_access', 'warning');
        Response::error('You do not have permission to access this resource', 403);
    }
}
