<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists(\Dotenv\Dotenv::class) && is_file(__DIR__ . '/../.env')) {
        \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
    }
}

require __DIR__ . '/config/security.php';
require __DIR__ . '/config/cors.php';
require __DIR__ . '/helpers/response.php';
require __DIR__ . '/helpers/Database.php';
require __DIR__ . '/helpers/BookCover.php';
require __DIR__ . '/helpers/Request.php';
require __DIR__ . '/helpers/Validator.php';
require __DIR__ . '/services/ActivityLogService.php';
require __DIR__ . '/services/StorageService.php';
require __DIR__ . '/services/TenantService.php';
require __DIR__ . '/services/TranslationService.php';
require __DIR__ . '/middleware/auth.php';
require __DIR__ . '/middleware/role.php';

require __DIR__ . '/routes/api.php';
