<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Helpers/functions.php';

load_env(dirname(__DIR__) . '/.env');

$appCfg = require dirname(__DIR__) . '/config/app.php';
date_default_timezone_set($appCfg['timezone']);

// Secure session configuration
$authCfg = require dirname(__DIR__) . '/config/auth.php';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name($authCfg['session_name']);
session_set_cookie_params([
    'lifetime' => $authCfg['session_lifetime'],
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Safe production error handling
if (!$appCfg['debug']) {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $ex) {
        log_error('Unhandled: ' . $ex->getMessage());
        http_response_code(500);
        require APP_ROOT . '/views/errors/500.php';
        exit;
    });
}

// Security headers fallback
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Middleware/Auth.php';
require_once __DIR__ . '/Middleware/Security.php';
require_once __DIR__ . '/Services/NotificationService.php';
require_once __DIR__ . '/Services/PricingService.php';
require_once __DIR__ . '/Services/BookingService.php';
require_once __DIR__ . '/Services/PaymentService.php';
require_once __DIR__ . '/Services/DispatchService.php';
require_once __DIR__ . '/Services/EarningsService.php';
require_once __DIR__ . '/Services/WaitingService.php';
