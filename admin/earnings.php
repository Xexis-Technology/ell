<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
require_role('admin');

$msg = '';
$isErr = false;

$companyPct = EarningsService::COMPANY_PCT;
$driverPct = EarningsService::DRIVER_PCT;
$payoutDays = (int)(setting($pdo, 'payout_interval_days', '7'));

// ---- the ledger -------------------------------------------------------------
$tot = $pdo->query('SELECT COALESCE(SUM(gross_amount),0) g,
        COALESCE(SUM(company_amount),0) c,
        COALESCE(SUM(driver_amount),0) d,
        COUNT(*) rows_n,
        COUNT(CASE WHEN status = "unpaid" THEN 1 END) unpaid_n,
        COALESCE(SUM(CASE WHEN status = "unpaid" THEN driver_amount ELSE 0 END),0) driver_owed,
        COALESCE(SUM(CASE WHEN status = "unpaid" THEN company_amount ELSE 0 END),0) company_held,
        COALESCE(SUM(CASE WHEN status = "paid" THEN driver_amount ELSE 0 END),0) driver_paid
    FROM driver_earnings')->fetch();

$show = (string)($_GET['show'] ?? '');
$where = '1';
if ($show === 'unpaid') $where = 'e.status = "unpaid"';
elseif ($show === 'paid') $where = 'e.status = "paid"';

$rows = $pdo->query('SELECT e.*, d.name AS dname, d.reference AS dref, b.booking_number, b.status AS booking_status, b.pickup_date
    FROM driver_earnings e
    JOIN drivers d ON d.id = e.driver_id
    JOIN bookings b ON b.id = e.booking_id
    WHERE ' . $where . '
    ORDER BY e.id DESC LIMIT 300')->fetchAll();

// ---- who is waiting ---------------------------------------------------------
// Per driver: what they have earned unpaid, and when the next payout window
// opens. The window is the real constraint on when money can move, so it is
// computed here rather than left in EarningsService for a driver-facing page.
$drivers = $pdo->query('SELECT d.id, d.name, d.reference, d.status,
        COALESCE(SUM(CASE WHEN e.status = "unpaid" THEN e.driver_amount ELSE 0 END),0) owed,
        COUNT(CASE WHEN e.status = "unpaid" THEN 1 END) owed_trips,
        COUNT(CASE WHEN e.status = "paid" THEN 1 END) paid_trips,
        MAX(CASE WHEN e.status = "unpaid" THEN e.earned_at END) last_earned
    FROM drivers d
    LEFT JOIN driver_earnings e ON e.driver_id = d.id
    GROUP BY d.id, d.name, d.reference, d.status
    ORDER BY owed DESC, d.name')->fetchAll();

$lastPayout = [];
foreach ($pdo->query('SELECT driver_id, MAX(requested_at) t FROM driver_payouts WHERE status IN ("requested","approved","paid") GROUP BY driver_id') as $r) {
    $lastPayout[(int)$r['driver_id']] = $r['t'];
}
$pendingPayouts = (int)$pdo->query('SELECT COUNT(*) c FROM driver_payouts WHERE status IN ("requested","approved")')->fetchColumn();
$pendingPayoutValue = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) s FROM driver_payouts WHERE status IN ("requested","approved")')->fetchColumn();

// Split integrity: the engine writes company = gross * 20%, driver = gross - company,
// so gross should always equal company + driver. Any drift means the arithmetic
// did not survive a rounding path, and it should be visible rather than assumed.
$splitDrift = 0.0;
foreach ($rows as $r) $splitDrift = max($splitDrift, abs((float)$r['gross_amount'] - ((float)$r['company_amount'] + (float)$r['driver_amount'])));

ob_start();
?>
<?php if ($msg !== ''): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="page-head">
  <div>
    <p class="eyebrow">Money</p>
    <h1 class="font-display text-3xl mt-1">Driver earnings</h1>
  </div>
</div>

<?php if (!$rows): ?>
  <?php if ($show !== ''): ?>
    <div class="en-blank">
      <p class="en-blank-h">Nothing <?= $show === 'unpaid' ? 'unpaid' : 'paid out' ?> right now</p>
      <p><?= $show === 'unpaid'
        ? 'Every trip on the books has been paid to its chauffeur. Unpaid trips land here the moment a booking finishes.'
        : 'No chauffeur has been paid out yet. Approving a payout request moves its trips here.' ?></p>
      <div class="en-blank-acts">
        <a class="en-btn" href="<?= url('admin/earnings.php') ?>">Show all trips</a>
        <?php if ($show === 'unpaid'): ?><a class="en-btn en-btn-quiet" href="<?= url('admin/payouts.php') ?>">Open payouts</a><?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="en-blank">
      <p class="en-blank-h">No earnings recorded yet</p>
      <p>A booking earns its split when it finishes, not when it is booked. Finish a trip and the <?= $companyPct ?>% house cut and the <?= $driverPct ?>% chauffeur share appear here the moment it is paid.</p>
      <div class="en-blank-acts">
        <a class="en-btn" href="<?= url('admin/bookings.php') ?>">Open bookings</a>
        <a class="en-btn en-btn-quiet" href="<?= url('admin/dispatch.php') ?>">Open dispatch</a>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>

<!-- the cut, at ledger scale -->
<section class="en-ledger" aria-label="Earnings split">
  <div class="en-cut">
    <div class="en-cut-head">
      <p class="en-cut-k">Every fare on the books</p>
      <p class="en-cut-total">$<?= money((float)$tot['g']) ?></p>
      <p class="en-cut-s"><?= (int)$tot['rows_n'] ?> <?= (int)$tot['rows_n'] === 1 ? 'trip' : 'trips' ?> split <?= $companyPct ?>/<?= $driverPct ?></p>
    </div>
    <div class="en-cut-bar" role="img"
         aria-label="House takes <?= $companyPct ?> percent, $<?= money((float)$tot['c']) ?>. Chauffeurs take <?= $driverPct ?> percent, $<?= money((float)$tot['d']) ?>.">
      <span class="en-seg en-seg-house" style="width:<?= (float)$tot['g'] > 0 ? $companyPct : 0 ?>%"></span>
      <span class="en-seg en-seg-drv" style="width:<?= (float)$tot['g'] > 0 ? $driverPct : 0 ?>%"></span>
    </div>
    <div class="en-legend">
      <div class="en-leg">
        <span class="en-dot en-dot-house" aria-hidden="true"></span>
        <span class="en-leg-k">House &mdash; <?= $companyPct ?>%</span>
        <span class="en-leg-v">$<?= money((float)$tot['c']) ?></span>
      </div>
      <div class="en-leg">
        <span class="en-dot en-dot-drv" aria-hidden="true"></span>
        <span class="en-leg-k">Chauffeurs &mdash; <?= $driverPct ?>%</span>
        <span class="en-leg-v">$<?= money((float)$tot['d']) ?></span>
      </div>
    </div>
  </div>

  <!-- what has actually moved -->
  <div class="en-moved">
    <div class="en-mv">
      <p class="en-mv-k">Paid to chauffeurs</p>
      <p class="en-mv-v">$<?= money((float)$tot['driver_paid']) ?></p>
      <p class="en-mv-s"><?= (int)$tot['rows_n'] - (int)$tot['unpaid_n'] ?> settled <?= ((int)$tot['rows_n'] - (int)$tot['unpaid_n']) === 1 ? 'trip' : 'trips' ?></p>
    </div>
    <div class="en-mv">
      <p class="en-mv-k">Owed to chauffeurs</p>
      <p class="en-mv-v is-wait">$<?= money((float)$tot['driver_owed']) ?></p>
      <p class="en-mv-s"><?= (int)$tot['unpaid_n'] ?> unpaid <?= (int)$tot['unpaid_n'] === 1 ? 'trip' : 'trips' ?></p>
    </div>
    <div class="en-mv">
      <p class="en-mv-k">House holds</p>
      <p class="en-mv-v">$<?= money((float)$tot['company_held']) ?></p>
      <p class="en-mv-s">its cut on unpaid trips</p>
    </div>
  </div>
</section>

<?php if ($splitDrift > 0.005): ?>
  <p class="en-drift">
    <?= count($rows) ?> <?= count($rows) === 1 ? 'row does' : 'rows do' ?> not add up. Gross and the two shares are out by up to $<?= money($splitDrift) ?>.
  </p>
<?php endif; ?>

<?php if ($pendingPayouts > 0): ?>
  <p class="en-flag"><?= $pendingPayouts ?> payout <?= $pendingPayouts === 1 ? 'request is' : 'requests are' ?> waiting on review &mdash; $<?= money($pendingPayoutValue) ?>.</p>
<?php endif; ?>

<!-- the ledger -->
<div class="en-col">
  <div class="en-filters" role="group" aria-label="Filter earnings">
    <?php foreach (['' => 'All trips', 'unpaid' => 'Unpaid', 'paid' => 'Paid out'] as $k => $label): ?>
      <a class="en-filter<?= $show === $k ? ' is-on' : '' ?>" href="<?= url('admin/earnings.php' . ($k ? '?show=' . $k : '')) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="en-filter-n"><?= count($rows) ?> shown</span>
  </div>

  <div class="table-wrap pz-table-wrap">
    <table class="data pz-table en-table">
      <thead><tr><th>Booking</th><th>Chauffeur</th><th>The cut</th><th>Gross</th><th>House</th><th>Chauffeur</th><th>State</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $gross = (float)$r['gross_amount'];
        $houseW = $gross > 0 ? (float)$r['company_amount'] / $gross * 100 : 0; ?>
        <tr>
          <th scope="row"><a class="en-book" href="<?= url('admin/bookings.php?action=view&n=' . $r['booking_number']) ?>"><?= e($r['booking_number']) ?></a></th>
          <td class="en-who"><?= e($r['dname']) ?></td>
          <td class="en-cutcell">
            <span class="en-mini" role="img" aria-label="House takes $<?= money($r['company_amount']) ?>, chauffeur takes $<?= money($r['driver_amount']) ?>">
              <i class="en-mini-h" style="width:<?= e(number_format($houseW, 4, '.', '')) ?>%"></i>
            </span>
          </td>
          <td class="pz-num" data-l="Gross">$<?= money($r['gross_amount']) ?></td>
          <td class="pz-num en-h" data-l="House">$<?= money($r['company_amount']) ?></td>
          <td class="pz-num en-d" data-l="Chauffeur">$<?= money($r['driver_amount']) ?></td>
          <td data-l="State"><?= status_pill($r['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- who is waiting, and when they can take it -->
<section class="en-who-band" aria-label="Chauffeur balances">
  <p class="en-band-k">Who is waiting</p>
  <?php
  $anyOwed = false;
  foreach ($drivers as $d) if ((float)$d['owed'] > 0) { $anyOwed = true; break; }
  ?>
  <div class="en-drivers">
    <?php foreach ($drivers as $d):
      $owed = (float)$d['owed'];
      $last = $lastPayout[(int)$d['id']] ?? null;
      if ($last) {
        $opens = strtotime($last) + $payoutDays * 86400;
        $daysLeft = (int)ceil(($opens - strtotime('today')) / 86400);
      } else {
        $opens = null;
        $daysLeft = 0;
      } ?>
      <article class="en-drv<?= $owed > 0 ? ' is-owed' : '' ?>">
        <header class="en-drv-top">
          <span class="en-drv-name"><?= e($d['name']) ?></span>
          <?php if ($d['reference']): ?><code class="en-drv-ref"><?= e($d['reference']) ?></code><?php endif; ?>
        </header>
        <p class="en-drv-owed"><?= $owed > 0 ? '$' . money($owed) : 'nothing owed' ?></p>
        <p class="en-drv-s">
          <?php if ($owed > 0): ?>
            <?= (int)$d['owed_trips'] ?> unpaid <?= (int)$d['owed_trips'] === 1 ? 'trip' : 'trips' ?>
            <?php if ($opens !== null): ?>
              &middot; next window <?= $daysLeft <= 0 ? 'open' : 'in ' . $daysLeft . ' ' . ($daysLeft === 1 ? 'day' : 'days') ?>
            <?php else: ?>
              &middot; can withdraw now
            <?php endif; ?>
          <?php else: ?>
            <?= (int)$d['paid_trips'] ?> <?= (int)$d['paid_trips'] === 1 ? 'trip' : 'trips' ?> settled
          <?php endif; ?>
        </p>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="en-band-note">Chauffeurs can request a payout once every <?= $payoutDays ?> days. Approve a request on the payouts page.</p>
</section>

<?php endif; ?>

<?php
$content = ob_get_clean();
$pageTitle = 'Earnings | Admin';
$navActive = 'earnings.php';
require APP_ROOT . '/views/layouts/admin.php';