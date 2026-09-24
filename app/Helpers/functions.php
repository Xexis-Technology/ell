<?php
declare(strict_types=1);

/**
 * Central URL configuration (last.md §5 - mandatory).
 * SITE_URL = browser/public URL. APP_ROOT = server filesystem root.
 * All links, assets, form actions, redirects, AJAX URLs must use url().
 */
define('SITE_URL', rtrim((string)(getenv('SITE_URL') ?: ($_ENV['SITE_URL'] ?? 'http://localhost/ell')), '/'));
define('APP_ROOT', dirname(__DIR__, 2));

function url(string $path = ''): string
{
    return SITE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/** XSS-safe output escaping. */
function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Load .env (simple parser, no dependency) into $_ENV/getenv. */
function load_env(string $file): void
{
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        if (!array_key_exists($k, $_ENV)) {
            $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
    }
}

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

/** Render a view inside a layout. */
function render(string $view, array $data = [], string $layout = 'public'): void
{
    extract($data, EXTR_SKIP);
    $viewFile = APP_ROOT . '/views/' . ltrim($view, '/') . '.php';
    $layoutFile = APP_ROOT . '/views/layouts/' . $layout . '.php';
    if (!is_file($viewFile)) {
        http_response_code(500);
        echo 'View not found.';
        return;
    }
    ob_start();
    require $viewFile;
    $content = ob_get_clean();
    require $layoutFile;
}

/** POST-Redirect-GET helper. */
function redirect(string $path, int $code = 302): void
{
    header('Location: ' . url($path), true, $code);
    exit;
}

function flash(string $key, mixed $value = null): mixed
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (func_num_args() === 1) {
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
    $_SESSION['_flash'][$key] = $value;
    return null;
}

function old(string $key, mixed $default = ''): mixed
{
    $v = flash('old_' . $key);
    return $v ?? $default;
}

/** CSRF token generation/validation. */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals((string)($_SESSION['_csrf'] ?? ''), (string)$sent);
}

function require_csrf(): void
{
    if (!verify_csrf()) {
        http_response_code(419);
        echo 'Invalid security token. Please go back and try again.';
        exit;
    }
}

/** Secure random booking number: exactly 8 digits. Uniqueness enforced by caller + DB. */
function generate_booking_number(PDO $pdo): string
{
    for ($i = 0; $i < 20; $i++) {
        $n = (string)random_int(10000000, 99999999);
        $st = $pdo->prepare('SELECT 1 FROM bookings WHERE booking_number = ? LIMIT 1');
        $st->execute([$n]);
        if (!$st->fetch()) return $n;
    }
    throw new RuntimeException('Could not generate unique booking number.');
}

function invoice_number(PDO $pdo): string
{
    $prefix = 'INV-' . date('Y') . '-';
    for ($i = 0; $i < 20; $i++) {
        $n = $prefix . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $st = $pdo->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
        $st->execute([$n]);
        if (!$st->fetch()) return $n;
    }
    throw new RuntimeException('Could not generate unique invoice number.');
}

/** Password hashing: Argon2id where available, else bcrypt. */
function hash_password(string $plain): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }
    return password_hash($plain, PASSWORD_BCRYPT);
}

/** Settings helper (cached per request). */
function setting(PDO $pdo, string $key, mixed $default = null, bool $refresh = false): mixed
{
    static $cache = [];
    if ($refresh) $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ? LIMIT 1');
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $cache[$key] = $row ? $row['svalue'] : $default;
    return $cache[$key];
}

function audit(PDO $pdo, string $actorType, ?int $actorId, string $action, ?string $entityType = null, ?int $entityId = null, mixed $metadata = null): void
{
    try {
        $st = $pdo->prepare('INSERT INTO audit_logs (actor_type, actor_id, action, entity_type, entity_id, metadata, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([
            $actorType, $actorId, $action, $entityType, $entityId,
            $metadata !== null ? json_encode($metadata) : null,
            substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45),
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 255),
        ]);
    } catch (Throwable) {
        // audit must never break the main flow
    }
}

function log_error(string $msg, array $ctx = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($ctx) $line .= ' ' . json_encode($ctx);
    @file_put_contents(APP_ROOT . '/storage/logs/app.log', $line . PHP_EOL, FILE_APPEND);
}

function money(float|int|string $v): string
{
    return number_format((float)$v, 2, '.', ',');
}

/** Category-mapped stock photo for fleet vehicles. Returns [unsplash_id, alt]. */
function vehicle_photo(?string $category): array
{
    static $map = [
        'sedan' => ['photo-1555215695-3004980ad54e', 'Black luxury sedan'],
        'luxury sedan' => ['photo-1492144534655-ae79c964c9d7', 'Luxury sedan'],
        'suv' => ['photo-1568605117036-5fe5e7bab0b7', 'Premium SUV'],
        'luxury suv' => ['photo-1502877338535-766e1452684a', 'Luxury SUV at night'],
        'van' => ['photo-1464219789935-c2d9d9aba644', 'Passenger van on the road'],
        'limo' => ['photo-1519641471654-76ce0107ad1b', 'Limousine cabin'],
    ];
    $key = strtolower(trim((string)$category));
    return $map[$key] ?? ['photo-1503376780353-7e6692767b70', 'Luxury vehicle'];
}

function vehicle_photo_url(?string $category, int $w = 800): string
{
    [$id] = vehicle_photo($category);
    return 'https://images.unsplash.com/' . $id . '?auto=format&fit=crop&w=' . $w . '&q=60';
}
