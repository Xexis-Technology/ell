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
/*
      Speak the operator's language, not the database's. `at_pickup_location` and
      `awaiting_pricing` were going onto the office screen inside pills, because a
      pill that prettified its own argument would have had to know about vehicles
      and customers too.

      The wording is deliberately the office's, not the driver's: to a driver
      `awaiting_pricing` and `confirmed` are both simply "At the office" — nothing
      to do. To whoever is working the booking they are opposite ends of the desk's
      own work, so they say so. Road states are shared, since both sides name them
      the same way. Anything unmapped falls back to plain title case.
    */
    static $office = [
        'pending_payment' => 'Awaiting payment',
        'payment_failed' => 'Payment problem',
        'awaiting_pricing' => 'Awaiting pricing',
        'pricing_finalized' => 'Price sent',
        'booking_received' => 'New request',
        'confirmed' => 'Confirmed',
        'assigned' => 'Assigned',
        'on_the_way' => 'On the way',
        'arrived' => 'Arrived',
        'at_pickup_location' => 'At the pickup',
        'on_board' => 'On board',
        'finish' => 'Finished',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ];
    $label = $office[$status] ?? '';
    if ($label === '' && class_exists('DispatchLabels')) $label = DispatchLabels::step($status)['label'] ?? '';
    if ($label === '') $label = ucwords(str_replace('_', ' ', trim($status)));
    return '<span class="pill ' . $cls . '">' . e($label) . '</span>';
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

/* ---------------------------------------------------------------------------
   Locations, for the picker on the homepage and the booking form.

   The list used to be hardcoded twice - once in assets/js/booking.js and once
   in index.php - so the two copies could drift and neither could be edited from
   the admin. It lives in the `locations` table now, and these two functions are
   the only way to read it.
--------------------------------------------------------------------------- */

/** The transport types the picker can filter by, in the order the chips run. */
function location_kinds(): array
{
    return [
        'airport' => 'Airport',
        'rail'    => 'Rail',
        'bus'     => 'Bus',
        'cruise'  => 'Cruise',
        'place'   => 'Place',
    ];
}

/**
 * The fallback list, identical to the seed in database.sql.
 *
 * Kept so the picker still works on a checkout that has not run the schema, or
 * while the table is briefly unreachable. A booking form with an empty location
 * list is worse than one showing a slightly stale list.
 */
function location_fallback(): array
{
    return [
        ['label' => 'John F. Kennedy International Airport (JFK)', 'area' => 'Queens',     'kind' => 'airport'],
        ['label' => 'LaGuardia Airport (LGA)',                      'area' => 'Queens',     'kind' => 'airport'],
        ['label' => 'Newark Liberty International Airport (EWR)',   'area' => 'New Jersey', 'kind' => 'airport'],
        ['label' => 'Moynihan Train Hall, Penn Station',            'area' => 'Manhattan',  'kind' => 'rail'],
        ['label' => 'Grand Central Terminal',                       'area' => 'Manhattan',  'kind' => 'rail'],
        ['label' => 'Port Authority Bus Terminal',                  'area' => 'Manhattan',  'kind' => 'bus'],
        ['label' => 'Manhattan Cruise Terminal',                    'area' => 'Manhattan',  'kind' => 'cruise'],
        ['label' => 'Brooklyn Cruise Terminal',                     'area' => 'Brooklyn',   'kind' => 'cruise'],
        ['label' => 'Wall Street Piers',                            'area' => 'Manhattan',  'kind' => 'cruise'],
        ['label' => 'Times Square',                                 'area' => 'Manhattan',  'kind' => 'place'],
        ['label' => 'Central Park South',                           'area' => 'Manhattan',  'kind' => 'place'],
        ['label' => 'World Trade Center',                           'area' => 'Manhattan',  'kind' => 'place'],
        ['label' => 'Empire State Building',                        'area' => 'Manhattan',  'kind' => 'place'],
        ['label' => 'Madison Square Garden',                        'area' => 'Manhattan',  'kind' => 'place'],
        ['label' => 'Barclays Center',                              'area' => 'Brooklyn',   'kind' => 'place'],
        ['label' => 'Yankee Stadium',                               'area' => 'Bronx',      'kind' => 'place'],
        ['label' => 'Citi Field',                                   'area' => 'Queens',     'kind' => 'place'],
        ['label' => 'MetLife Stadium',                              'area' => 'New Jersey', 'kind' => 'place'],
    ];
}

/** Active locations in the order the office put them. */
function locations(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT label, area, kind FROM locations WHERE is_active = 1
                             ORDER BY sort_order, id')->fetchAll();
    } catch (Throwable) {
        return location_fallback();
    }
    if ($rows === []) return location_fallback();
    return array_map(static fn(array $r): array => [
        'label' => (string)$r['label'],
        'area'  => (string)$r['area'],
        'kind'  => (string)$r['kind'],
    ], $rows);
}

