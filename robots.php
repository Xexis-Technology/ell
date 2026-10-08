<?php
declare(strict_types=1);

/**
 * robots.txt, generated so it can never name the wrong domain.
 *
 * The static file in the project root hardcoded a production URL and told
 * crawlers to look for /sitemap.xml, which was itself a hand-maintained list
 * that had drifted. Both are now derived from SITE_URL and the database.
 */
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$base = SITE_URL;

// The operator's own text, stored in SEO setup. Fall back to a sane default
// so a fresh install still serves something usable.
$rules = '';
try {
    $pdo = Database::pdo();
    $rules = trim((string)setting($pdo, 'robots_rules', ''));
} catch (Throwable) {
    $rules = '';
}
if ($rules === '') {
    $rules = "User-agent: *\nAllow: /";
}

// Paths that must never be crawled or indexed. Repeated here on purpose:
// an operator editing the free-text rules above could otherwise remove them,
// and the admin, driver and account areas do not belong in an index.
$blocked = ['/admin/', '/driver/', '/account/', '/auth/', '/webhook/', '/storage/', '/app/', '/config/', '/database/', '/vendor/', '/tests/'];

echo rtrim(str_replace(["\r\n", "\r"], "\n", $rules), "\n");
echo "\n# Sections that must not be crawled\n";
foreach ($blocked as $path) {
    echo 'Disallow: ' . $path . "\n";
}
echo "\n# This install's own address, so crawlers are never sent elsewhere\n";
echo 'Sitemap: ' . $base . "/sitemap.php\n";