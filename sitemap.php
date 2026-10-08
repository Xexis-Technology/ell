<?php
declare(strict_types=1);

/**
 * Sitemap, generated from what is actually published.
 *
 * The hand-written sitemap.xml that sat in the project root named a
 * production domain this install is not on, listed two paths that do not
 * exist, and contained no blog posts at all. Every one of those is a reason
 * a search engine stops trusting the file, so it is built from the database
 * and the real routes instead.
 */
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/xml; charset=UTF-8');
// A sitemap is a listing, not a page: it must never be cached for long
// enough to be stale after a post is published.
header('Cache-Control: public, max-age=3600');

try {
    $pdo = Database::pdo();
} catch (Throwable $ex) {
    log_error('sitemap: database unavailable: ' . $ex->getMessage());
    http_response_code(503);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    exit;
}

$now = date('c');
$base = SITE_URL;

/**
 * One entry. $freq and $priority encode how often the page genuinely changes:
 * a fare page is not edited daily, and pretending otherwise is what makes a
 * sitemap get ignored.
 */
$urls = [];
$add = static function (string $loc, string $freq, string $priority, ?string $lastmod = null) use (&$urls, $base, $now): void {
    $urls[] = [
        'loc' => $base . '/' . ltrim($loc, '/'),
        'freq' => $freq,
        'priority' => $priority,
        'lastmod' => $lastmod,
    ];
};

$add('index.php', 'weekly', '1.0');
$add('services/index.php', 'monthly', '0.9');
$add('services/airport.php', 'monthly', '0.9');
$add('services/hourly.php', 'monthly', '0.9');
$add('services/point-to-point.php', 'monthly', '0.9');
$add('services/booking.php', 'weekly', '0.8');
$add('services/fleet.php', 'monthly', '0.8');
$add('services/group-event.php', 'monthly', '0.8');
$add('services/direct-contract.php', 'monthly', '0.7');
$add('services/blogs/index.php', 'weekly', '0.7');
$add('legal/faq.php', 'monthly', '0.6');
$add('legal/contact.php', 'monthly', '0.6');
$add('legal/cancellation.php', 'monthly', '0.5');
$add('legal/terms.php', 'yearly', '0.3');
$add('legal/privacy.php', 'yearly', '0.3');

// Published posts only. A draft in a sitemap is a promise the site cannot keep.
try {
    $posts = $pdo->query("SELECT slug, published_at, updated_at FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($posts as $p) {
        $lastmod = trim((string)$p['updated_at']) ?: trim((string)$p['published_at']);
        $add('services/blogs/read.php?slug=' . rawurlencode((string)$p['slug']), 'monthly', '0.6', $lastmod ?: null);
    }
} catch (Throwable $ex) {
    log_error('sitemap: could not read blog_posts: ' . $ex->getMessage());
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= e($u['loc']) ?></loc>
<?php if ($u['lastmod'] !== null && $u['lastmod'] !== ''): $ts = strtotime($u['lastmod']); ?>
    <lastmod><?= $ts !== false ? date('Y-m-d', $ts) : '' ?></lastmod>
<?php endif; ?>
    <changefreq><?= e($u['freq']) ?></changefreq>
    <priority><?= e($u['priority']) ?></priority>
  </url>
<?php endforeach; ?>
</urlset>