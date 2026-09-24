<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['op'] ?? '') === 'refund') {
        $pid = (int)$_POST['payment_id'];
        $st = $pdo->prepare('SELECT p.*, b.booking_number FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ? LIMIT 1');
        $st->execute([$pid]);
        $p = $st->fetch();
        if ($p) {
            $amount = min((float)$_POST['amount'], (float)$p['amount']);
            $stripeCfg = require APP_ROOT . '/config/stripe.php';
            $refId = null;
            $status = 'succeeded';
            if ($p['provider'] === 'stripe' && $p['provider_payment_id'] && $stripeCfg['configured']) {
                try {
                    \Stripe\Stripe::setApiKey($stripeCfg['secret']);
                    $pi = \Stripe\PaymentIntent::retrieve($p['provider_payment_id']);
                    $chId = is_array($pi->latest_charge) ? null : $pi->latest_charge;
                    $charge = $chId ? \Stripe\Charge::retrieve($chId) : null;
                    if ($charge) {
                        $ref = \Stripe\Refund::create(['charge' => $charge->id, 'amount' => (int)round($amount * 100)]);
                        $refId = $ref->id;
                    }
                } catch (Throwable $ex) {
                    $status = 'failed';
                    $msg = 'Stripe refund failed.';
                    log_error('refund failed: ' . $ex->getMessage());
                }
            }
            if ($msg === '') {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO refunds (payment_id, provider_refund_id, amount, status, reason, created_by) VALUES (?,?,?,?,?,?)')->execute([$pid, $refId, $amount, $status, substr(trim($_POST['reason'] ?? ''), 0, 255), (int)$admin['id']]);
                if ($status === 'succeeded') {
                    $full = abs($amount - (float)$p['amount']) < 0.01;
                    $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?')->execute([$full ? 'refunded' : 'partially_refunded', $pid]);
                    $pdo->prepare('UPDATE bookings SET payment_status = ?, status = ? WHERE id = ?')->execute([$full ? 'refunded' : 'partially_refunded', $full ? 'refunded' : $p['status'] ?? 'confirmed', $p['booking_id']]);
                    $b = $pdo->query('SELECT * FROM bookings WHERE id = ' . (int)$p['booking_id'])->fetch();
                    $email = $b['guest_email'];
                    if ($b['customer_id']) $email = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$b['customer_id'])->fetch()['email'] ?? $email;
                    if ($email) NotificationService::bookingEmail($pdo, 'refund-processed', $b, $email, $b['customer_id'], $b['customer_id'] ? 'customer' : 'guest');
                }
                $pdo->commit();
                audit($pdo, 'admin', (int)$admin['id'], 'payment.refund', 'payment', $pid, ['amount' => $amount]);
                $msg = 'Refund ' . $status . '.';
            }
        }
    }
    header('Location: ' . url('admin/payments.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$pays = $pdo->query('SELECT p.*, b.booking_number FROM payments p JOIN bookings b ON b.id = p.booking_id ORDER BY p.id DESC LIMIT 200')->fetchAll();
$refs = $pdo->query('SELECT r.*, p.booking_id FROM refunds r JOIN payments p ON p.id = r.payment_id ORDER BY r.id DESC LIMIT 100')->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Payments &amp; Refunds</h1>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>ID</th><th>Booking</th><th>Provider</th><th>Amount</th><th>Status</th><th>Provider ID</th><th>Refund</th></tr></thead><tbody>
<?php foreach ($pays as $p): ?><tr><td><?= (int)$p['id'] ?></td><td><a class="underline" href="<?= url('admin/bookings.php?action=view&n=' . $p['booking_number']) ?>"><?= e($p['booking_number']) ?></a></td><td><?= e($p['provider']) ?></td><td>$<?= money($p['amount']) ?></td><td><?= e($p['status']) ?></td><td class="text-xs"><?= e((string)($p['provider_payment_id'] ?? '')) ?></td>
<td><?php if (in_array($p['status'], ['paid','partially_refunded'], true)): ?><form method="post" class="flex gap-1"><?= csrf_field() ?><input type="hidden" name="op" value="refund"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><input name="amount" type="number" step="0.01" max="<?= e((string)$p['amount']) ?>" class="input" style="width:90px" placeholder="Amt" required><input name="reason" class="input" style="width:110px" placeholder="Reason"><button class="btn-gold" data-confirm="Issue this refund?">Refund</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<h2 class="font-display text-2xl mt-6">Refund records</h2>
<div class="table-wrap card mt-2"><table class="data"><thead><tr><th>ID</th><th>Payment</th><th>Amount</th><th>Status</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($refs as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= (int)$r['payment_id'] ?></td><td>$<?= money($r['amount']) ?></td><td><?= e($r['status']) ?></td><td><?= e((string)($r['reason'] ?? '')) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Payments | Admin';
$navActive = 'payments.php';
require APP_ROOT . '/views/layouts/admin.php';
