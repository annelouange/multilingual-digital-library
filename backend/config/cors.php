<?php

$app = require __DIR__ . '/app.php';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = $app['allowed_origins'] ?? [];
$isProduction = ($app['env'] ?? 'local') === 'production';

if ($origin !== '') {
    header('Vary: Origin');
    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        if ($app['cors_allow_credentials'] ?? true) {
            header('Access-Control-Allow-Credentials: true');
        }
    } elseif ($isProduction && ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(403);
        exit;
    }
}

header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 600');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
