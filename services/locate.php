<?php
/**
 * Reverse geocode a pair of coordinates into a one-line address.
 *
 * The browser's Geolocation API hands back latitude and longitude and nothing
 * else, so "where am I" cannot be answered on the client without a third party
 * seeing the coordinates first. They come here instead, and the lookup happens
 * here so Nominatim gets the identifying User-Agent it asks for - a browser is
 * not permitted to set one.
 *
 * CSRF-protected because it is a POST that spends a request against a shared,
 * rate-limited public service: anybody who can make this endpoint fire on demand
 * can exhaust the quota for every other visitor.
 *
 * Answer shape is always {ok: bool, ...}; never a bare string.
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$reply = static function (array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    $reply(['ok' => false, 'error' => 'Use POST.'], 405);
}
require_csrf();

$rawLat = trim((string)($_POST['lat'] ?? ''));
$rawLng = trim((string)($_POST['lng'] ?? ''));
if ($rawLat === '' || $rawLng === '') {
    $reply(['ok' => false, 'error' => 'No coordinates were sent.'], 400);
}

// is_numeric and range checks together: reject "12abc", 1e400 and 91.5 alike.
$lat = is_numeric($rawLat) ? (float)$rawLat : null;
$lng = is_numeric($rawLng) ? (float)$rawLng : null;
if ($lat === null || $lng === null) {
    $reply(['ok' => false, 'error' => 'Those coordinates could not be read.'], 400);
}
if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
    $reply(['ok' => false, 'error' => 'Those coordinates are not on the globe.'], 400);
}

$pdo = Database::pdo();
$result = reverse_geocode($pdo, $lat, $lng);

if (!$result['ok']) {
    $reply(['ok' => false, 'error' => (string)($result['error'] ?? 'The address lookup failed.')], 502);
}

// Nothing here is personal data worth keeping, but an audit line means the
// support answer to "did you log my location?" is a fact rather than a guess.
audit($pdo, 'guest', null, 'location.reverse_geocoded', null, null, ['cached' => (bool)($result['cached'] ?? false)]);

$reply(['ok' => true, 'address' => (string)$result['address']]);