<?php
declare(strict_types=1);
return [
    'enabled' => filter_var($_ENV['MAPS_ENABLED'] ?? getenv('MAPS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'api_key' => $_ENV['GOOGLE_MAPS_API_KEY'] ?? getenv('GOOGLE_MAPS_API_KEY') ?: '',
];
