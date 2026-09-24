<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
ob_start();
?>
<div class="max-w-4xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Hourly Charter</h1>
  <p class="mt-4 text-[#E5E5E3]">Your chauffeur on standby — business, evenings out, weddings. 2-hour minimum; additional hours priced per vehicle.</p>
  <ul class="mt-4 text-sm text-[#E5E5E3] space-y-2"><li>✓ 2-hour minimum</li><li>✓ Additional hours selectable at booking</li><li>✓ One-way flexibility within booked time</li></ul>
  <a href="<?= url('services/booking.php?service=hourly') ?>" class="btn-cta inline-block mt-6">Book Hourly Charter</a>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Hourly Charter | Exotic Lane Limo';
$metaDesc = 'Hourly chauffeur charter with 2-hour minimum and per-vehicle additional-hour pricing.';
require APP_ROOT . '/views/layouts/public.php';
