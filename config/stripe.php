<?php
declare(strict_types=1);
return [
    'publishable' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? getenv('STRIPE_PUBLISHABLE_KEY') ?: '',
    'secret' => $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: '',
    'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET') ?: '',
    'configured' => (bool)($_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY')),
];
