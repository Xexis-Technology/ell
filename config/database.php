<?php
declare(strict_types=1);
return [
    'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
    'name' => $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'ell_db',
    'user' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root',
    'pass' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
];
