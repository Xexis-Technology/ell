<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
DriverPortal::require_cleared($pdo, $driver);
$did = (int)$driver['id'];
$msg = '';
$isErr = false;

/*
  What is on the driver's own record for where money goes. It is set once, when
  the office sets the chauffeur up, and asking for it again on every payout
  invites a typo straight into a bank detail.
*/
$fresh = DriverPortal::fresh($pdo, $did);
$onFile = trim((string)($fresh['payout_reference'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $elig = EarningsService::payoutEligible($pdo, $did);
    if (!$elig['eligible']) {
        $msg = $elig['reason'];
        $isErr = true;
    } else {
        // Blank means "use the one already on file", which is the common case
        // and the reason the field is not required.
        $dest = trim((string)($_POST['destination'] ?? ''));
        if ($dest === '') $dest = $onFile;
        if ($dest === '') {
            $msg = 'Add where the money should go before asking for it.';
            $isErr = true;
        } else {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO driver_payouts (driver_id, amount, eligible_at, status, destination_reference, requested_at)
                           VALUES (?,?,?,?,?,?)')
                ->execute([$did, $elig['balance'], $now, 'requested', substr($dest, 0, 255), $now]);
            $pid = (int)$pdo->lastInsertId();
            audit($pdo, 'driver', $did, 'payout.requested', 'payout', $pid, ['amount' => $elig['balance']]);
            $msg = 'Requested $' . money($elig['balance']) . '. The office will send it.';
        }
    }
    header('Location: ' . url('driver/payout.php?msg=' . urlencode($msg) . ($isErr ? '&err=1' : '')));
    exit;
}
if (isset($_GET['msg'])) {
    $msg = (string)$_GET['msg'];
    $isErr = isset($_GET['err']);
}

$elig = EarningsService::payoutEligible($pdo, $did);
$balance = EarningsService::unpaidBalance($pdo, $did);
$interval = (int)setting($pdo, 'payout_interval_days', '7');

/*
  The service answers "not yet" without saying when. A driver being told no and
  not told when to come back is the same as being told nothing, so the date is
  worked out here from the same interval the service used.
*/
$st = $pdo->prepare('SELECT requested_at FROM driver_payouts WHERE driver_id = ? AND status IN ("requested","approved","paid") ORDER BY requested_at DESC LIMIT 1');
$st->execute([$did]);
$lastRequest = $st->fetch();
$nextEligible = null;
$daysToGo = null;
if (!$elig['eligible'] && $lastRequest) {
    $nextEligible = (int)strtotime((string)$lastRequest['requested_at']) + $interval * 86400;
    $daysToGo = (int)ceil(($nextEligible - time()) / 86400);
}

$st = $pdo->prepare('SELECT amount, status, requested_at, reviewed_at, paid_at, notes, destination_reference FROM driver_payouts WHERE driver_id = ? ORDER BY id DESC LIMIT 50');
$st->execute([$did]);
$rows = $st->fetchAll();
$paidOut = 0.0;
$lastPaidAt = null;
$turnbacks = 0;
foreach ($rows as $r) {
    if ((string)$r['status'] === 'paid') {
        $paidOut += (float)$r['amount'];
        $at = trim((string)($r['paid_at'] ?? '')) !== '' ? (string)$r['paid_at'] : (string)$r['requested_at'];
        if ($lastPaidAt === null || strtotime($at) > strtotime($lastPaidAt)) $lastPaidAt = $at;
    }
    if ((string)$r['status'] === 'rejected') $turnbacks++;
}

/* Plain wording for the four states a withdrawal passes through. */
$STATE = [
    'requested' => ['Waiting on the office', 'wait'],
    'approved' => ['Approved, waiting to be sent', 'wait'],
    'paid' => ['Sent to you', 'ok'],
    'rejected' => ['Turned down', 'bad'],
];

ob_start();
?>
<div class="pay-page">

  <header class="sheet-head">
    <p class="sheet-when"><span class="drv-k">Withdrawals</span> <?= e(strtoupper(date('D j M Y'))) ?></p>
    <h1 class="sheet-name">Payout</h1>
  </header>

  <?php if ($msg !== ''): ?>
    <div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>" role="status"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php /*
    The slip.

    A withdrawal is an instruction, and an instruction is a slip of paper with a
    tear-off edge and a figure on it. Drawing it that way says "this is the one
    thing you are deciding here" - the amount, where it goes, and the button.
    Everything else on the page is history.
  */ ?>
  <section class="slip<?= $elig['eligible'] ? ' is-ready' : ' is-wait' ?>">
    <span class="slip-perf" aria-hidden="true"></span>
    <div class="slip-in">
      <span class="slip-k"><?= $elig['eligible'] ? 'Ready to withdraw' : 'On your balance' ?></span>
      <strong class="slip-amt">$<?= money($balance) ?></strong>

      <?php if ($elig['eligible']): ?>
        <p class="slip-note">You can ask for this any time. Payouts run every <?= $interval ?> days.</p>
      <?php else: ?>
        <p class="slip-note">
          <?= e($elig['reason']) ?>
          <?php if ($daysToGo !== null && $daysToGo > 0): ?>
            You can ask again in <b><?= $daysToGo ?> <?= $daysToGo === 1 ? 'day' : 'days' ?></b>.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <?php if ($onFile !== ''): ?>
        <p class="slip-dest"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> Going to <b><?= e($onFile) ?></b></p>
      <?php endif; ?>

      <?php if ($elig['eligible']): ?>
        <form method="post" class="slip-form" data-once>
          <?= csrf_field() ?>
          <div class="drv-field">
            <label class="drv-label" for="destination">Where should it go?</label>
            <input id="destination" name="destination" class="drv-input" autocomplete="off"
                   placeholder="<?= $onFile !== '' ? $onFile : 'Chase ****4471, or your Zelle handle' ?>"
                   value="<?= e($onFile) ?>">
            <p class="drv-hint">A handle and last four digits only. Never a full card or account number.</p>
          </div>
          <button class="drv-btn drv-btn-gold slip-go">Request $<?= money($balance) ?></button>
        </form>
      <?php elseif ($nextEligible !== null): ?>
        <?php /* Nothing to press, so the page offers the date instead of a
                 disabled button pretending to be an action. */ ?>
        <p class="slip-when">
          <i class="fa-regular fa-clock" aria-hidden="true"></i>
          Ask again from <b><?= e(date('j M Y', $nextEligible)) ?></b>
        </p>
      <?php endif; ?>
    </div>
  </section>

  <?php /* The balance is already the figure on the slip above, so these three
         are history only. Repeating it under its own heading put the same
         number on one screen twice. */ ?>
  <div class="stat-grid is-3 pay-counts">
    <article class="stat is-quiet">
      <span class="stat-k">Taken out</span>
      <strong class="stat-v">$<?= money($paidOut) ?></strong>
      <span class="stat-s">sent to you so far</span>
    </article>
    <article class="stat is-quiet">
      <span class="stat-k">Last sent</span>
      <strong class="stat-v stat-v-date"><?= $lastPaidAt ? date('j M Y', (int)strtotime((string)$lastPaidAt)) : 'never' ?></strong>
      <span class="stat-s"><?= $lastPaidAt ? 'last withdrawal' : 'nothing sent yet' ?></span>
    </article>
    <article class="stat <?= $turnbacks > 0 ? 'is-warn' : 'is-quiet' ?>">
      <span class="stat-k">Turned down</span>
      <strong class="stat-v"><?= $turnbacks ?></strong>
      <span class="stat-s"><?= $turnbacks === 0 ? 'none on record' : 'check the reason below' ?></span>
    </article>
  </div>

  <?php if ($rows): ?>
    <section class="withdrawals">
      <header class="ride-day-h">
        <span class="ride-day-n">Past withdrawals</span>
        <i aria-hidden="true"></i>
        <span class="ride-day-c"><?= count($rows) ?></span>
      </header>
      <ul class="with-list">
        <?php foreach ($rows as $r):
          $st8 = $STATE[(string)$r['status']] ?? ['Withdrawn', 'wait'];
        ?>
          <li class="with-row is-<?= e($st8[1]) ?>">
            <span class="with-amt">$<?= money((float)$r['amount']) ?></span>
            <span class="with-body">
              <b class="with-state"><?= e($st8[0]) ?></b>
              <span class="with-when">
                Asked <?= e(date('j M Y', (int)strtotime((string)$r['requested_at']))) ?>
                <?php if (trim((string)($r['paid_at'] ?? '')) !== ''): ?>
                  · Sent <?= e(date('j M Y', (int)strtotime((string)$r['paid_at']))) ?>
                <?php endif; ?>
              </span>
              <?php if (trim((string)($r['destination_reference'] ?? '')) !== ''): ?>
                <span class="with-dest"><?= e((string)$r['destination_reference']) ?></span>
              <?php endif; ?>
              <?php /* A refusal the driver cannot act on is worse than none, so
                       the office's reason is passed on in full. */ ?>
              <?php if (trim((string)($r['notes'] ?? '')) !== ''): ?>
                <span class="with-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <?= e((string)$r['notes']) ?></span>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php else: ?>
    <div class="dash-empty">
      <p>You have not asked for a payout yet. Everything you earn sits on your balance until you do.</p>
    </div>
  <?php endif; ?>

  <a class="drv-btn drv-btn-line pay-go" href="<?= url('driver/earnings.php') ?>">See what I earned</a>

</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Payout | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';