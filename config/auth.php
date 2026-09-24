<?php
declare(strict_types=1);
return [
    'session_name' => 'ELLSESSID',
    'session_lifetime' => 7200,
    'rate_limit_attempts' => 5,
    'rate_limit_window' => 900, // 15 min
    'reset_token_ttl' => 1800,  // 30 min
    'min_password_length' => 8,
];
