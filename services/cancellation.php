<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $n = trim($_POST['booking_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $st = $pdo->prepare('SELECT * FROM bookings WHERE booking_number = ? LIMIT 1');
    $st->execute([$n]);
    $b = $st->fetch();
    if (!$b) {
        $message = 'Booking not found.';
    } else {
        $ownerEmail = null;
        if ($b['customer_id']) {
            $ownerEmail = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$b['customer_id'])->fetch()['email'] ?? null;
        } else {
            $ownerEmail = $b['guest_email'];
        }
        if ($ownerEmail && !hash_equals(strtolower($ownerEmail), strtolower($email))) {
            $message = 'Email does not match this booking.';
        } elseif (in_array($b['status'], ['finish','cancelled','refunded'], true)) {
            $message = 'This booking can no longer be cancelled.';
        } else {
            BookingService::setStatus($pdo, (int)$b['id'], 'cancelled', $b['customer_id'] ? 'customer' : 'system', $b['customer_id'], 'Customer cancellation request');
            $pdo->prepare('UPDATE bookings SET cancelled_at = NOW() WHERE id = ?')->execute([$b['id']]);
            if ($ownerEmail) NotificationService::bookingEmail($pdo, 'booking-cancelled', $b, $ownerEmail, $b['customer_id'], $b['customer_id'] ? 'customer' : 'guest');
            $message = 'Booking ' . $b['booking_number'] . ' has been cancelled per policy.';
        }
    }
}
try {
    $policy = $pdo->query('SELECT body FROM content WHERE slug = "cancellation" LIMIT 1')->fetch()['body'] ?? '';
} catch (Throwable) { $policy = ''; }
ob_start();
?>
<div class="max-w-5xl mx-auto px-4 py-12">
  <p class="eyebrow">Cancellation</p>
  <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9] mt-2">Cancel a booking</h1>
  <p class="text-sm text-[#AB8868] mt-2">Have your 8-digit booking number and booking email ready.</p>
  <?php if ($message): ?><div class="alert alert-ok mt-4"><?= e($message) ?></div><?php endif; ?>
  <div class="grid md:grid-cols-2 gap-5 mt-6 items-start">
    <div class="card rounded-2xl p-6">
      <h2 class="font-display text-xl text-[#F3D4A6]">Policy</h2>
      <div class="text-sm mt-2 space-y-2"><?= $policy ?: '<p>Cancellations follow the published policy. Request below.</p>' ?></div>
      <p class="text-xs text-[#AB8868] mt-4">Paid bookings are refunded per policy. <a class="underline" href="<?= url('legal/terms.php') ?>">Read terms</a> · <a class="underline" href="<?= url('legal/contact.php') ?>">Contact us</a></p>
    </div>
    <form method="post" class="card rounded-2xl p-6 space-y-4"><?= csrf_field() ?>
      <h2 class="font-display text-xl text-[#F3D4A6]">Request</h2>
      <div><label class="label" for="booking_number">Booking number</label><input id="booking_number" name="booking_number" class="input" required pattern="[0-9]{8}" placeholder="8-digit number"></div>
      <div><label class="label" for="email">Booking email</label><input id="email" type="email" name="email" class="input" required placeholder="you@example.com"></div>
      <button class="btn-danger-outline rounded-full w-full py-3 text-sm font-semibold">Request cancellation</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Cancellation | Exotic Lane Limo';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
