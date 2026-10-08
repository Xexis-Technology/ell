<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    $tab = in_array($_POST['tab'] ?? '', ['drawer', 'payments', 'refunds'], true) ? $_POST['tab'] : 'drawer';

    if ($op === 'refund') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        // booking_status is needed for the partial-refund path: it preserves the
// booking's own status instead of overwriting it with the payment's.
$st = $pdo->prepare('SELECT p.*, b.booking_number, b.status AS booking_status FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ? LIMIT 1');
        $st->execute([$pid]);
        $p = $st->fetch();

        $rawAmount = trim((string)($_POST['amount'] ?? ''));
        $reason = substr(trim((string)($_POST['reason'] ?? '')), 0, 255);

        if (!$p) {
            $msg = 'That payment is no longer on file.';
            $isErr = true;
        } elseif (!in_array($p['status'], ['paid', 'partially_refunded'], true)) {
            $msg = 'A ' . $p['status'] . ' payment cannot be refunded.';
            $isErr = true;
        } elseif ($rawAmount === '') {
            $msg = 'Enter how much to refund, or use the full amount.';
            $isErr = true;
        } elseif (!is_numeric($rawAmount)) {
            $msg = 'The refund amount is not a number.';
            $isErr = true;
        } elseif ((float)$rawAmount <= 0) {
            // The old code did min($_POST['amount'], $amount) with no floor, so a
            // posted -50 wrote a negative refund and still flipped the payment to
            // 'refunded'. Floor it, and refuse a non-positive amount outright.
            $msg = 'A refund has to be more than zero.';
            $isErr = true;
        } elseif ($reason === '') {
            $msg = 'Add a reason so the record explains itself later.';
            $isErr = true;
        } else {
            $amount = round(min((float)$rawAmount, (float)$p['amount']), 2);
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
                    log_error('refund failed: ' . $ex->getMessage());
                }
            }

            if ($status === 'failed') {
                $msg = 'Stripe refused the refund, so nothing was changed. The reason from Stripe is in the error log.';
                $isErr = true;
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO refunds (payment_id, provider_refund_id, amount, status, reason, created_by) VALUES (?,?,?,?,?,?)')->execute([$pid, $refId, $amount, $status, $reason, (int)$admin['id']]);
                // "Full" means the payment is now given back in its entirety, so
                // compare the running total of successful refunds — not this one
                // refund against the original amount. Comparing only the latest
                // refund left a payment stuck at 'partially_refunded' forever once
                // it was refunded in two goes, and made the drawer read negative.
                $refundedSoFar = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) s FROM refunds WHERE payment_id = ' . $pid . ' AND status = "succeeded"')->fetchColumn();
                $full = abs($refundedSoFar - (float)$p['amount']) < 0.01;
                $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?')->execute([$full ? 'refunded' : 'partially_refunded', $pid]);
                $pdo->prepare('UPDATE bookings SET payment_status = ?, status = ? WHERE id = ?')->execute([$full ? 'refunded' : 'partially_refunded', $full ? 'refunded' : ($p['booking_status'] ?? 'confirmed'), (int)$p['booking_id']]);
                $b = $pdo->query('SELECT * FROM bookings WHERE id = ' . (int)$p['booking_id'])->fetch();
                $pdo->commit();
                if ($b) {
                    $email = $b['guest_email'];
                    if ($b['customer_id']) $email = $pdo->query('SELECT email FROM customers WHERE id = ' . $b['customer_id'])->fetch()['email'] ?? $email;
                    if ($email) NotificationService::bookingEmail($pdo, 'refund-processed', $b, $email, $b['customer_id'], $b['customer_id'] ? 'customer' : 'guest');
                }
                audit($pdo, 'admin', (int)$admin['id'], 'payment.refund', 'payment', $pid, ['amount' => $amount, 'reason' => $reason]);
                $msg = $full
                    ? '$' . money($amount) . ' refunded in full. ' . $p['booking_number'] . ' is closed.'
                    : '$' . money($amount) . ' refunded. $' . money((float)$p['amount'] - $amount) . ' still collected on ' . $p['booking_number'] . '.';
            }
        }
        $tab = 'payments';
    }

    header('Location: ' . url('admin/payments.php?tab=' . $tab . ($msg !== '' ? '&' . ($isErr ? 'err=1&' : '') . 'msg=' . urlencode($msg) : '')));
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
$isErr = isset($_GET['err']);
$tab = in_array($_GET['tab'] ?? '', ['drawer', 'payments', 'refunds'], true) ? $_GET['tab'] : 'drawer';
$editPay = (int)($_GET['refund'] ?? 0);

