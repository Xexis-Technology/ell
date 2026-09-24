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
<div class="max-w-3xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Cancellation</h1>
  <div class="card p-6 mt-4 text-sm"><?= $policy ?: '<p>Cancellations follow the published policy. Request below.</p>' ?></div>
  <?php if ($message): ?><div class="alert alert-ok mt-4"><?= e($message) ?></div><?php endif; ?>
  <form method="post" class="card p-6 mt-4 space-y-4"><?= csrf_field() ?>
    <div><label class="label" for="booking_number">Booking number</label><input id="booking_number" name="booking_number" class="input" required pattern="[0-9]{8}" placeholder="8-digit number"></div>
    <div><label class="label" for="email">Booking email</label><input id="email" type="email" name="email" class="input" required placeholder="you@example.com"></div>
    <button class="btn-cta">Request Cancellation</button>
  </form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Cancellation | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/public.php';
