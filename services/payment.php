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
<div class="max-w-5xl mx-auto px-4 py-12" x-data="{state:'idle', error:''}">
  <p class="eyebrow">Checkout</p>
  <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9] mt-2">Secure payment</h1>
  <?php if ($justPaid): ?><div class="alert alert-ok mt-4">Payment submitted — confirmation follows automatically once verified. Do not pay twice.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
  <div class="grid md:grid-cols-[1fr_1.4fr] gap-5 mt-6 items-start">
    <div class="card rounded-2xl p-6">
      <p class="tabular text-xs tracking-widest text-[#D9B978]">ORDER SUMMARY</p>
      <p class="tabular font-display text-3xl text-[#F9F9F9] mt-2"><?= e($booking['booking_number']) ?></p>
      <div class="text-sm mt-3 space-y-1.5">
        <p><?= e(ucwords(str_replace('_', ' ', $booking['service_type']))) ?> · <?= e($booking['pickup_date']) ?> <?= e(substr($booking['pickup_time'], 0, 5)) ?></p>
        <p class="text-[#AB8868]"><?= e(mb_strimwidth($booking['pickup_location'], 0, 42, '…')) ?> → <?= e(mb_strimwidth($booking['destination_location'], 0, 42, '…')) ?></p>
      </div>
      <div class="hairline-t mt-4 pt-4 flex items-center justify-between">
        <span class="text-sm text-[#AB8868]">Total due</span>
        <span class="tabular font-display text-3xl text-[#F3D4A6]">$<?= money($booking['total']) ?></span>
      </div>
      <p class="text-[11px] text-[#AB8868] mt-3">Status: <?= e($booking['status']) ?> · <?= e($booking['payment_status']) ?></p>
    </div>
    <div>
      <?php if (!$error && $stripeCfg['publishable']): ?>
      <div class="card rounded-2xl p-6">
        <p class="tabular text-xs tracking-widest text-[#D9B978]">CARD DETAILS</p>
        <div id="payment-element" class="mt-3"></div>
        <div id="pay-msg" class="text-sm mt-3" role="status"></div>
        <button id="payBtn" class="btn-gold rounded-full w-full mt-4 py-3.5 text-sm font-semibold" data-once-state>Pay $<?= money($booking['total']) ?></button>
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
      <?php elseif (!$error): ?>
      <div class="card rounded-2xl p-6 text-sm text-[#AB8868]">Online payment is not configured right now — our team will send a secure payment link, or arrange an offline payment.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Payment | Exotic Lane Limo';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
