<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$stripeCfg = require APP_ROOT . '/config/stripe.php';

$booking = null;
$linkRow = null;
// Secure payment-link flow (?token=)
if (!empty($_GET['token'])) {
    $linkRow = PaymentService::resolvePaymentLink($pdo, (string)$_GET['token']);
    if (!$linkRow) {
        http_response_code(410);
        echo 'This payment link is invalid, expired, or already used.';
        exit;
    }
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
    $st->execute([$linkRow['booking_id']]);
    $booking = $st->fetch();
} elseif (!empty($_GET['booking'])) {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE booking_number = ? LIMIT 1');
    $st->execute([$_GET['booking']]);
    $booking = $st->fetch();
    if (!$booking) {
        http_response_code(404);
        require APP_ROOT . '/views/errors/404.php';
        exit;
    }
} else {
    redirect('services/booking.php');
}

if ($booking['payment_status'] === 'paid') {
    redirect('services/booking-confirmation.php?n=' . $booking['booking_number']);
}

// Handle Stripe return (?paid=1 is informational only — webhook is authoritative)
$justPaid = isset($_GET['paid']);

// Ensure a PaymentIntent exists
$intent = PaymentService::createIntent($pdo, (int)$booking['id']);
$error = $intent['error'] ?? null;
if ($linkRow && !isset($intent['error'])) {
    // Token single-use: mark used once payment object exists
    $st = $pdo->prepare('SELECT id FROM payment_links WHERE token_hash = ? LIMIT 1');
    $st->execute([hash('sha256', (string)$_GET['token'])]);
    if ($lr = $st->fetch()) PaymentService::markLinkUsed($pdo, (int)$lr['id']);
}

ob_start();
?>
<div class="max-w-2xl mx-auto px-4 py-12" x-data="{state:'idle', error:''}">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Secure Payment</h1>
  <p class="text-sm text-[#AB8868]">Booking <?= e($booking['booking_number']) ?> · Total <strong>$<?= money($booking['total']) ?></strong></p>
  <?php if ($justPaid): ?><div class="alert alert-ok mt-4">Payment submitted — confirmation follows automatically once verified. Do not pay twice.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$error && $stripeCfg['publishable']): ?>
  <div class="card p-6 mt-6">
    <div id="payment-element"></div>
    <div id="pay-msg" class="text-sm mt-3" role="status"></div>
    <button id="payBtn" class="btn-cta w-full mt-4" data-once-state>Pay $<?= money($booking['total']) ?></button>
    <p class="text-xs mt-3 text-[#AB8868]">Card data goes directly to Stripe — we never store raw card data.</p>
  </div>
  <script src="https://js.stripe.com/v3/"></script>
  <script>
  const stripe = Stripe(<?= json_encode($stripeCfg['publishable']) ?>);
  const elements = stripe.elements({ clientSecret: <?= json_encode($intent['client_secret']) ?> });
  const paymentElement = elements.create('payment');
  paymentElement.mount('#payment-element');
  const btn = document.getElementById('payBtn'), msg = document.getElementById('pay-msg');
  btn.addEventListener('click', async () => {
    btn.disabled = true; msg.textContent = 'Processing…';
    const { error } = await stripe.confirmPayment({ elements, confirmParams: { return_url: <?= json_encode(url('services/payment.php?booking=' . $booking['booking_number'] . '&paid=1')) ?> } });
    if (error) { msg.textContent = error.message || 'Payment failed.'; btn.disabled = false; }
  });
  </script>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Payment | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/public.php';
