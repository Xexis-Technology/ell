<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/richtext.php';
$pdo = Database::pdo();
try { $body = $pdo->query('SELECT body FROM content WHERE slug = "terms" LIMIT 1')->fetch()['body'] ?? ''; $title = $pdo->query('SELECT title FROM content WHERE slug = "terms" LIMIT 1')->fetch()['title'] ?? 'Terms of Service'; } catch (Throwable) { $body = ''; $title = 'Terms of Service'; }
ob_start();
?>
<div class="max-w-3xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]"><?= e($title) ?></h1>
  <div class="card p-6 mt-6 text-sm space-y-2"><?= render_rich_text($body) ?: '<p>Terms are managed by the administrator.</p>' ?></div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Terms | Exotic Lane Limo';
$seo = content_seo($pdo, 'terms');
require APP_ROOT . '/views/layouts/public.php';
