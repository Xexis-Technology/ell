<?php
declare(strict_types=1);
return [
    'host' => $_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST') ?: '',
    'port' => (int)($_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?: 587),
    'username' => $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME') ?: '',
    'password' => $_ENV['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD') ?: '',
    'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?: '',
    'from_name' => $_ENV['MAIL_FROM_NAME'] ?? getenv('MAIL_FROM_NAME') ?: 'Exotic Lane Limo',
];
