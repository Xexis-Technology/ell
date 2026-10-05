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
    $id = (int)($_POST['id'] ?? 0);
    if ($op === 'status' && $id) {
        $want = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $st = $pdo->prepare('SELECT name, status FROM customers WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            $msg = 'That client is not on file.';
            $isErr = true;
        } elseif ($c['status'] === $want) {
            $msg = $c['name'] . ' is already ' . $want . '.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute([$want, $id]);
            audit($pdo, 'admin', (int)$admin['id'], 'customer.status', 'customer', $id, ['status' => $want]);
            $msg = $c['name'] . ' is now ' . ($want === 'active' ? 'active again' : 'suspended') . '.';
        }
    }
    header('Location: ' . url('admin/customers.php?' . http_build_query(array_filter([
        'c' => $id ?: null, 'q' => trim((string)($_POST['q'] ?? '')), 'msg' => $msg,
    ]) + ($isErr ? ['err' => 1] : []))));
    exit;
}

if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$isErr = isset($_GET['err']);
$q = trim($_GET['q'] ?? '');

// ---- index ---------------------------------------------------------------
$sqlIndex = 'SELECT c.id, c.name, c.email, c.phone, c.status, c.created_at,
      (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS rides,
      (SELECT COALESCE(SUM(b.total), 0) FROM bookings b WHERE b.customer_id = c.id) AS spend,
      (SELECT MAX(b.pickup_date) FROM bookings b WHERE b.customer_id = c.id AND b.pickup_date <= CURDATE()) AS last_ride,
      (SELECT MIN(b.pickup_date) FROM bookings b WHERE b.customer_id = c.id AND b.pickup_date >= CURDATE()) AS next_ride
    FROM customers c';
$params = [];
if ($q !== '') {
    $sqlIndex .= ' WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?';
    $params = ["%$q%", "%$q%", "%$q%"];
}
$sqlIndex .= ' ORDER BY spend DESC, c.name ASC LIMIT 200';
$st = $pdo->prepare($sqlIndex);
$st->execute($params);
$clients = $st->fetchAll();

// ---- selected client -----------------------------------------------------
$selId = (int)($_GET['c'] ?? 0);
if ($selId > 0) {
    $found = null;
    foreach ($clients as $c) if ((int)$c['id'] === $selId) { $found = $c; break; }
    if ($found === null && $q === '') {
        $st = $pdo->prepare('SELECT c.id, c.name, c.email, c.phone, c.status, c.created_at,
            (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS rides,
            (SELECT COALESCE(SUM(b.total), 0) FROM bookings b WHERE b.customer_id = c.id) AS spend,
            (SELECT MAX(b.pickup_date) FROM bookings b WHERE b.customer_id = c.id AND b.pickup_date <= CURDATE()) AS last_ride,
            (SELECT MIN(b.pickup_date) FROM bookings b WHERE b.customer_id = c.id AND b.pickup_date >= CURDATE()) AS next_ride
          FROM customers c WHERE c.id = ? LIMIT 1');
        $st->execute([$selId]);
        $found = $st->fetch() ?: null;
    }
    $client = $found;
} else {
    $client = $clients[0] ?? null;
}

$rides = [];
$upcoming = 0;
$unpaid = 0.0;
$unpaidRides = 0;
if ($client) {
    $st = $pdo->prepare('SELECT id, booking_number, pickup_date, pickup_time, pickup_location, destination_location,
        passengers, luggage, status, payment_status, total, service_type
        FROM bookings WHERE customer_id = ? ORDER BY pickup_date DESC, pickup_time DESC');
    $st->execute([(int)$client['id']]);
    $rides = $st->fetchAll();
    $today = date('Y-m-d');
    foreach ($rides as $r) {
        if ($r['pickup_date'] >= $today && !in_array($r['status'], ['finish', 'cancelled', 'refunded'], true)) $upcoming++;
        if (!in_array($r['payment_status'], ['paid', 'refunded'], true)) {
            $unpaid += (float)$r['total'];
            $unpaidRides++;
        }
    }
}

// ---- header figures (whole book, filtered view) --------------------------
// COUNT(*) over a LEFT JOIN counts joined rows, not clients, so it must be
// DISTINCT or a customer with 7 rides reads as 7 customers.
$st = $pdo->prepare('SELECT COUNT(DISTINCT c.id) c, COALESCE(SUM(b.total), 0) s, COUNT(b.id) r FROM customers c LEFT JOIN bookings b ON b.customer_id = c.id'
    . ($q !== '' ? ' WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?' : ''));
$st->execute($params);
$tot = $st->fetch();

ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="cu-head">
  <div>
    <p class="eyebrow">Customers</p>
    <h1 class="font-display">Client book</h1>
    <p class="cu-sub">Everyone who has booked with Exotic Lane, and what they have ridden with us. Pick a name to open their ledger.</p>
  </div>
  <p class="cu-figures">
    <span><b><?= (int)$tot['c'] ?></b><?= (int)$tot['c'] === 1 ? ' client' : ' clients' ?></span>
    <span><b><?= (int)$tot['r'] ?></b> rides</span>
    <span><b>$<?= money($tot['s']) ?></b> billed<?= $q !== '' ? ' in this search' : '' ?></span>
  </p>
</header>

<div class="cu-split">
  <nav class="cu-index" aria-label="Client index">
    <form method="get" class="cu-search" action="<?= url('admin/customers.php') ?>">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email or phone" aria-label="Search clients">
      <?php if ($client): ?><input type="hidden" name="c" value="<?= (int)$client['id'] ?>"><?php endif; ?>
      <button type="submit">Search</button>
      <?php if ($q !== ''): ?><a href="<?= url('admin/customers.php' . ($client ? '?c=' . (int)$client['id'] : '')) ?>" class="cu-clear">Clear</a><?php endif; ?>
    </form>

    <?php if (!$clients): ?>
      <p class="cu-empty"><?= $q !== '' ? 'No client matches &ldquo;' . e($q) . '&rdquo;.' : 'No clients yet.' ?></p>
      <p class="cu-empty-sub"><?= $q !== '' ? 'Try part of an email or a phone number.' : 'Clients appear here as soon as someone books a ride.' ?></p>
    <?php else: ?>
      <ul class="cu-list">
        <?php foreach ($clients as $c):
          $isSel = $client && (int)$client['id'] === (int)$c['id']; ?>
        <li>
          <a class="cu-item<?= $isSel ? ' is-sel' : '' ?>" href="<?= url('admin/customers.php?' . http_build_query(array_filter(['c' => (int)$c['id'], 'q' => $q]))) ?>"
            <?= $isSel ? 'aria-current="true"' : '' ?>>
            <span class="cu-item-top">
              <b class="cu-item-name"><?= e($c['name']) ?></b>
              <?php if ($c['status'] === 'inactive'): ?><span class="pill pill-red">suspended</span><?php endif; ?>
            </span>
            <span class="cu-item-meta"><?= e($c['email']) ?></span>
            <span class="cu-item-figures">
              <span class="cu-rides"><?= (int)$c['rides'] ?> ride<?= (int)$c['rides'] === 1 ? '' : 's' ?></span>
              <span class="cu-spend">$<?= money($c['spend']) ?></span>
            </span>
            <?php if ($c['next_ride']): ?><span class="cu-item-next">Next <?= e(date('j M', strtotime((string)$c['next_ride']))) ?></span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </nav>

  <?php if (!$client): ?>
    <section class="cu-sheet cu-sheet-empty">
      <p class="cu-empty-t">No client to show.</p>
      <p><?= $q !== '' ? 'Nothing in the book matches that search yet.' : 'Once someone books a ride, their ledger opens here.' ?></p>
    </section>
  <?php else:
    $cid = (int)$client['id'];
    $initials = '';
    foreach (preg_split('/\s+/', trim((string)$client['name'])) ?: [] as $w) if ($w !== '') $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    $inactive = $client['status'] === 'inactive';
  ?>
  <section class="cu-sheet">
    <header class="cu-id">
      <span class="cu-avatar" aria-hidden="true"><?= e($initials !== '' ? $initials : '?') ?></span>
      <div class="cu-id-txt">
        <h2 class="cu-name"><?= e($client['name']) ?></h2>
        <p class="cu-contact">
          <a href="mailto:<?= e($client['email']) ?>"><?= e($client['email']) ?></a><?php if ($client['phone']): ?><span class="cu-contact-nb">&middot; <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string)$client['phone'])) ?>"><?= e($client['phone']) ?></a></span><?php endif; ?>
        </p>
        <p class="cu-joined">On the book since <?= e(date('j F Y', strtotime((string)$client['created_at']))) ?></p>
      </div>
      <div class="cu-id-actions">
        <?php if ($inactive): ?><span class="pill pill-red">suspended</span><?php else: ?><span class="pill pill-green">active</span><?php endif; ?>
      </div>
    </header>

    <div class="cu-stats">
      <div class="cu-stat cu-stat-lead">
        <span class="cu-stat-k">Billed to date</span>
        <span class="cu-stat-v">$<?= money($client['spend']) ?></span>
      </div>
      <div class="cu-stat">
        <span class="cu-stat-k">Rides</span>
        <span class="cu-stat-v"><?= (int)$client['rides'] ?></span>
      </div>
      <div class="cu-stat">
        <span class="cu-stat-k">Last ride</span>
        <span class="cu-stat-v"><?= $client['last_ride'] ? e(date('j M Y', strtotime((string)$client['last_ride']))) : 'Never' ?></span>
      </div>
      <div class="cu-stat">
        <span class="cu-stat-k">Next ride</span>
        <span class="cu-stat-v<?= $client['next_ride'] ? ' is-next' : '' ?>"><?= $client['next_ride'] ? e(date('j M Y', strtotime((string)$client['next_ride']))) : 'Nothing booked' ?></span>
      </div>
    </div>

    <?php if ($unpaidRides > 0): ?>
      <p class="cu-alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> $<?= money($unpaid) ?> across <?= $unpaidRides ?> ride<?= $unpaidRides === 1 ? '' : 's' ?> is still unpaid. <a href="<?= url('admin/payments.php') ?>">Open payments</a></p>
    <?php endif; ?>

    <?php if ($inactive && $upcoming > 0): ?>
      <p class="cu-alert cu-alert-warn"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <?= $upcoming ?> upcoming <?= $upcoming === 1 ? 'ride is' : 'rides are' ?> still booked for <?= e($client['name']) ?>. Suspending stops new bookings; it does not cancel <?= $upcoming === 1 ? 'it' : 'them' ?>.</p>
    <?php endif; ?>

    <h3 class="bk-sec-h">Ride ledger <span class="bk-sub"><?= count($rides) ?> <?= count($rides) === 1 ? 'ride' : 'rides' ?>, newest first</span></h3>

    <?php if (!$rides): ?>
      <p class="cu-empty"><?= e($client['name']) ?> has not booked a ride yet.</p>
      <p class="cu-empty-sub">Nothing to settle, nothing to dispatch. The ledger fills in as soon as a booking is made.</p>
    <?php else: ?>
      <ol class="cu-ledger">
        <?php
        $todayStr = date('Y-m-d');
        foreach ($rides as $r):
          $ahead = $r['pickup_date'] >= $todayStr && !in_array($r['status'], ['finish', 'cancelled', 'refunded'], true);
          $settled = in_array($r['payment_status'], ['paid', 'refunded'], true);
          $dd = strtotime((string)$r['pickup_date']);
          $ym = date('Y', $dd) === date('Y') ? date('M', $dd) : date('M Y', $dd);
        ?>
        <li class="cu-row<?= $ahead ? ' is-ahead' : '' ?>">
          <a class="cu-row-date" href="<?= url('admin/bookings.php?action=view&n=' . $r['booking_number']) ?>">
            <span class="cu-d"><?= e(date('j', $dd)) ?></span>
            <span class="cu-m"><?= e($ym) ?> &middot; <?= e(substr((string)$r['pickup_time'], 0, 5)) ?></span>
            <span class="cu-num"><?= e($r['booking_number']) ?></span>
          </a>
          <span class="cu-row-route">
            <span class="cu-route"><?= e($r['pickup_location']) ?></span>
            <i class="fa-solid fa-arrow-right cu-arrow" aria-hidden="true"></i>
            <span class="cu-route"><?= e($r['destination_location']) ?></span>
          </span>
          <span class="cu-row-party"><?= (int)$r['passengers'] ?> pax &middot; <?= (int)$r['luggage'] ?> bag<?= (int)$r['luggage'] === 1 ? '' : 's' ?></span>
          <span class="cu-row-state">
            <?= status_pill((string)$r['status']) ?>
            <?php if (!$settled): ?><span class="pill <?= $r['payment_status'] === 'failed' ? 'pill-red' : 'pill-gold' ?>"><?= e($r['payment_status']) ?></span><?php endif; ?>
          </span>
          <span class="cu-row-fare">$<?= money($r['total']) ?></span>
        </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <form method="post" class="cu-suspend">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="status">
      <input type="hidden" name="id" value="<?= $cid ?>">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <input type="hidden" name="status" value="<?= $inactive ? 'active' : 'inactive' ?>">
      <button class="cu-btn <?= $inactive ? 'cu-btn-go' : 'cu-btn-warn' ?>">
        <?= $inactive ? 'Reactivate this client' : 'Suspend this client' ?>
      </button>
      <span class="cu-suspend-why"><?= $inactive ? 'They can book again.' : 'Stops new bookings. Existing rides stay on the books.' ?></span>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Clients | Admin';
$navActive = 'customers.php';
require APP_ROOT . '/views/layouts/admin.php';