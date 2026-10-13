<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
DriverPortal::require_cleared($pdo, $driver);
$did = (int)$driver['id'];

/*
  A dashboard answers questions. Each block below is here because a chauffeur
  actually asks it - what is my next job, am I legal to drive, has this week
  paid, what did I earn, when did I last pull up - and each answer comes from a
  row that already exists.

  Nothing here is modelled on a score. There is no guest rating table and no
  driving-score column in this schema, and dispatches.status='reassigned' means
  the office swapped the driver rather than that anyone refused a job, so an
  "acceptance rate" built from it would be a confident lie. Every figure below
  is countable.
*/

// ---- the sheet ------------------------------------------------------------
$st = $pdo->prepare('SELECT b.booking_number, b.pickup_date, b.pickup_time, b.pickup_location, b.destination_location, b.guest_name, b.status, b.id FROM bookings b JOIN dispatches d ON d.booking_id = b.id WHERE d.driver_id = ? AND d.status IN ("assigned","reassigned") ORDER BY b.pickup_date DESC, b.pickup_time DESC LIMIT 50');
$st->execute([$did]);
$rides = $st->fetchAll();
$active = null;
$next = null;
foreach ($rides as $r) {
    if (in_array($r['status'], ['assigned', 'on_the_way', 'arrived', 'at_pickup_location', 'on_board'], true) && !$active) $active = $r;
}
foreach (array_reverse($rides) as $r) {
    if (!in_array($r['status'], ['finish', 'cancelled', 'refunded'], true) && strtotime($r['pickup_date'] . ' ' . $r['pickup_time']) >= time() && !$next) $next = $r;
}
$job = $active ?? $next;
$openJobs = count(array_filter($rides, static fn($r) => !in_array($r['status'], ['finish', 'cancelled', 'refunded'], true)));

