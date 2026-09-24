<?php
declare(strict_types=1);

/** Three isolated roles: customer -> /auth+/account, admin -> /admin, driver -> /driver */

function current_user(string $role): ?array
{
    return $_SESSION['user'][$role] ?? null;
}

function login_user(string $role, array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'][$role] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'status' => $user['status'] ?? 'active',
        'login_at' => time(),
    ];
}

function logout_user(string $role): void
{
    unset($_SESSION['user'][$role]);
    session_regenerate_id(true);
}

function require_role(string $role): array
{
    $u = current_user($role);
    if (!$u) {
        $login = $role === 'admin' ? 'admin/index.php' : ($role === 'driver' ? 'driver/index.php' : 'auth/login.php');
        redirect($login);
    }
    return $u;
}

function customer_owns_booking(PDO $pdo, int $customerId, int $bookingId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM bookings WHERE id = ? AND customer_id = ? LIMIT 1');
    $st->execute([$bookingId, $customerId]);
    return (bool)$st->fetch();
}

function driver_assigned_booking(PDO $pdo, int $driverId, int $bookingId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM dispatches WHERE booking_id = ? AND driver_id = ? AND status IN ("assigned","reassigned") LIMIT 1');
    $st->execute([$bookingId, $driverId]);
    return (bool)$st->fetch();
}

/** Simple login rate limiting per IP+role. */
function rate_limit_check(string $key): bool
{
    $cfg = require APP_ROOT . '/config/auth.php';
    $now = time();
    $_SESSION['_rl'][$key] = array_filter($_SESSION['_rl'][$key] ?? [], fn($t) => $t > $now - $cfg['rate_limit_window']);
    return count($_SESSION['_rl'][$key]) < $cfg['rate_limit_attempts'];
}

function rate_limit_hit(string $key): void
{
    $_SESSION['_rl'][$key][] = time();
}

function rate_limit_clear(string $key): void
{
    unset($_SESSION['_rl'][$key]);
}
