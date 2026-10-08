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

/** Returns an active class when the current script path ends with one of the given app-relative paths. */
function nav_active(string|array $paths, bool $underline = true): string
{
    $script = '/' . ltrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    foreach ((array)$paths as $p) {
        $p = '/' . ltrim((string)$p, '/');
        if ($script === $p || str_ends_with($script, $p)) {
            return $underline ? 'nav-active' : 'text-[#F3D4A6]';
        }
    }
    return '';
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

/** Status pill for admin tables. Never invents states — unknown values get neutral styling. */
function status_pill(string $status): string
{
    static $green = ['paid','active','verified','succeeded','booking_received','confirmed','finish','sent','published'];
    static $red = ['failed','inactive','suspended','rejected','cancelled','refunded','expired'];
    static $gold = ['pending','processing','requested','assigned','approved','quoted','new','pricing_finalized','awaiting_pricing','partially_refunded','partially_paid','unpaid','maintenance','draft'];
    static $blue = ['on_the_way','arrived','at_pickup_location','on_board'];
    if (in_array($status, $green, true)) $cls = 'pill-green';
    elseif (in_array($status, $red, true)) $cls = 'pill-red';
    elseif (in_array($status, $gold, true)) $cls = 'pill-gold';
    elseif (in_array($status, $blue, true)) $cls = 'pill-blue';
    else $cls = '';
    return '<span class="pill ' . $cls . '">' . e($status) . '</span>';
}

/**
 * Whole days from today until $date, or null when it was never recorded.
 * Used by the fleet docket for insurance / registration / inspection /
 * diamond-sticker / licence expiry, so a missing date reads as missing rather
 * than as a fake value.
 */
function days_until(mixed $date): ?int
{
    if (!is_string($date) || trim($date) === '') return null;
    $ts = strtotime(trim($date));
    if ($ts === false) return null;
    return (int)floor(($ts - strtotime('today')) / 86400);
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
    return unsplash_url($id, $w);
}

/**
 * One place that turns whatever is in a `cover` column into an image URL, so
 * the admin list, the editor preview and the public pages cannot drift apart.
 * Handles a stored upload under storage/uploads, a pasted full URL, or a bare
 * Unsplash photo id.
 */
function cover_url(?string $cover, int $w = 1200): string
{
    $c = trim((string)$cover);
    if ($c === '') return '';
    if (preg_match('#^storage/uploads/#i', $c)) return url($c);
    if (preg_match('#^https?://#i', $c)) return $c;
    return unsplash_url($c, $w);
}

function unsplash_url(string $photoId, int $w = 1200): string
{
    $id = trim($photoId);
    if ($id === '') return '';
    // auto=format lets the CDN answer with AVIF/WebP when the browser can take
    // it; q drops a little on thumbnails where banding is invisible.
    $q = $w <= 600 ? 55 : 62;
    return 'https://images.unsplash.com/' . rawurlencode($id) . '?auto=format&fit=crop&w=' . $w . '&q=' . $q;
}

/**
 * Resolve an image an admin typed or uploaded into a usable absolute URL.
 * Shared so the CMS preview and the rendered <head> cannot disagree.
 */
function media_url(?string $value): string
{
    $v = trim((string)$value);
    if ($v === '') return '';
    if (preg_match('#^https?://#i', $v)) return $v;
    if (preg_match('#^storage/#i', $v)) return url($v);
    if (preg_match('#^photo-[A-Za-z0-9-]+$#', $v)) return unsplash_url($v, 1200);
    return url($v);
}

/**
 * SEO overrides for a CMS content row, shaped for views/layouts/public.php.
 * A page that passes this gets whatever admin/cms.php saved; anything blank
 * there falls back to the site default inside the layout.
 */
function content_seo(PDO $pdo, string $slug): array
{
    try {
        $st = $pdo->prepare('SELECT meta_title, meta_description, meta_keywords, og_title, og_description,
                og_image, canonical_url, noindex FROM content WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $r = $st->fetch();
    } catch (Throwable) {
        $r = false;
    }
    if (!$r) return [];
    $pick = static fn(?string $v): ?string => trim((string)$v) !== '' ? trim((string)$v) : null;
    return array_filter([
        'title' => $pick($r['meta_title'] ?? ''),
        'description' => $pick($r['meta_description'] ?? ''),
        'keywords' => $pick($r['meta_keywords'] ?? ''),
        'og_title' => $pick($r['og_title'] ?? ''),
        'og_description' => $pick($r['og_description'] ?? ''),
        'og_image' => $pick($r['og_image'] ?? ''),
        'canonical' => $pick($r['canonical_url'] ?? ''),
        'noindex' => (int)($r['noindex'] ?? 0) === 1 ? true : null,
    ], static fn($v) => $v !== null);
}

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) $s = $t;
    $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 110) : 'vehicle';
}

/** Unique vehicle slug (optionally ignoring one id on update). */
function unique_vehicle_slug(PDO $pdo, string $base, ?int $excludeId = null): string
{
    $base = slugify($base) ?: 'vehicle';
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT 1 FROM vehicles WHERE slug = ?' . ($excludeId ? ' AND id != ' . (int)$excludeId : '') . ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([$slug]);
        if (!$st->fetch()) return $slug;
        $slug = $base . '-' . ($i++);
    }
}
