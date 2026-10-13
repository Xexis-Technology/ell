<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
DriverPortal::require_cleared($pdo, $driver);
$did = (int)$driver['id'];
$msg = '';
$isErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $r = DispatchService::driverUpdateStatus($pdo, (int)($_POST['booking_id'] ?? 0), $did, (string)($_POST['new_status'] ?? ''));
    if (isset($r['error'])) {
        $msg = $r['error'];
        $isErr = true;
    } else {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([(int)($_POST['booking_id'] ?? 0)]);
        $b = $st->fetch();
        // The guest is told the trip moved on. Named for the step they just took,
        // because "Trip update: arrived" is not something anybody can act on.
        $phrases = [
            'on_the_way' => 'is on the way',
            'arrived' => 'has arrived',
            'at_pickup_location' => 'is waiting at the pickup',
            'on_board' => 'is on board',
            'finish' => 'has finished the trip',
        ];
        $step = (string)($_POST['new_status'] ?? '');
        $email = (string)($b['guest_email'] ?? '');
        if (!empty($b['customer_id'])) {
            $cu = $pdo->prepare('SELECT email FROM customers WHERE id = ? LIMIT 1');
            $cu->execute([(int)$b['customer_id']]);
            $email = (string)($cu->fetchColumn() ?: $email);
        }
        if ($email && isset($phrases[$step])) {
            NotificationService::bookingEmail($pdo, 'driver-status-update', $b, $email,
                $b['customer_id'], $b['customer_id'] ? 'customer' : 'guest',
                ['html' => '<p>Your chauffeur ' . e($phrases[$step]) . ' for booking <strong>' . e($b['booking_number']) . '</strong>.</p>']);
        }
        $msg = 'Updated. Booking ' . (string)$b['booking_number'] . ' is now ' . (DispatchLabels::step($step)['label'] ?? $step) . '.';
    }
    header('Location: ' . url('driver/rides.php?msg=' . urlencode($msg) . ($isErr ? '&err=1' : '')));
    exit;
}
if (isset($_GET['msg'])) {
    $msg = (string)$_GET['msg'];
    $isErr = isset($_GET['err']);
}

/*
  Every ride this driver has ever been given, not only the open ones.

  The old page filtered the dispatch to assigned/reassigned, so the moment a
  ride finished its dispatch moved to "completed" and the ride vanished - the
  page called itself My Rides and showed no rides once you had any. Dispatch
  rows are never deleted, so the history is all still there.
*/
$st = $pdo->prepare(
    'SELECT b.id, b.booking_number, b.status, b.guest_name, b.pickup_location, b.destination_location,
            b.pickup_date, b.pickup_time, b.passengers, b.luggage, b.service_type, b.trip_type,
            b.completed_at, e.driver_amount,
            v.make, v.model, v.plate, vc.name AS vehicle_class
       FROM dispatches d
       JOIN bookings b ON b.id = d.booking_id
       LEFT JOIN driver_earnings e ON e.booking_id = b.id AND e.driver_id = d.driver_id
       LEFT JOIN vehicles v ON v.id = d.vehicle_id
       LEFT JOIN vehicle_categories vc ON vc.id = v.category_id
      WHERE d.driver_id = ?
      ORDER BY b.pickup_date DESC, b.pickup_time DESC
      LIMIT 250'
);
$st->execute([$did]);
$rides = $st->fetchAll();

/* Extra stops, read once for the whole page. The old version ran two queries
   per ride inside the loop - two hundred queries for a long book. */
$ids = array_map(static fn($r) => (int)$r['id'], $rides);
$stops = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ss = $pdo->prepare("SELECT booking_id, location FROM booking_stops WHERE booking_id IN ($in) ORDER BY booking_id, stop_order");
    $ss->execute($ids);
    foreach ($ss->fetchAll() as $s) {
        $stops[(int)$s['booking_id']][] = (string)$s['location'];
    }
}
foreach ($rides as &$r) {
    $r['stops'] = $stops[(int)$r['id']] ?? [];
}
unset($r);

$open = array_values(array_filter($rides, static fn($r) => !in_array((string)$r['status'], ['finish', 'cancelled', 'refunded'], true)));
$done = array_values(array_filter($rides, static fn($r) => in_array((string)$r['status'], ['finish', 'cancelled', 'refunded'], true)));
$earned = 0.0;
foreach ($done as $r) {
    if ($r['driver_amount'] !== null) $earned += (float)$r['driver_amount'];
}

