<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
ob_start();
?>
<div class="max-w-4xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Airport Transportation</h1>
  <p class="mt-4 text-[#E5E5E3]">Airport ↔ customer address, in both directions. No flight tracking and no required flight number in Phase 1 — just tell us where and when. Includes 60 minutes of free waiting.</p>
  <ul class="mt-4 text-sm text-[#E5E5E3] space-y-2"><li>✓ To-airport and from-airport</li><li>✓ 60 minutes free waiting</li><li>✓ Meet &amp; Greet available</li></ul>
  <a href="<?= url('services/booking.php?service=airport') ?>" class="btn-cta inline-block mt-6">Book Airport Transfer</a>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Airport Transportation | Exotic Lane Limo';
$metaDesc = 'Airport to address and address to airport chauffeured service with 60 minutes free waiting.';
require APP_ROOT . '/views/layouts/public.php';
