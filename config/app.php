<?php
declare(strict_types=1);
return [
    'site_url' => rtrim((string)(getenv('SITE_URL') ?: ($_ENV['SITE_URL'] ?? 'http://localhost/ell')), '/'),
    'app_root' => dirname(__DIR__),
    'env'      => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local',
    'debug'    => filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'timezone' => $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'America/New_York',
    'currency' => $_ENV['APP_CURRENCY'] ?? getenv('APP_CURRENCY') ?: 'USD',
];
