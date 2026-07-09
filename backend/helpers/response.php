<?php

class Response
{
    public static function json(bool $success, string $message, mixed $data = null, int $status = 200, mixed $error = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'error' => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null, string $message = 'Action completed successfully'): void
    {
        self::json(true, $message, $data);
    }

    public static function error(string $message, int $status = 400, mixed $error = null): void
    {
        self::json(false, $message, null, $status, $error);
    }
}
