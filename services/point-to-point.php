<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
ob_start();
?>
<div class="max-w-4xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Point-to-Point Service</h1>
  <p class="mt-4 text-[#E5E5E3]">Direct chauffeured transfer from pickup to destination, with up to 6 optional stops. One-way or round-trip. Add Meet &amp; Greet, child or booster seats at booking.</p>
  <ul class="mt-4 text-sm text-[#E5E5E3] space-y-2"><li>✓ 15 minutes free waiting</li><li>✓ Transparent per-mile or hourly pricing</li><li>✓ Secure online payment &amp; invoice</li></ul>
  <a href="<?= url('services/booking.php?service=point_to_point') ?>" class="btn-cta inline-block mt-6">Book Point-to-Point</a>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Point-to-Point | Exotic Lane Limo';
$metaDesc = 'Direct chauffeured point-to-point transfers with up to 6 stops, one-way or round-trip.';
require APP_ROOT . '/views/layouts/public.php';
