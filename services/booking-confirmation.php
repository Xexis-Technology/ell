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
<div class="max-w-3xl mx-auto px-4 py-12">
  <p class="badge">Booking confirmation</p>
  <h1 class="font-display text-4xl text-[#F9F9F9] mt-2">Booking <?= e($b['booking_number']) ?></h1>
  <div class="card p-6 mt-6 space-y-2 text-sm">
    <p><strong>Service:</strong> <?= e(ucwords(str_replace('_', ' ', $b['service_type']))) ?> · <?= e($b['trip_type'] === 'round_trip' ? 'Round-Trip' : 'One-Way') ?></p>
    <p><strong>Date/Time:</strong> <?= e($b['pickup_date']) ?> at <?= e(substr($b['pickup_time'], 0, 5)) ?></p>
    <p><strong>Route:</strong> <?= e($b['pickup_location']) ?> → <?= e($b['destination_location']) ?></p>
    <p><strong>Vehicle:</strong> <?= e(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? '')) ?: 'To be assigned') ?></p>
    <p><strong>Status:</strong> <?= e($b['status']) ?> · <strong>Payment:</strong> <?= e($b['payment_status']) ?></p>
    <p><strong>Total:</strong> $<?= money($b['total']) ?></p>
    <?php if ($invRow && !empty($b['customer_id'])): ?><p><a class="underline" href="<?= url('account/invoice.php?booking=' . $b['booking_number']) ?>">View invoice</a></p><?php elseif ($invRow): ?><p class="text-xs">Invoice available — <a class="underline" href="<?= url('auth/register.php') ?>">create an account</a> with your booking email to view it, or ask us anytime.</p><?php endif; ?>
  </div>
  <?php if ($b['status'] === 'awaiting_pricing'): ?>
    <div class="alert alert-ok mt-4">Your booking was received. Our team will verify mileage and send a secure payment link by email.</div>
  <?php elseif ($b['payment_status'] !== 'paid'): ?>
    <a href="<?= url('services/payment.php?booking=' . $b['booking_number']) ?>" class="btn-cta inline-block mt-4">Proceed to Payment</a>
  <?php endif; ?>
  <h2 class="font-display text-2xl mt-8">Next steps</h2>
  <ol class="text-sm mt-2 space-y-1 list-decimal ml-5"><li>We confirm your ride by email.</li><li>Your chauffeur is dispatched before pickup.</li><li>Pickup changes are possible until 2 hours before pickup.</li></ol>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Confirmation | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/public.php';