$today = date('Y-m-d');
$isToday = static fn(string $d): bool => $d === $today;
// `use` is required here: an arrow function captures the enclosing scope on its
// own, a `function` does not. Without it $today is undefined inside, today and
// yesterday quietly fall through to a date, and a PHP warning is printed straight
// into the middle of the page.
$dayLabel = static function (string $date) use ($today): string {
    if ($date === $today) return 'Today';
    if ($date === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('D j M', (int)strtotime($date));
};

ob_start();
?>
<div class="ride-page">

  <header class="sheet-head">
    <p class="sheet-when"><span class="drv-k">Your book</span> <?= e(strtoupper(date('D j M Y'))) ?></p>
    <h1 class="sheet-name">My rides</h1>
  </header>

  <?php if ($msg !== ''): ?>
    <div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>" role="status"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php if (!$rides): ?>
    <?php /* An empty book is an invitation, not an apology. */ ?>
    <div class="sheet sheet-empty-sheet">
      <div class="sheet-empty">
        <span class="sheet-empty-mark" aria-hidden="true"><i></i><i></i></span>
        <p class="sheet-empty-h">No rides yet</p>
        <p class="sheet-empty-sub">Dispatch sends work to this page. Nothing to do until then.</p>
      </div>
      <a class="drv-btn drv-btn-line sheet-go" href="<?= url('driver/dashboard.php') ?>">Back to my rides</a>
    </div>
  <?php else: ?>

    <?php /* Counts first, because "how much has this been worth" is the question
             a driver opens this page with. Open work is the thing to act on. */ ?>
    <div class="stat-grid is-3 ride-counts">
      <article class="stat <?= $open ? 'is-live' : 'is-quiet' ?>">
        <span class="stat-k">Still to do</span>
        <strong class="stat-v"><?= count($open) ?></strong>
        <span class="stat-s"><?= $open ? 'on your book' : 'nothing waiting' ?></span>
      </article>
      <article class="stat is-quiet">
        <span class="stat-k">Rides driven</span>
        <strong class="stat-v"><?= count($done) ?></strong>
        <span class="stat-s">on file</span>
      </article>
      <article class="stat is-quiet">
        <span class="stat-k">Paid for them</span>
        <strong class="stat-v">$<?= money($earned) ?></strong>
        <span class="stat-s">your share</span>
      </article>
    </div>

    <?php
    /*
      The book is a run of days, newest first, and each day's rides hang off one
      continuous rail. The rail is not decoration: order is what this page is
      for - what is next, what is done - and a driver reads the day top to bottom
      the way they read a timetable.
    */
    $days = [];
    foreach ($rides as $r) {
        $d = (string)$r['pickup_date'];
        $days[$d][] = $r;
    }
    foreach ($days as $date => $dayRides): ?>
      <section class="ride-day<?= $isToday((string)$date) ? ' is-today' : '' ?>">
        <header class="ride-day-h">
          <span class="ride-day-n"><?= e($dayLabel((string)$date)) ?></span>
          <i aria-hidden="true"></i>
          <span class="ride-day-c"><?= count($dayRides) ?> <?= count($dayRides) === 1 ? 'ride' : 'rides' ?></span>
        </header>

        <ol class="ride-rail">
          <?php foreach ($dayRides as $r):
            $L = DispatchLabels::step((string)$r['status']);
            $nextStep = DispatchLabels::next((string)$r['status']);
          ?>
            <li class="ride-row is-<?= e($L['tone']) ?><?= $L['live'] ? ' is-live' : '' ?>">
              <span class="ride-time">
                <?= e(substr((string)$r['pickup_time'], 0, 5)) ?>
                <?php if (trim((string)$r['completed_at']) !== ''): ?>
                  <em><?= e(substr((string)$r['completed_at'], 11, 5)) ?> back</em>
                <?php endif; ?>
              </span>
              <span class="ride-node" aria-hidden="true"><i class="fa-solid <?= e($L['icon']) ?>"></i></span>

              <div class="ride-body">
                <div class="ride-top">
                  <b class="ride-no"><?= e((string)$r['booking_number']) ?></b>
                  <span class="ride-state"><?= e($L['label']) ?></span>
                </div>

                <?php if (trim((string)($r['guest_name'] ?? '')) !== ''): ?>
                  <p class="ride-guest"><?= e((string)$r['guest_name']) ?></p>
                <?php endif; ?>

                <?php /* The route as a line, not two stacked fields - pickup and
                         drop-off are one journey and read as one. */ ?>
                <p class="ride-route">
                  <span><?= e((string)$r['pickup_location']) ?></span>
                  <i class="fa-solid fa-arrow-right-long" aria-hidden="true"></i>
                  <span><?= e((string)$r['destination_location']) ?></span>
                </p>

                <?php if ($r['stops']): ?>
                  <p class="ride-stops">
                    <?php foreach ($r['stops'] as $i => $stop): ?>
                      <span><b>Via</b> <?= e($stop) ?></span>
                    <?php endforeach; ?>
                  </p>
                <?php endif; ?>

                <p class="ride-facts">
                  <span><i class="fa-solid fa-user-group" aria-hidden="true"></i> <?= (int)$r['passengers'] ?> <?= (int)$r['passengers'] === 1 ? 'guest' : 'guests' ?></span>
                  <span><i class="fa-solid fa-suitcase" aria-hidden="true"></i> <?= (int)$r['luggage'] ?> bags</span>
                  <?php $car = trim(((string)($r['make'] ?? '')) . ' ' . ((string)($r['model'] ?? ''))); ?>
                  <?php if ($car !== ''): ?>
                    <span><i class="fa-solid fa-car" aria-hidden="true"></i> <?= e($car) ?><?php if (trim((string)($r['plate'] ?? '')) !== ''): ?> <em><?= e((string)$r['plate']) ?></em><?php endif; ?></span>
                  <?php endif; ?>
                  <?php if (trim((string)($r['driver_amount'] ?? '')) !== ''): ?>
                    <span class="ride-amt">$<?= money((float)$r['driver_amount']) ?></span>
                  <?php endif; ?>
                </p>
              </div>

              <?php /* One next step, named for what it does. The flow only ever
                       allows a single step forward, so a dropdown of every
                       status offered five things that four of them refused. */ ?>
              <?php if ($nextStep): ?>
                <form method="post" class="ride-act" data-once>
                  <?= csrf_field() ?>
                  <input type="hidden" name="booking_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="new_status" value="<?= e($nextStep['to']) ?>">
                  <button type="submit" class="drv-btn <?= $nextStep['final'] ? 'drv-btn-gold' : 'drv-btn-line' ?>">
                    <?= e($nextStep['label']) ?>
                  </button>
                </form>
              <?php else: ?>
                <span class="ride-closed"><?= e($L['closed']) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
<?php
$content = ob_get_clean();
$pageTitle = 'My rides | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';