// ---- money ----------------------------------------------------------------
$sum = static function (PDO $pdo, int $id, string $where): array {
    $st = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(driver_amount),0) t FROM driver_earnings WHERE driver_id = ? {$where}");
    $st->execute([$id]);
    return (array)$st->fetch();
};
$life = $sum($pdo, $did, '');
$week = $sum($pdo, $did, 'AND earned_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
$month = $sum($pdo, $did, 'AND earned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
$perRide = (int)$life['c'] > 0 ? (float)$life['t'] / (int)$life['c'] : 0.0;

$balance = EarningsService::unpaidBalance($pdo, $did);
$elig = EarningsService::payoutEligible($pdo, $did);
$payoutEvery = (int)setting($pdo, 'payout_interval_days', '7');

$st = $pdo->prepare('SELECT amount, status, requested_at FROM driver_payouts WHERE driver_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$did]);
$lastPayout = $st->fetch();

// ---- standing -------------------------------------------------------------
// The session copy of the driver has no created_at or dates on it, so anything
// read from $driver rather than the row silently came out blank.
$fresh = DriverPortal::fresh($pdo, $did);

$jobsCancelled = (int)$pdo->query(
    "SELECT COUNT(*) FROM dispatches d JOIN bookings b ON b.id = d.booking_id
      WHERE d.driver_id = " . $did . " AND b.status IN ('cancelled','refunded')"
)->fetchColumn();

$st = $pdo->prepare(
    'SELECT b.pickup_date, b.pickup_time, b.pickup_location, b.destination_location,
            b.guest_name, b.status, e.driver_amount
       FROM dispatches d
       JOIN bookings b ON b.id = d.booking_id
       LEFT JOIN driver_earnings e ON e.booking_id = b.id AND e.driver_id = d.driver_id
      WHERE d.driver_id = ?
      ORDER BY b.pickup_date DESC, b.pickup_time DESC
      LIMIT 6'
);
$st->execute([$did]);
$recent = $st->fetchAll();

// ---- the car on this job --------------------------------------------------
$car = null;
if ($job) {
    $vs = $pdo->prepare('SELECT v.make, v.model, v.plate, v.year, v.status FROM vehicles v
                          JOIN dispatches d ON d.vehicle_id = v.id WHERE d.booking_id = ? LIMIT 1');
    $vs->execute([$job['id']]);
    $car = $vs->fetch() ?: null;
}

/*
  Tone and the bar width for a document with an expiry.

  A driver reads these as "can I still be on the road", so the bar is time left
  out of a year and the colour is the answer, not decoration.
*/
$due = static function (?int $days, int $warn, int $bad): array {
    if ($days === null) return ['is-none', 0, 'not on file'];
    if ($days < 0) return ['is-bad', 3, abs($days) . 'd overdue'];
    if ($days <= $bad) return ['is-bad', max(3, (int)round($days / 365 * 100)), $days . 'd left'];
    if ($days <= $warn) return ['is-warn', max(3, (int)round($days / 365 * 100)), $days . 'd left'];
    return ['is-ok', max(3, (int)round($days / 365 * 100)), $days . 'd left'];
};
$shown = static function (?string $v): string {
    return ($v === null || trim((string)$v) === '') ? '' : date('j M Y', (int)strtotime((string)$v));
};
$aged = static function (?string $v): string {
    $t = $v ? strtotime((string)$v) : false;
    if ($t === false) return 'not on file';
    $d = (int)floor((time() - $t) / 86400);
    if ($d < 1) return 'today';
    if ($d < 31) return $d . 'd ago';
    if ($d < 365) return intdiv($d, 30) . 'mo ago';
    return number_format($d / 365, 1) . 'y ago';
};

/*
  Room left in a year, for a document that was last done rather than that runs
  out. A check that is a week old is a full bar and one from last year is nearly
  empty - the same reading as every other bar on the page, where full always
  means there is room before it needs attention. Filling it in proportion to age
  instead made a stale background check look like the healthiest thing here.
*/
$freshPct = static function (?string $v): int {
    $t = $v ? strtotime((string)$v) : false;
    if ($t === false) return 0;
    $age = (int)floor((time() - $t) / 86400);
    return max(3, 100 - (int)round(min(100, $age / 365 * 100)));
};

/* How long they have been on the crew, in the units people say it out loud. */
$tenure = static function (mixed $since): string {
    $ts = is_string($since) ? strtotime($since) : false;
    if ($ts === false || $ts > time()) return '—';
    $months = (int)floor((time() - $ts) / 2629800);
    if ($months < 1) return 'new';
    $y = intdiv($months, 12);
    $m = $months % 12;
    if ($y < 1) return $m . ($m === 1 ? ' month' : ' months');
    return $y . ($y === 1 ? ' yr ' : ' yrs ') . $m . ' mo';
};

$name = trim((string)($driver['name'] ?? ''));
$hour = (int)date('G');
$daypart = $hour < 5 ? 'late' : ($hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening'));
$greeting = ['late' => 'Still up', 'morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Good evening'][$daypart];
$clock = static fn($t): string => substr((string)$t, 0, 5);

/* Section rule: label, hairline to the edge, note. A plain heading again - the
   phone used to get one of these per section as a tap-to-open row, which meant
   five closed headers stacked down the screen before the driver had seen a
   single number. Mobile now gets four cards that matter instead. */
$section = static function (string $id, string $title, string $note = '', int $count = 0): string {
    ob_start(); ?>
    <div class="dash-sec-h"><span><?= e($title) ?><?php if ($count > 0): ?><b><?= $count ?></b><?php endif; ?></span><i aria-hidden="true"></i><?php if ($note !== ''): ?><em><?= e($note) ?></em><?php endif; ?></div>
    <?php
    return (string)ob_get_clean();
};
// Sections are flat again, so there is no block to close.
$sectionEnd = static function (): string {
    return '';
};

$statusTone = static function (string $s): string {
    return ['active' => 'is-on', 'pending' => 'is-wait', 'suspended' => 'is-bad', 'inactive' => 'is-none'][$s] ?? 'is-none';
};

/* Worked out once, up here, because the phone and the desktop now read the same
   four figures from different views of them. */
[$licTone, $licPct, $licNote] = $due(days_until($fresh['license_expiry'] ?? null), 45, 30);
[$medTone, $medPct, $medNote] = $due(days_until($fresh['medical_cert_expiry'] ?? null), 60, 30);
$bgTone = ($t = strtotime((string)($fresh['background_check_date'] ?? ''))) === false ? 'is-none' : ((time() - $t) > 31536000 ? 'is-warn' : 'is-ok');
$drugTone = ($t = strtotime((string)($fresh['drug_test_date'] ?? ''))) === false ? 'is-none' : ((time() - $t) > 31536000 ? 'is-warn' : 'is-ok');

$rejSt = $pdo->prepare("SELECT COUNT(*) FROM driver_documents WHERE driver_id = ? AND status = 'rejected'");
$rejSt->execute([$did]);
$rejectedDocs = (int)$rejSt->fetchColumn();

ob_start();
?>
<div class="sheet-page">

  <header class="sheet-head">
    <p class="sheet-when"><span class="drv-k"><?= e($greeting) ?></span> <?= e(strtoupper(date('D j M'))) ?></p>
    <h1 class="sheet-name"><?= e($name) ?></h1>
  </header>

  <?php /* ---- the sheet: the one thing to act on ---- */ ?>
  <section class="sheet<?= $active ? ' is-live' : '' ?><?= $job ? '' : ' is-blank' ?>">
    <header class="sheet-bar">
      <span class="sheet-k"><?= $active ? 'On a ride now' : ($next ? 'Next on the sheet' : 'The sheet') ?></span>
      <?php if ($job): ?><span class="sheet-no"><?= e((string)$job['booking_number']) ?></span><?php endif; ?>
    </header>

    <?php if ($job): ?>
      <div class="sheet-status">
        <?php if ($active): ?>
          <span class="drv-state is-on"><?= e(ucwords(str_replace('_', ' ', (string)$job['status']))) ?></span>
          <?php if (trim((string)($job['guest_name'] ?? '')) !== ''): ?>
            <span class="sheet-for">for <?= e((string)$job['guest_name']) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span class="drv-state is-wait"><?= e(date('D j M', (int)strtotime((string)$job['pickup_date']))) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($car): ?>
        <p class="sheet-car">
          <i class="fa-solid fa-car" aria-hidden="true"></i>
          <?= e(trim((string)$car['make'] . ' ' . (string)$car['model'])) ?>
          <em><?= e((string)$car['plate']) ?></em>
        </p>
      <?php endif; ?>

      <div class="sheet-route">
        <div class="sheet-stop">
          <span class="sheet-time"><?= e($clock($job['pickup_time'])) ?></span>
          <span class="sheet-rail" aria-hidden="true"><i class="sheet-pin"></i></span>
          <span class="sheet-where"><b>Pick up</b><?= e((string)$job['pickup_location']) ?></span>
        </div>
        <div class="sheet-leg" aria-hidden="true"><i></i></div>
        <div class="sheet-stop">
          <span class="sheet-time"><?= e($clock($job['pickup_time'])) ?></span>
          <span class="sheet-rail is-end" aria-hidden="true"><i class="sheet-pin"></i></span>
          <span class="sheet-where"><b>Drop off</b><?= e((string)$job['destination_location']) ?></span>
        </div>
      </div>

      <a class="drv-btn drv-btn-gold sheet-go" href="<?= url('driver/rides.php') ?>"><?= $active ? 'Open the ride' : 'Open the job' ?></a>

    <?php else: ?>
      <?php /*
        An empty sheet is the common case, so it gets designed rather than
        stubbed. The previous version drew two ghost rows with dashes for
        times, which read as two half-broken records instead of one clear
        "nothing here". One unfinished length of rail, one sentence, and the
        one fact worth knowing while you wait.
      */ ?>
      <div class="sheet-empty">
        <span class="sheet-empty-mark" aria-hidden="true"><i></i><i></i></span>
        <p class="sheet-empty-h">Nothing on the sheet</p>
        <p class="sheet-empty-sub">Dispatch sends the next job here. There is nothing to do until then.</p>
        <?php if ($fresh['clearance_submitted_at']): ?>
          <p class="sheet-empty-foot">
            Paperwork sent <?= e(date('j M Y', (int)strtotime((string)$fresh['clearance_submitted_at']))) ?>
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php /*
    Phone only.

    A chauffeur at a kerb needs four answers and nothing else: is there a job
    (the sheet above), can I legally drive, when can I get paid, and has this
    week been worth it. The other eleven cards are desk figures - lifetime
    totals, per-ride averages, tenure, a cancellation count - and they buried
    those four under a scroll. They stay on the desktop, which has the room.
  */ ?>
  <div class="dash-keys">
    <?php if ($rejectedDocs > 0): ?>
      <a class="dash-need" href="<?= url('driver/notification.php?kind=action') ?>">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span>
          <b><?= $rejectedDocs === 1 ? 'A photo was turned down' : $rejectedDocs . ' photos were turned down' ?></b>
          Take them again and send clearer ones.
        </span>
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </a>
    <?php endif; ?>

    <div class="dash-keys-h"><span>Where you stand</span><i aria-hidden="true"></i></div>
    <div class="stat-grid is-keys">
      <article class="stat <?= $licTone ?>">
        <span class="stat-k">Licence</span>
        <strong class="stat-v stat-v-date"><?= $shown($fresh['license_expiry'] ?? null) ?: 'not on file' ?></strong>
        <span class="stat-s"><?= e($licNote) ?></span>
        <div class="stat-bar"><i style="width:<?= $licPct ?>%"></i></div>
      </article>
      <article class="stat <?= $balance > 0 ? 'is-live' : 'is-quiet' ?>">
        <span class="stat-k">Your balance</span>
        <strong class="stat-v">$<?= money($balance) ?></strong>
        <span class="stat-s"><?= $balance > 0 ? 'ready to withdraw' : 'nothing waiting' ?></span>
      </article>
      <article class="stat <?= $elig['eligible'] ? 'is-on' : 'is-quiet' ?>">
        <span class="stat-k">Next payout</span>
        <strong class="stat-v stat-v-word"><?= $elig['eligible'] ? 'Available' : 'Not yet' ?></strong>
        <span class="stat-s"><?= e($elig['eligible'] ? 'every ' . $payoutEvery . ' days' : $elig['reason']) ?></span>
      </article>
      <article class="stat is-quiet">
        <span class="stat-k">This week</span>
        <strong class="stat-v">$<?= money($week['t']) ?></strong>
        <span class="stat-s"><?= (int)$week['c'] ?> <?= (int)$week['c'] === 1 ? 'ride' : 'rides' ?></span>
      </article>
    </div>

    <div class="dash-keys-links">
      <a href="<?= url('driver/notification.php') ?>">Notices</a>
      <a href="<?= url('driver/rides.php') ?>">All my rides</a>
      <a href="<?= url('driver/settings.php') ?>">My details</a>
    </div>
  </div>

  <?php /* The desk view. Hidden below 900px in favour of .dash-keys above. */ ?>
<div class="dash-desk">

<?php /* ---- standing: the documents that decide whether you may drive ---- */ ?>
  <?= $section('standing', 'Your standing', 'checked against your file', 4) ?>
  <div class="stat-grid is-4">
    <article class="stat <?= $licTone ?>">
      <span class="stat-k">Driving licence</span>
      <strong class="stat-v stat-v-date"><?= $shown($fresh['license_expiry'] ?? null) ?: 'not on file' ?></strong>
      <span class="stat-s"><?= e($licNote) ?></span>
      <span class="stat-f"><?= e(trim((string)($fresh['license_number'] ?? '')) ?: 'no number on file') ?></span>
      <div class="stat-bar"><i style="width:<?= $licPct ?>%"></i></div>
    </article>
    <article class="stat <?= $medTone ?>">
      <span class="stat-k">Medical certificate</span>
      <strong class="stat-v stat-v-date"><?= $shown($fresh['medical_cert_expiry'] ?? null) ?: 'not on file' ?></strong>
      <span class="stat-s"><?= e($medNote) ?></span>
      <span class="stat-f">renew yearly</span>
      <div class="stat-bar"><i style="width:<?= $medPct ?>%"></i></div>
    </article>
    <article class="stat <?= $bgTone ?>">
      <span class="stat-k">Background check</span>
      <strong class="stat-v stat-v-date"><?= $shown($fresh['background_check_date'] ?? null) ?: 'not on file' ?></strong>
      <span class="stat-s"><?= e($aged($fresh['background_check_date'] ?? null)) ?></span>
      <span class="stat-f">renew yearly</span>
      <div class="stat-bar"><i style="width:<?= $freshPct($fresh['background_check_date'] ?? null) ?>%"></i></div>
    </article>
    <article class="stat <?= $drugTone ?>">
      <span class="stat-k">Drug test</span>
      <strong class="stat-v stat-v-date"><?= $shown($fresh['drug_test_date'] ?? null) ?: 'not on file' ?></strong>
      <span class="stat-s"><?= e($aged($fresh['drug_test_date'] ?? null)) ?></span>
      <span class="stat-f">renew yearly</span>
      <div class="stat-bar"><i style="width:<?= $freshPct($fresh['drug_test_date'] ?? null) ?>%"></i></div>
    </article>
  </div>
  <?= $sectionEnd() ?>

  <?php /* ---- work ---- */ ?>
  <?= $section('work', 'Your work', 'every ride you have been paid for', 4) ?>
  <div class="stat-grid is-4">
    <article class="stat is-live">
      <span class="stat-k">Last 7 days</span>
      <strong class="stat-v"><?= (int)$week['c'] ?></strong>
      <span class="stat-unit"><?= (int)$week['c'] === 1 ? 'ride' : 'rides' ?></span>
      <span class="stat-s">$<?= money($week['t']) ?> earned</span>
    </article>
    <article class="stat is-live">
      <span class="stat-k">Last 30 days</span>
      <strong class="stat-v"><?= (int)$month['c'] ?></strong>
      <span class="stat-unit"><?= (int)$month['c'] === 1 ? 'ride' : 'rides' ?></span>
      <span class="stat-s">$<?= money($month['t']) ?> earned</span>
    </article>
    <article class="stat is-quiet">
      <span class="stat-k">Lifetime</span>
      <strong class="stat-v"><?= (int)$life['c'] ?></strong>
      <span class="stat-unit"><?= (int)$life['c'] === 1 ? 'ride' : 'rides' ?></span>
      <span class="stat-s">$<?= money($life['t']) ?> total</span>
    </article>
    <article class="stat is-quiet">
      <span class="stat-k">Per ride</span>
      <strong class="stat-v">$<?= money($perRide) ?></strong>
      <span class="stat-unit">average</span>
      <span class="stat-s">your share, lifetime</span>
    </article>
  </div>
  <?= $sectionEnd() ?>

  <?php /* ---- where you stand ---- */ ?>
  <?= $section('stand', 'Where you stand', '', 4) ?>
  <div class="stat-grid is-4">
    <article class="stat <?= $openJobs > 0 ? 'is-live' : 'is-quiet' ?>">
      <span class="stat-k">Jobs on the sheet</span>
      <strong class="stat-v"><?= $openJobs ?></strong>
      <span class="stat-s"><?= $openJobs === 0 ? 'nothing waiting' : ($active ? 'one running now' : 'waiting to start') ?></span>
    </article>
    <article class="stat <?= $jobsCancelled > 0 ? 'is-warn' : 'is-quiet' ?>">
      <span class="stat-k">Jobs cancelled</span>
      <strong class="stat-v"><?= $jobsCancelled ?></strong>
      <span class="stat-s">on your dispatch history</span>
    </article>
    <article class="stat is-quiet">
      <span class="stat-k">On the crew</span>
      <strong class="stat-v stat-v-word"><?= e($tenure($fresh['created_at'] ?? null)) ?></strong>
      <span class="stat-s">since <?= e(date('M Y', (int)strtotime((string)($fresh['created_at'] ?? 'now')))) ?></span>
    </article>
    <article class="stat <?= $statusTone((string)$fresh['status']) ?>">
      <span class="stat-k">Account</span>
      <strong class="stat-v stat-v-word"><?= e(ucfirst((string)$fresh['status'])) ?></strong>
      <span class="stat-s"><?= $fresh['clearance_submitted_at'] ? 'paperwork sent ' . e(date('j M Y', (int)strtotime((string)$fresh['clearance_submitted_at']))) : 'paperwork not sent yet' ?></span>
    </article>
  </div>
  <?= $sectionEnd() ?>

  <?php /* ---- money ---- */ ?>
  <?= $section('money', 'Money', 'your share, before the house cut', 3) ?>
  <div class="stat-grid is-3">
    <article class="stat is-live">
      <span class="stat-k">On your balance</span>
      <strong class="stat-v">$<?= money($balance) ?></strong>
      <span class="stat-s"><?= $balance > 0 ? 'ready when you are' : 'nothing waiting' ?></span>
    </article>
    <article class="stat <?= $elig['eligible'] ? 'is-on' : 'is-none' ?>">
      <span class="stat-k">Next payout</span>
      <strong class="stat-v stat-v-word"><?= $elig['eligible'] ? 'Available' : 'Not yet' ?></strong>
      <span class="stat-s"><?= e($elig['eligible'] ? 'every ' . $payoutEvery . ' days' : $elig['reason']) ?></span>
    </article>
    <article class="stat is-quiet">
      <span class="stat-k">Last payout</span>
      <strong class="stat-v stat-v-word"><?= $lastPayout ? '$' . money((float)$lastPayout['amount']) : '—' ?></strong>
      <span class="stat-s"><?= $lastPayout ? e(ucfirst((string)$lastPayout['status']) . ' · ' . date('j M Y', (int)strtotime((string)$lastPayout['requested_at']))) : 'never requested' ?></span>
    </article>
  </div>
  <?php if ($elig['eligible']): ?>
    <a class="drv-btn drv-btn-line dash-wide" href="<?= url('driver/payout.php') ?>">Request payout</a>
  <?php endif; ?>
  <?= $sectionEnd() ?>

  <?php /* ---- recent work ---- */ ?>
  <?= $section('recent', 'Recent rides', 'newest first', count($recent)) ?>
  <?php if ($recent): ?>
    <ul class="dash-list">
      <?php foreach ($recent as $r):
        $st2 = (string)$r['status'];
        $dead = in_array($st2, ['cancelled', 'refunded'], true);
        $tone = in_array($st2, ['finish', 'completed'], true) ? 'is-ok' : ($dead ? 'is-bad' : 'is-wait');
        // A cancelled job shows no figure even if an earnings row survives against
        // it. Money next to CANCELLED reads as money the driver earned.
        $amt = (!$dead && $r['driver_amount'] !== null) ? '$' . money((float)$r['driver_amount']) : '—';
      ?>
        <li class="dash-row <?= $tone ?>">
          <span class="dash-when"><?= e(date('j M', (int)strtotime((string)$r['pickup_date']))) ?><em><?= e($clock($r['pickup_time'])) ?></em></span>
          <span class="dash-where">
            <b><?= e((string)$r['pickup_location']) ?></b>
            <i class="fa-solid fa-arrow-right-long" aria-hidden="true"></i>
            <b><?= e((string)$r['destination_location']) ?></b>
          </span>
          <span class="dash-who"><?= e(trim((string)($r['guest_name'] ?? '')) ?: '—') ?></span>
          <span class="dash-state"><?= e(ucwords(str_replace('_', ' ', $st2))) ?></span>
          <span class="dash-amt"><?= e($amt) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <a class="drv-btn drv-btn-quiet dash-wide" href="<?= url('driver/rides.php') ?>">See all my rides</a>
  <?php else: ?>
    <div class="dash-empty">
      <p>No rides on your history yet. The first one you take will show up here.</p>
    </div>
  <?php endif; ?>

</div><!-- /.dash-desk -->

</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Driver dashboard | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';