// ---- the drawer: three directions of the same money -------------------------
$pdo->exec('SET SESSION group_concat_max_len = 1024');
$drawer = $pdo->query('SELECT
    /* Gross takings: every payment that reached a collected state at some point.
       Refunds are subtracted once, below, in PHP — subtracting them here too
       double-counted them and drove the drawer negative. */
    COALESCE(SUM(CASE WHEN status IN ("paid","partially_refunded","refunded") THEN amount ELSE 0 END),0) AS gross_taken,
    /* What each payment still owes us, which is the honest per-row figure. */
    COALESCE(SUM(CASE WHEN status IN ("paid","partially_refunded","refunded")
        THEN GREATEST(amount - COALESCE((SELECT SUM(amount) FROM refunds r WHERE r.payment_id = p.id AND r.status = "succeeded"),0), 0)
        ELSE 0 END),0) AS still_held,
    COALESCE(SUM(CASE WHEN status IN ("pending","processing","failed") THEN amount ELSE 0 END),0) AS in_flight,
    COALESCE(SUM(CASE WHEN status = "failed" THEN amount ELSE 0 END),0) AS failed_value,
    COALESCE(SUM(CASE WHEN status IN ("pending","processing") THEN amount ELSE 0 END),0) AS awaiting_value,
    COUNT(*) AS total_rows,
    COUNT(CASE WHEN status IN ("paid","partially_refunded") THEN 1 END) AS settled_rows
    FROM payments p')->fetch();

$refundTotals = $pdo->query('SELECT COALESCE(SUM(amount),0) AS given_back, COUNT(*) AS rows_all,
    COUNT(CASE WHEN status = "succeeded" THEN 1 END) AS rows_ok,
    COUNT(CASE WHEN status <> "succeeded" THEN 1 END) AS rows_bad
    FROM refunds')->fetch();

$expected = $pdo->query('SELECT COUNT(*) AS rows_n, COALESCE(SUM(total),0) AS value
    FROM bookings WHERE payment_status IN ("pending","failed") AND status <> "cancelled"')->fetch();

$netTaken = max(0, (float)$drawer['gross_taken'] - (float)$refundTotals['given_back']);

// ---- payment list, joined so a row is a booking and not a number ------------
$show = $_GET['show'] ?? '';
$where = '1';
if ($show === 'chase') $where = 'p.status IN ("pending","processing","failed")';
elseif ($show === 'settled') $where = 'p.status IN ("paid","partially_refunded","refunded")';
elseif ($show === 'refunded') $where = 'p.status = "refunded"';

$pays = $pdo->query('SELECT p.*, b.booking_number, b.guest_name, b.status AS booking_status, b.pickup_date,
        COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_id = p.id AND r.status = "succeeded"),0) AS refunded_value
    FROM payments p JOIN bookings b ON b.id = p.booking_id
    WHERE ' . $where . '
    ORDER BY p.id DESC LIMIT 200')->fetchAll();

$refs = $pdo->query('SELECT r.*, p.booking_id, p.amount AS paid_amount, p.currency, b.booking_number, b.guest_name
    FROM refunds r
    JOIN payments p ON p.id = r.payment_id
    JOIN bookings b ON b.id = p.booking_id
    ORDER BY r.id DESC LIMIT 200')->fetchAll();

$tabs = [
    'drawer' => ['Drawer', 'Money in, out and expected'],
    'payments' => ['Payments', 'Every payment on file'],
    'refunds' => ['Refunds', 'Money given back'],
];

$methodLabel = static function (?string $m, string $provider): string {
    $m = strtolower(trim((string)$m));
    if ($m !== '') return ucwords(str_replace('_', ' ', $m));
    return $provider === 'stripe' ? 'Card' : 'Cash';
};

ob_start();
?>
<?php if ($msg !== ''): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="page-head">
  <div>
    <p class="eyebrow">Money</p>
    <h1 class="font-display text-3xl mt-1">Payments</h1>
  </div>
</div>

<nav class="pz-tabs" aria-label="Payment sections">
  <?php foreach ($tabs as $key => [$label, $sub]): ?>
    <a class="pz-tab<?= $tab === $key ? ' is-on' : '' ?>" href="<?= url('admin/payments.php?tab=' . $key) ?>"
       <?= $tab === $key ? 'aria-current="page"' : '' ?>>
      <span class="pz-tab-l"><?= e($label) ?></span>
      <span class="pz-tab-s"><?= e($sub) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'drawer'): ?>
<section class="pd-drawer" aria-label="Reconciliation">
  <p class="pd-rail-cap">The till at close of business</p>

  <div class="pd-rail">
    <!-- in -->
    <div class="pd-cell pd-in">
      <p class="pd-cell-k">Taken in</p>
      <p class="pd-cell-v">$<?= money((float)$drawer['gross_taken']) ?></p>
      <p class="pd-cell-s"><?= (int)$drawer['settled_rows'] ?> settled <?= (int)$drawer['settled_rows'] === 1 ? 'payment' : 'payments' ?></p>
    </div>

    <!-- expected: money that exists as a booking but not as cash -->
    <div class="pd-cell pd-exp">
      <p class="pd-cell-k">Expected, not taken</p>
      <p class="pd-cell-v">$<?= money((float)$expected['value']) ?></p>
      <p class="pd-cell-s"><?= (int)$expected['rows_n'] ?> unpaid <?= (int)$expected['rows_n'] === 1 ? 'booking' : 'bookings' ?></p>
      <?php if ((int)$expected['rows_n'] > 0): ?>
        <a class="pd-cell-a" href="<?= url('admin/bookings.php?filter=pending_payment') ?>">Chase these</a>
      <?php endif; ?>
    </div>

    <!-- out -->
    <div class="pd-cell pd-out">
      <p class="pd-cell-k">Given back</p>
      <p class="pd-cell-v">−$<?= money((float)$refundTotals['given_back']) ?></p>
      <p class="pd-cell-s"><?= (int)$refundTotals['rows_ok'] ?> <?= (int)$refundTotals['rows_ok'] === 1 ? 'refund' : 'refunds' ?><?= (int)$refundTotals['rows_bad'] > 0 ? ' &middot; ' . (int)$refundTotals['rows_bad'] . ' failed' : '' ?></p>
    </div>

    <!-- the difference -->
    <div class="pd-cell pd-net<?= $netTaken < 0 ? ' is-neg' : '' ?>">
      <p class="pd-cell-k">In the drawer</p>
      <p class="pd-cell-v">$<?= money($netTaken) ?></p>
      <p class="pd-cell-s">taken in, less refunds</p>
    </div>
  </div>

  <?php if ((float)$drawer['awaiting_value'] > 0 || (float)$drawer['failed_value'] > 0): ?>
    <p class="pd-flag">
      <?php if ((float)$drawer['failed_value'] > 0): ?>
        $<?= money((float)$drawer['failed_value']) ?> of <?= (int)$pdo->query('SELECT COUNT(*) c FROM payments WHERE status = "failed"')->fetchColumn() ?> payments failed.
      <?php endif; ?>
      <?php if ((float)$drawer['awaiting_value'] > 0): ?>
        $<?= money((float)$drawer['awaiting_value']) ?> is still being processed by the card company.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <div class="pd-next">
    <a class="pd-next-a" href="<?= url('admin/payments.php?tab=payments&show=chase') ?>">
      <span class="pd-next-k">Needs chasing</span>
      <span class="pd-next-v"><?= (int)$pdo->query('SELECT COUNT(*) c FROM payments WHERE status IN ("pending","processing","failed")')->fetchColumn() ?></span>
    </a>
    <a class="pd-next-a" href="<?= url('admin/payments.php?tab=payments') ?>">
      <span class="pd-next-k">All payments</span>
      <span class="pd-next-v"><?= (int)$drawer['total_rows'] ?></span>
    </a>
    <a class="pd-next-a" href="<?= url('admin/payments.php?tab=refunds') ?>">
      <span class="pd-next-k">Refunds issued</span>
      <span class="pd-next-v"><?= (int)$refundTotals['rows_all'] ?></span>
    </a>
  </div>
</section>

<div class="pd-col">
  <div class="pd-empty-inv">
    <p class="pd-note-h">How a payment moves through this page</p>
    <ol class="pd-flow">
      <li><b>Booked</b> — the customer reserves a car. Money is expected but not taken.</li>
      <li><b>Paid</b> — the card clears or cash is counted. It lands in <i>taken in</i>.</li>
      <li><b>Refunded</b> — you give some or all of it back. It leaves through <i>given back</i>.</li>
      <li><b>What is left</b> is what the drawer should hold.</li>
    </ol>
    <div class="pd-note-acts">
      <a class="pd-btn" href="<?= url('admin/payments.php?tab=payments') ?>">Open payments</a>
      <a class="pd-btn pd-btn-quiet" href="<?= url('admin/payments.php?tab=refunds') ?>">Open refunds</a>
    </div>
  </div>
</div>

<?php else: ?>
<?php if ($tab === 'payments'): ?>
<div class="pd-col">
  <div class="pd-filters" role="group" aria-label="Filter payments">
    <?php foreach (['' => 'All payments', 'chase' => 'Needs chasing', 'settled' => 'Settled', 'refunded' => 'Refunded'] as $k => $label): ?>
      <a class="pd-filter<?= $show === $k ? ' is-on' : '' ?>" href="<?= url('admin/payments.php?tab=payments' . ($k ? '&show=' . $k : '')) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="pd-filter-n"><?= count($pays) ?> shown</span>
  </div>

  <?php if (!$pays): ?>
    <p class="pz-empty">No payments here. <?= $show !== '' ? 'Try another filter.' : 'They appear once a booking is confirmed.' ?></p>
  <?php else: ?>
    <div class="table-wrap pz-table-wrap">
      <table class="data pz-table pd-table">
        <thead><tr><th>Booking</th><th>Customer</th><th>Paid by</th><th>Amount</th><th>Refunded</th><th>State</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pays as $p):
          $refundable = in_array($p['status'], ['paid', 'partially_refunded'], true);
          $back = (float)$p['refunded_value'];
          $left = (float)$p['amount'] - $back;
          $editing = $editPay === (int)$p['id']; ?>
          <?php if ($editing): ?>
            <tr class="pz-editing">
              <td colspan="8">
                <form method="post" class="pz-edit">
                  <?= csrf_field() ?><input type="hidden" name="op" value="refund"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="tab" value="payments">
                  <p class="pz-edit-h">Refund <?= e($p['booking_number']) ?> &mdash; $<?= money((float)$p['amount']) ?> taken, $<?= money($left) ?> still refundable</p>
                  <div class="pz-edit-grid pd-edit-grid">
                    <div>
                      <label class="bk-k" for="pd-amt-<?= (int)$p['id'] ?>">Refund amount</label>
                      <input id="pd-amt-<?= (int)$p['id'] ?>" name="amount" type="number" step="0.01" min="0.01" max="<?= e(number_format($left, 2, '.', '')) ?>" class="input" required placeholder="<?= e(number_format($left, 2, '.', '')) ?>">
                      <span class="fl-hint">Up to $<?= money($left) ?></span>
                    </div>
                    <div>
                      <label class="bk-k" for="pd-why-<?= (int)$p['id'] ?>">Reason</label>
                      <input id="pd-why-<?= (int)$p['id'] ?>" name="reason" class="input" required placeholder="Trip cancelled, overcharge, goodwill">
                      <span class="fl-hint">Recorded on the refund and in the log</span>
                    </div>
                    <div class="pd-edit-acts">
                      <button class="pz-save" type="submit">Issue refund</button>
                      <a class="pz-toggle" href="<?= url('admin/payments.php?tab=payments&show=' . e($show)) ?>">Cancel</a>
                    </div>
                  </div>
                  <p class="pd-edit-note">Refunds are emailed to the customer. A card refund usually shows on their statement in 3&ndash;5 days.</p>
                </form>
              </td>
            </tr>
          <?php endif; ?>
          <tr>
            <th scope="row"><a class="pd-book" href="<?= url('admin/bookings.php?action=view&n=' . $p['booking_number']) ?>"><?= e($p['booking_number']) ?></a></th>
            <td class="pd-name"><?= e(trim((string)($p['guest_name'] ?? '—'))) ?></td>
            <td class="pd-dim" data-l="Paid by"><?= e($methodLabel($p['method'], (string)$p['provider'])) ?></td>
            <td class="pz-num" data-l="Amount">$<?= money($p['amount']) ?></td>
            <td class="pz-num<?= $back > 0 ? ' is-out' : '' ?>" data-l="Refunded"><?= $back > 0 ? '−$' . money($back) : '—' ?></td>
            <td data-l="State"><?= status_pill($p['status']) ?></td>
            <td class="pd-dim" data-l="Date"><?= e(date('j M', strtotime((string)($p['paid_at'] ?: $p['created_at'])))) ?></td>
            <td>
              <div class="pz-acts">
                <?php if ($refundable): ?>
                  <?php if (!$editing): ?>
                    <a class="pz-toggle" href="<?= url('admin/payments.php?tab=payments&show=' . e($show) . '&refund=' . (int)$p['id']) ?>" aria-label="Refund <?= e($p['booking_number']) ?>">Refund</a>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="pd-col">
  <p class="pz-lede">Every refund issued, newest first. A refund either comes back to you as <i>pending</i> on the card statement or clears as <i>succeeded</i>.</p>

  <?php if (!$refs): ?>
    <p class="pz-empty">No refunds yet. When you give money back on the Payments tab it is recorded here.</p>
  <?php else: ?>
    <div class="table-wrap pz-table-wrap">
      <table class="data pz-table pd-table">
        <thead><tr><th>Booking</th><th>Customer</th><th>Refunded</th><th>Of total</th><th>Reason</th><th>State</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($refs as $r): ?>
          <tr>
            <th scope="row"><a class="pd-book" href="<?= url('admin/bookings.php?action=view&n=' . $r['booking_number']) ?>"><?= e($r['booking_number']) ?></a></th>
            <td class="pd-name"><?= e(trim((string)($r['guest_name'] ?? '—'))) ?></td>
            <td class="pz-num is-out" data-l="Refunded">−$<?= money($r['amount']) ?></td>
            <td class="pz-num" data-l="Of total">$<?= money($r['paid_amount']) ?></td>
            <td class="pd-why" data-l="Reason"><?= e(trim((string)($r['reason'] ?? '—'))) ?></td>
            <td data-l="State"><?= status_pill($r['status']) ?></td>
            <td class="pd-dim" data-l="Date"><?= e(date('j M Y', strtotime((string)$r['created_at']))) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
  <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
$pageTitle = 'Payments | Admin';
$navActive = 'payments.php';
require APP_ROOT . '/views/layouts/admin.php';