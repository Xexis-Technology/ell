<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
DriverPortal::require_cleared($pdo, $driver);
$did = (int)$driver['id'];

/*
  A driver's earnings are not one figure. Every fare is split - the house takes
  20%, they keep 80% - so the page is built as a statement rather than a total:
  what the fare was, what the house took, what was left. The split is the same
  truth the totals came from, so the bar and the rows can never disagree.
*/
$st = $pdo->prepare(
    'SELECT e.id, b.booking_number, e.gross_amount, e.company_amount, e.driver_amount,
            e.status, e.earned_at, e.paid_at,
            b.pickup_location, b.destination_location, b.guest_name
       FROM driver_earnings e
       JOIN bookings b ON b.id = e.booking_id
      WHERE e.driver_id = ?
      ORDER BY e.earned_at DESC, e.id DESC
      LIMIT 250'
);
$st->execute([$did]);
$rows = $st->fetchAll();

$tot = $pdo->prepare(
    'SELECT COALESCE(SUM(gross_amount),0) g, COALESCE(SUM(company_amount),0) c,
            COALESCE(SUM(driver_amount),0) d,
            COALESCE(SUM(CASE WHEN status = "unpaid" THEN driver_amount ELSE 0 END),0) u,
            COUNT(*) n
       FROM driver_earnings WHERE driver_id = ?'
);
$tot->execute([$did]);
$t = $tot->fetch();
$gross = (float)$t['g'];
$house = (float)$t['c'];
$mine = (float)$t['d'];
$waiting = (float)$t['u'];
$count = (int)$t['n'];
$perRide = $count > 0 ? $mine / $count : 0.0;
// Share of the whole fare that reached the driver, from the rows themselves.
$share = $gross > 0 ? (int)round($mine / $gross * 100) : 0;

ob_start();
?>
<div class="earn-page">

  <header class="sheet-head">
    <p class="sheet-when"><span class="drv-k">Your statement</span> <?= e(strtoupper(date('D j M Y'))) ?></p>
    <h1 class="sheet-name">Earnings</h1>
  </header>

  <?php if (!$rows): ?>
    <?php /* No earnings is an invitation, not an apology: it says where the
             money appears and that finishing a ride is what fills this page. */ ?>
    <div class="sheet sheet-empty-sheet">
      <div class="sheet-empty">
        <span class="sheet-empty-mark" aria-hidden="true"><i></i><i></i></span>
        <p class="sheet-empty-h">Nothing earned yet</p>
        <p class="sheet-empty-sub">Every finished ride appears here with the fare, the house cut and your share.</p>
      </div>
      <a class="drv-btn drv-btn-line sheet-go" href="<?= url('driver/rides.php') ?>">See my rides</a>
    </div>
  <?php else: ?>

    <?php /*
      The split, drawn once at the size it deserves. This is the thesis of the
      page: of everything these rides were worth, this much was yours. A number
      labelled "Total (80%)" states the same fact and proves nothing - this bar
      is the arithmetic, and the figures beside it are the sum of the rows below.
    */ ?>
    <section class="split" aria-label="How your earnings divide">
      <div class="split-bar" role="img"
           aria-label="<?= e($share) ?> percent of <?= money($gross) ?> in fares went to the driver">
        <i class="split-mine" style="width:<?= max(0, min(100, $share)) ?>%"></i>
      </div>
      <div class="split-legend">
        <span class="split-mine-k"><i aria-hidden="true"></i>Yours <b>$<?= money($mine) ?></b></span>
        <span class="split-house-k"><i aria-hidden="true"></i>The house took <b>$<?= money($house) ?></b></span>
        <span class="split-pct"><?= $share ?>%</span>
      </div>
    </section>

    <div class="stat-grid is-4 earn-counts">
      <article class="stat is-live">
        <span class="stat-k">Waiting on you</span>
        <strong class="stat-v">$<?= money($waiting) ?></strong>
        <span class="stat-s">not paid out yet</span>
      </article>
      <article class="stat is-quiet">
        <span class="stat-k">Paid to you</span>
        <strong class="stat-v">$<?= money($mine - $waiting) ?></strong>
        <span class="stat-s">already sent</span>
      </article>
      <article class="stat is-quiet">
        <span class="stat-k">Per ride</span>
        <strong class="stat-v">$<?= money($perRide) ?></strong>
        <span class="stat-s">your share, average</span>
      </article>
      <article class="stat is-quiet">
        <span class="stat-k">Rides counted</span>
        <strong class="stat-v"><?= $count ?></strong>
        <span class="stat-s">on this statement</span>
      </article>
    </div>

    <?php
    /*
      The statement itself, in months, newest first. Each row is an arithmetic
      line - the fare, the cut, what is left - because that is what the money
      actually was, and a bare total could not be argued with.
    */
    $months = [];
    foreach ($rows as $r) {
        $months[(string)date('Y-m', strtotime((string)$r['earned_at']))][] = $r;
    }
    foreach ($months as $ym => $lines):
        $mGross = $mMine = 0.0;
        foreach ($lines as $l) { $mGross += (float)$l['gross_amount']; $mMine += (float)$l['driver_amount']; }
    ?>
      <section class="ledger">
        <header class="ledger-h">
          <span class="ledger-m"><?= e(date('F Y', (int)strtotime($ym . '-01'))) ?></span>
          <i aria-hidden="true"></i>
          <span class="ledger-t">$<?= money($mMine) ?> <em>of $<?= money($mGross) ?></em></span>
        </header>
        <ul class="ledger-list">
          <?php foreach ($lines as $r):
            $paid = (string)$r['status'] === 'paid';
            $partial = (string)$r['status'] === 'partially_paid';
          ?>
            <li class="ledger-row <?= $paid ? 'is-ok' : ($partial ? 'is-warn' : 'is-wait') ?>">
              <span class="ledger-when">
                <?= e(date('j M', (int)strtotime((string)$r['earned_at']))) ?>
                <em><?= e((string)($r['paid_at'] !== null ? 'paid ' . date('j M', (int)strtotime((string)$r['paid_at'])) : 'waiting')) ?></em>
              </span>
              <span class="ledger-what">
                <b class="ledger-no"><?= e((string)$r['booking_number']) ?></b>
                <?php if (trim((string)($r['guest_name'] ?? '')) !== ''): ?>
                  <span class="ledger-guest"><?= e((string)$r['guest_name']) ?></span>
                <?php endif; ?>
                <span class="ledger-where"><?= e((string)$r['pickup_location']) ?> → <?= e((string)$r['destination_location']) ?></span>
              </span>
              <?php /* The arithmetic, in the order it happened. */ ?>
              <span class="ledger-math">
                <span class="ledger-gross">$<?= money((float)$r['gross_amount']) ?></span>
                <span class="ledger-cut">− $<?= money((float)$r['company_amount']) ?></span>
                <span class="ledger-net">$<?= money((float)$r['driver_amount']) ?></span>
              </span>
              <span class="ledger-state"><?= $paid ? 'Paid' : ($partial ? 'Part paid' : 'Waiting') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>

    <a class="drv-btn drv-btn-line earn-go" href="<?= url('driver/payout.php') ?>">Take it out</a>
  <?php endif; ?>

</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Earnings | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';