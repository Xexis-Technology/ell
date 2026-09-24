<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
try { $body = $pdo->query('SELECT body FROM content WHERE slug = "faq" LIMIT 1')->fetch()['body'] ?? ''; } catch (Throwable) { $body = ''; }
ob_start();
?>
<div class="max-w-3xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Frequently Asked Questions</h1>
  <div class="card p-6 mt-6 text-sm space-y-3">
    <?= $body ?: '<p>FAQs are managed by the administrator.</p>' ?>
    <h2 class="font-display text-xl text-[#F3D4A6]">Booking essentials</h2>
    <p><strong>How many stops can I add?</strong> Up to 6 additional stops.</p>
    <p><strong>What is the hourly minimum?</strong> 2 hours.</p>
    <p><strong>How much free waiting do I get?</strong> Airport 60 min, bus/train/cruise terminals 30 min, point-to-point 15 min. Extra waiting is $15 per 10 minutes, invoiced afterwards.</p>
    <p><strong>Can I change pickup time?</strong> Yes, until 2 hours before pickup.</p>
    <p><strong>Do you need my flight number?</strong> No — airport service is address-based in Phase 1.</p>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'FAQ | Exotic Lane Limo';
$metaDesc = 'FAQs: stops, hourly minimum, waiting policy, pickup changes, airport service.';
require APP_ROOT . '/views/layouts/public.php';