/** Every location including hidden ones - the editor needs to see them all. */
function locations_admin(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT id, label, area, kind, sort_order, is_active FROM locations
                             ORDER BY sort_order, id')->fetchAll();
    } catch (Throwable) {
        return [];
    }
    return array_map(static fn(array $r): array => [
        'id'    => (int)$r['id'],
        'label' => (string)$r['label'],
        'area'  => (string)$r['area'],
        'kind'  => (string)$r['kind'],
        'on'    => (int)$r['is_active'] === 1,
    ], $rows);
}

/**
 * Locations as the JSON payload the picker reads.
 *
 * The keys stay v/a/t because that is the shape the JavaScript already uses;
 * renaming them would mean touching behaviour that works.
 *
 *   v  the label the rider sees and what gets booked
 *   a  the display line beside it, "Area · Kind" for anything typed and just
 *      "Area" for a plain landmark
 *   t  the kind, verbatim: airport, rail, bus, cruise or place
 *
 * `t` used to be collapsed to a coarser airport/road pair, because the picker
 * only had two chips and a rail terminal would vanish the moment "road" was
 * switched on. The chips are built from this data now and offer all five, so
 * the value is passed through untouched and the rider can actually filter to
 * the same categories the office sees in Settings.
 *
 * The HEX flags matter. An admin can type a location called
 * </script><script>alert(1)</script>, and without them the JSON closes the
 * script tag and the rest of the line becomes markup. These escape <, >, &, '
 * and " as &-forms, which JSON.parse decodes straight back to the original text.
 */
