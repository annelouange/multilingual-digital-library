<?php

return [
    'host' => getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1'),
    'port' => getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306'),
    'database' => getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'multilingual_digital_library_startup'),
    'username' => getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root'),
    'password' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($_ENV['DB_PASSWORD'] ?? ''),
    'charset' => getenv('DB_CHARSET') ?: ($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
];

