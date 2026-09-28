<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$n = $_GET['n'] ?? '';
$st = $pdo->prepare('SELECT b.*, v.make, v.model FROM bookings b LEFT JOIN vehicles v ON v.id = b.vehicle_id WHERE b.booking_number = ? LIMIT 1');
$st->execute([$n]);
$b = $st->fetch();
if (!$b) {
    http_response_code(404);
    $GLOBALS['__site_url'] = SITE_URL;
    require APP_ROOT . '/views/errors/404.php';
    exit;
}
$inv = $pdo->prepare('SELECT id FROM invoices WHERE booking_id = ? LIMIT 1');
$inv->execute([$b['id']]);
$invRow = $inv->fetch();
ob_start();
?>
<div class="max-w-3xl mx-auto px-4 py-12 text-center">
  <span class="w-14 h-14 rounded-full bg-[#D9B978] text-[#0A0A0C] inline-flex items-center justify-center" aria-hidden="true">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
  </span>
  <p class="eyebrow mt-4">Booking confirmation</p>
  <p class="tabular text-4xl font-semibold text-[#F9F9F9] mt-2"><?= e($b['booking_number']) ?></p>
  <p class="mt-3 inline-flex flex-wrap justify-center gap-2 text-xs">
    <span class="border border-[#3a3a3d] rounded-full px-3 py-1 text-[#E5E5E3]"><?= e(ucwords(str_replace('_', ' ', $b['service_type']))) ?> · <?= e($b['trip_type'] === 'round_trip' ? 'Round-Trip' : 'One-Way') ?></span>
    <span class="border border-[#3a3a3d] rounded-full px-3 py-1 text-[#E5E5E3]"><?= e($b['status']) ?></span>
    <span class="border border-[#C8A96B] rounded-full px-3 py-1 text-[#F3D4A6]"><?= e($b['payment_status']) ?> · $<?= money($b['total']) ?></span>
  </p>
</div>
<div class="max-w-3xl mx-auto px-4 pb-12">
  <div class="card rounded-2xl p-6 grid sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
    <div><p class="label">Route</p><p><?= e($b['pickup_location']) ?> → <?= e($b['destination_location']) ?></p></div>
    <div><p class="label">Date &amp; time</p><p><?= e($b['pickup_date']) ?> at <?= e(substr($b['pickup_time'], 0, 5)) ?></p></div>
    <div><p class="label">Vehicle</p><p><?= e(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? '')) ?: 'To be assigned') ?></p></div>
    <div><p class="label">Invoice</p>
      <?php if ($invRow && !empty($b['customer_id'])): ?><p><a class="underline" href="<?= url('account/invoice.php?booking=' . $b['booking_number']) ?>">View invoice</a></p>
      <?php elseif ($invRow): ?><p class="text-xs">Available — <a class="underline" href="<?= url('auth/register.php') ?>">create an account</a> with your booking email to view it.</p>
      <?php else: ?><p class="text-xs text-[#AB8868]">Issued with payment.</p><?php endif; ?>
    </div>
  </div>
  <?php if ($b['status'] === 'awaiting_pricing'): ?>
    <div class="alert alert-ok mt-4" style="border:1px solid #2c6b3f"><strong class="tabular">Your booking ID-<?= e($b['booking_number']) ?></strong> is received and awaiting final pricing. Our team will verify mileage and email your secure payment link shortly.</div>
  <?php elseif ($b['payment_status'] !== 'paid'): ?>
    <div class="text-center mt-6"><a href="<?= url('services/payment.php?booking=' . $b['booking_number']) ?>" class="btn-gold rounded-full inline-block text-sm px-8 py-3">Proceed to payment</a></div>
  <?php endif; ?>
  <h2 class="font-display text-2xl text-[#F9F9F9] mt-10 text-center">Next steps</h2>
  <ol class="grid sm:grid-cols-3 gap-4 mt-4 text-sm">
    <li class="card rounded-2xl p-4"><p class="tabular text-xs text-[#D9B978]">01</p><p class="mt-1">We confirm your ride by email.</p></li>
    <li class="card rounded-2xl p-4"><p class="tabular text-xs text-[#D9B978]">02</p><p class="mt-1">Your chauffeur is dispatched before pickup.</p></li>
    <li class="card rounded-2xl p-4"><p class="tabular text-xs text-[#D9B978]">03</p><p class="mt-1">Pickup changes possible until 2 hours before.</p></li>
  </ol>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Confirmation | Exotic Lane Limo';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
