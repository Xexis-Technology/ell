<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$cfg = require APP_ROOT . '/config/stripe.php';

$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if ($cfg['webhook_secret'] !== '' && $sig !== '') {
    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig, $cfg['webhook_secret']);
        $ok = PaymentService::applyEvent($pdo, $event->id, $event->type, $event->data->object->toArray());
        // Post-payment notifications (once — applyEvent is idempotent, guard invoice mail)
        if ($ok && $event->type === 'payment_intent.succeeded') {
            $obj = $event->data->object->toArray();
            $st = $pdo->prepare('SELECT * FROM payments WHERE provider_payment_id = ? LIMIT 1');
            $st->execute([$obj['id']]);
            if ($pay = $st->fetch()) {
                $b = $pdo->query('SELECT * FROM bookings WHERE id = ' . (int)$pay['booking_id'])->fetch();
                if ($b) {
                    $email = $b['guest_email'];
                    if ($b['customer_id']) $email = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$b['customer_id'])->fetch()['email'] ?? $email;
                    if ($email) NotificationService::bookingEmail($pdo, 'payment-received', $b, $email, $b['customer_id'], $b['customer_id'] ? 'customer' : 'guest');
                }
            }
        }
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    } catch (Throwable $ex) {
        log_error('stripe webhook verify/apply failed: ' . $ex->getMessage());
        http_response_code(400);
        echo json_encode(['error' => 'invalid signature or processing error']);
        exit;
    }
}
// No secret configured or missing signature: safe configuration state
log_error('stripe webhook called without valid signature');
http_response_code(400);
echo json_encode(['error' => 'webhook not configured']);
