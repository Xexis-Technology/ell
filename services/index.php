<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
ob_start();
?>
<div class="max-w-5xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Our Services</h1>
  <p class="text-[#AB8868] mt-2">Chauffeured transportation for every occasion.</p>
  <div class="grid md:grid-cols-2 gap-5 mt-8">
    <a href="<?= url('services/point-to-point.php') ?>" class="card p-6"><h2 class="font-display text-2xl text-[#F3D4A6]">Point-to-Point</h2><p class="text-sm mt-2">Pickup → optional stops → destination. Up to 6 stops.</p></a>
    <a href="<?= url('services/airport.php') ?>" class="card p-6"><h2 class="font-display text-2xl text-[#F3D4A6]">Airport Transportation</h2><p class="text-sm mt-2">Airport ↔ customer address. 60 min free waiting.</p></a>
    <a href="<?= url('services/hourly.php') ?>" class="card p-6"><h2 class="font-display text-2xl text-[#F3D4A6]">Hourly Charter</h2><p class="text-sm mt-2">2-hour minimum, additional hours priced per vehicle.</p></a>
    <a href="<?= url('services/group-event.php') ?>" class="card p-6"><h2 class="font-display text-2xl text-[#F3D4A6]">Group &amp; Event</h2><p class="text-sm mt-2">Multi-vehicle inquiries with admin quote.</p></a>
  </div>
  <a href="<?= url('services/booking.php') ?>" class="btn-cta inline-block mt-8">Book Now</a>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Services | Exotic Lane Limo';
$metaDesc = 'Point-to-point, airport and hourly chauffeured services with transparent pricing.';
require APP_ROOT . '/views/layouts/public.php';