function locations_json(PDO $pdo): string
{
    $kinds = location_kinds();
    $out = array_map(static function (array $l) use ($kinds): array {
        $typed = $l['kind'] !== 'place' && isset($kinds[$l['kind']]);
        $line = $l['area'];
        if ($typed) $line = ($line !== '' ? $line . ' · ' : '') . $kinds[$l['kind']];
        return [
            'v' => $l['label'],
            'a' => $line,
            't' => $l['kind'],
        ];
    }, locations($pdo));
    return (string)json_encode(
        $out,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

/* ---------------------------------------------------------------------------
   Sidebar counts, for views/layouts/admin.php.

   Each number is "something here needs a person", not "how many rows this
   table has". A badge showing the total customer count tells an admin nothing
   they can act on and trains them to ignore the badges; a badge showing 6
   bookings waiting to be priced tells them where to go.

   The definitions are the same ones admin/dashboard.php already uses, so a
   badge and the dashboard can never disagree about what "needs attention"
   means - awaiting pricing, pending drivers, requested payouts.

   All of it is one query. Nine separate COUNT(*) round trips on every admin
   page load is a noticeable tax on a page that is otherwise a single fetch.
--------------------------------------------------------------------------- */

/**
 * @return array<string,int> admin page filename => count, only where > 0
 */
function admin_nav_counts(PDO $pdo): array
{
    $out = [];
    foreach (admin_nav_state($pdo) as $page => $s) {
        if (($s['n'] ?? 0) > 0) $out[$page] = (int)$s['n'];
    }
    return $out;
}

/**
 * Everything the sidebar needs in one read: how many things need attention per
 * page, when the newest of them arrived, and when this admin last opened that
 * page.
 *
 * @return array<string,array{n:int,newest:?string,seen:?string}>
 */
function admin_nav_state(PDO $pdo, ?int $adminId = null): array
{
    // 'customers' is dated rather than stateful: it counts signups in the last
    // week, matching the dashboard's own 7-day window, and goes to zero on its
    // own instead of sticking at the lifetime total.
    //
    // Each section reports two things: how many rows would count, and when the
    // newest of those rows arrived. "New" is then a comparison against the
    // admin's last visit rather than mere existence - otherwise a badge for two
    // inquiries you opened the page for would follow you around the console all
    // day.
    $sql = 'SELECT
        (SELECT COUNT(*) FROM bookings WHERE status = "awaiting_pricing") AS bookings_n,
        (SELECT MAX(created_at) FROM bookings WHERE status = "awaiting_pricing") AS bookings_t,
        (SELECT COUNT(*) FROM bookings b WHERE b.status = "confirmed"
            AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.booking_id = b.id AND d.driver_id IS NOT NULL)) AS dispatch_n,
        (SELECT MAX(b.created_at) FROM bookings b WHERE b.status = "confirmed"
            AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.booking_id = b.id AND d.driver_id IS NOT NULL)) AS dispatch_t,
        (SELECT COUNT(*) FROM drivers WHERE status = "pending") AS drivers_n,
        (SELECT MAX(created_at) FROM drivers WHERE status = "pending") AS drivers_t,
        (SELECT COUNT(*) FROM payments WHERE status = "failed") AS payments_n,
        (SELECT MAX(created_at) FROM payments WHERE status = "failed") AS payments_t,
        (SELECT COUNT(*) FROM driver_payouts WHERE status = "requested") AS payouts_n,
        (SELECT MAX(requested_at) FROM driver_payouts WHERE status = "requested") AS payouts_t,
        (SELECT COUNT(*) FROM group_event_inquiries WHERE status = "new") AS group_events_n,
        (SELECT MAX(created_at) FROM group_event_inquiries WHERE status = "new") AS group_events_t,
        (SELECT COUNT(*) FROM notifications WHERE status = "failed") AS notifications_n,
        (SELECT MAX(created_at) FROM notifications WHERE status = "failed") AS notifications_t,
        (SELECT COUNT(*) FROM blog_posts WHERE status <> "published") AS blog_n,
        (SELECT MAX(created_at) FROM blog_posts WHERE status <> "published") AS blog_t,
        (SELECT COUNT(*) FROM customers WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS customers_n,
        (SELECT MAX(created_at) FROM customers WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS customers_t';

    try {
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        // A table that has not been created yet must not take the whole admin
        // area down with it. No badge is a fine outcome.
        return [];
    }
    if (!is_array($row)) return [];

    $map = [
        'bookings.php'      => 'bookings',
        'dispatch.php'      => 'dispatch',
        'drivers.php'       => 'drivers',
        'payments.php'      => 'payments',
        'payouts.php'       => 'payouts',
        'group-events.php'  => 'group_events',
        'notifications.php' => 'notifications',
        'blog.php'          => 'blog',
        'customers.php'     => 'customers',
    ];

    $out = [];
    foreach ($map as $page => $key) {
        $n = (int)($row[$key . '_n'] ?? 0);
        $newest = $row[$key . '_t'] ?? null;
        $out[$page] = [
            'n' => $n,
            'newest' => ($newest === null || $newest === '') ? null : (string)$newest,
            'seen' => null,
        ];
    }

    // One more read for this admin's visit times. Null admin = not signed in as
    // someone (a shared layout edge case), so everything stays unseen.
    if ($adminId !== null && $adminId > 0) {
        try {
            $st = $pdo->prepare('SELECT section, seen_at FROM admin_nav_seen WHERE admin_id = ?');
            $st->execute([$adminId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sec = (string)$r['section'];
                if (isset($out[$sec])) $out[$sec]['seen'] = (string)$r['seen_at'];
            }
        } catch (Throwable) {
            // No table, no history: everything counts as unseen, which is the
            // safe direction to fail in.
        }
    }
    return $out;
}

/**
 * Stamp "this admin has now looked at that section".
 *
 * Called when the page is rendered, so opening Groups clears the Groups badge
 * immediately - the inquiries are on screen, there is nothing left to go and
 * look at. A later inquiry arrives with a newer created_at and the badge
 * returns on its own.
 */
function admin_nav_mark_seen(PDO $pdo, ?int $adminId, string $section): void
{
    if ($adminId === null || $adminId <= 0 || $section === '') return;
    try {
        $st = $pdo->prepare('INSERT INTO admin_nav_seen (admin_id, section, seen_at) VALUES (?,?,NOW())
                             ON DUPLICATE KEY UPDATE seen_at = NOW()');
        $st->execute([$adminId, $section]);
    } catch (Throwable) {
        // Losing a bookmark is not worth failing a page over.
    }
}

/**
 * Should this section show a badge to this admin right now?
 *
 * Three things have to hold: there is something to look at, this is not the
 * page already on screen, and it arrived after their last visit.
 */
function admin_nav_shows(array $s, bool $isCurrentPage): bool
{
    $n = (int)($s['n'] ?? 0);
    if ($n <= 0) return false;
    if ($isCurrentPage) return false;
    $seen = $s['seen'] ?? null;
    if ($seen === null || $seen === '') return true;      // never looked
    $newest = $s['newest'] ?? null;
    if ($newest === null) return true;                    // counted, but undated
    // String comparison is safe here: both sides are DATETIME literals.
    return (string)$newest > (string)$seen;
}
/**
 * How a count is written in a badge: "7", or "99+" once there are more than 99.
 *
 * Capping is the point - a badge three digits wide shoves the label out of the
 * sidebar and stops being glanceable, which is the only thing it is for.
 */
function admin_badge(int $n): string
{
    return $n > 99 ? '99+' : (string)$n;
}
