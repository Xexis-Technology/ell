<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pd = (string)($_POST['day'] ?? '');
    $pdOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) ? DateTime::createFromFormat('!Y-m-d', $pd) : false;
    $postDay = ($pdOk && $pdOk->format('Y-m-d') === $pd) ? $pd : date('Y-m-d');
    // "Leave open" arrives as an empty string from a native select and as "0" from
    // the picker, so normalise both to null. Without this, id 0 reaches the
    // dispatches table and trips the foreign key instead of clearing the slot.
    $optId = static function (mixed $raw): ?int {
        $s = trim((string)($raw ?? ''));
        return ($s === '' || $s === '0') ? null : (int)$s;
    };
    $r = DispatchService::assign($pdo, (int)$_POST['booking_id'], $optId($_POST['driver_id'] ?? null), $optId($_POST['vehicle_id'] ?? null), (int)$admin['id'], trim($_POST['tmp_driver_name'] ?? '') ?: null, trim($_POST['tmp_driver_phone'] ?? '') ?: null, trim($_POST['tmp_vehicle'] ?? '') ? ['label' => trim($_POST['tmp_vehicle'])] : null);
    $msg = $r['error'] ?? 'Dispatch saved. The driver has been notified.';
    $isErr = isset($r['error']);
    if (!$isErr) {
        $st = $pdo->prepare('SELECT b.*, d.driver_id FROM bookings b LEFT JOIN dispatches d ON d.booking_id = b.id WHERE b.id = ? LIMIT 1');
        $st->execute([(int)$_POST['booking_id']]);
        $b = $st->fetch();
        if ($b && $b['driver_id']) {
            $dr = $pdo->query('SELECT email FROM drivers WHERE id = ' . (int)$b['driver_id'])->fetch();
            if ($dr) NotificationService::bookingEmail($pdo, 'driver-assigned', $b, $dr['email'], (int)$b['driver_id'], 'driver');
        }
    }
    header('Location: ' . url('admin/dispatch.php?day=' . urlencode($postDay) . '&msg=' . urlencode($msg) . ($isErr ? '&err=1' : '')));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$isErr = isset($_GET['err']);

// ---- day scope ------------------------------------------------------------
$today = date('Y-m-d');
$day = (string)($_GET['day'] ?? $today);
$parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? DateTime::createFromFormat('!Y-m-d', $day) : false;
if (!$parsed || $parsed->format('Y-m-d') !== $day) $day = $today;
$isToday = $day === $today;
$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($day . ' +1 day'));
$dayLabel = date('l j F', strtotime($day));

$sqlUpcoming = 'SELECT b.id, b.booking_number, b.pickup_date, b.pickup_time, b.status, b.service_type, b.trip_type, b.pickup_location, b.destination_location, b.passengers, b.luggage, b.guest_name, c.name AS cname,
        d.id AS did, d.driver_id, d.vehicle_id, d.temporary_driver_name, d.status AS dstatus,
        dr.name AS dname, CONCAT(v.make, " ", v.model) AS vname, v.plate AS vplate
  FROM bookings b
  LEFT JOIN dispatches d ON d.booking_id = b.id
  LEFT JOIN drivers dr ON dr.id = d.driver_id
  LEFT JOIN vehicles v ON v.id = d.vehicle_id
  LEFT JOIN customers c ON c.id = b.customer_id
  WHERE b.pickup_date = ? AND b.status IN ("booking_received","confirmed","assigned","on_the_way","arrived","at_pickup_location","on_board")
  ORDER BY b.pickup_time, b.id';
$st = $pdo->prepare($sqlUpcoming);
$st->execute([$day]);
$upcoming = $st->fetchAll();

$st = $pdo->query('SELECT id, name, phone FROM drivers WHERE status = "active" ORDER BY name');
$drivers = $st->fetchAll();
$st = $pdo->query('SELECT v.id, v.make, v.model, v.plate, v.passenger_capacity, v.luggage_capacity, c.name AS category, r.per_mile_rate, r.hourly_rate
  FROM vehicles v
  LEFT JOIN vehicle_categories c ON c.id = v.category_id
  LEFT JOIN pricing_rates r ON r.vehicle_id = v.id AND r.active = 1
  WHERE v.status = "active"
  ORDER BY v.make, v.model');
$vehicles = $st->fetchAll();

$st = $pdo->prepare('SELECT h.*, b.booking_number,
        od.name AS old_dname, nd.name AS new_dname, ov.make AS old_vmake, ov.model AS old_vmodel, nv.make AS new_vmake, nv.model AS new_vmodel
  FROM dispatch_history h
  JOIN bookings b ON b.id = h.booking_id
  LEFT JOIN drivers od ON od.id = h.old_driver_id
  LEFT JOIN drivers nd ON nd.id = h.new_driver_id
  LEFT JOIN vehicles ov ON ov.id = h.old_vehicle_id
  LEFT JOIN vehicles nv ON nv.id = h.new_vehicle_id
  ORDER BY h.id DESC LIMIT 12');
$st->execute();
$history = $st->fetchAll();

// ---- counts for the header ------------------------------------------------
$needsCover = 0;
$onRoad = 0;
foreach ($upcoming as $u) {
    if (!$u['driver_id'] && !$u['temporary_driver_name']) $needsCover++;
    if (in_array($u['status'], ['on_the_way', 'arrived', 'at_pickup_location', 'on_board'], true)) $onRoad++;
}
$freeCars = 0;
foreach ($vehicles as $v) if (DispatchService::vehicleAvailable($pdo, (int)$v['id'], $day, '00:00')) $freeCars++;
$freeCrew = 0;
foreach ($drivers as $d) if (DispatchService::driverAvailable($pdo, (int)$d['id'], $day)) $freeCrew++;

$st = $pdo->prepare('SELECT COUNT(*) c FROM bookings WHERE pickup_date >= ? AND status = "awaiting_pricing"');
$st->execute([$day]);
$waitingPrice = (int)$st->fetch()['c'];

// ---- payload for the driver / car pickers ---------------------------------
// Availability is resolved once here and reused by the header, the crew and car
// panels, and the pickers, so we do not re-query per panel.
$carFree = [];
foreach ($vehicles as $v) $carFree[(int)$v['id']] = DispatchService::vehicleAvailable($pdo, (int)$v['id'], $day, '00:00');
$crewFree = [];
foreach ($drivers as $d) $crewFree[(int)$d['id']] = DispatchService::driverAvailable($pdo, (int)$d['id'], $day);

$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach ($parts as $p) if ($p !== '') $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out !== '' ? $out : '?';
};

$pickerData = ['drivers' => [], 'cars' => []];
foreach ($drivers as $d) {
    $pickerData['drivers'][] = [
        'id' => (int)$d['id'],
        'n' => (string)$d['name'],
        'ini' => $initials((string)$d['name']),
        'ph' => (string)($d['phone'] ?? ''),
        'free' => !empty($crewFree[(int)$d['id']]),
        's' => mb_strtolower(trim((string)$d['name'] . ' ' . (string)($d['phone'] ?? ''))),
    ];
}
foreach ($vehicles as $v) {
    $nm = trim((string)$v['make'] . ' ' . (string)$v['model']);
    $pickerData['cars'][] = [
        'id' => (int)$v['id'],
        'n' => $nm,
        'plate' => (string)($v['plate'] ?? ''),
        'img' => vehicle_photo_url((string)($v['category'] ?? ''), 200),
        'mi' => (float)($v['per_mile_rate'] ?? 0),
        'hr' => (float)($v['hourly_rate'] ?? 0),
        'cap' => (int)$v['passenger_capacity'] . ' pax',
        'free' => !empty($carFree[(int)$v['id']]),
        's' => mb_strtolower(trim($nm . ' ' . (string)($v['plate'] ?? '') . ' ' . (string)($v['category'] ?? ''))),
    ];
}
$pickerJson = json_encode($pickerData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

ob_start();
?>
<script type="application/json" id="dp-data"><?= $pickerJson ?></script>
<script>
document.addEventListener('alpine:init', function () {
  var cache = null;
  function data() {
    if (!cache) cache = JSON.parse(document.getElementById('dp-data').textContent);
    return cache;
  }
  Alpine.data('dpPicker', function (kind, selected) {
    return {
      kind: kind,
      selected: selected || 0,
      open: false,
      q: '',
      cursor: 0,
      get all() { return data()[this.kind] || []; },
      get filtered() {
        var q = this.q.trim().toLowerCase();
        return q ? this.all.filter(function (o) { return o.s.indexOf(q) !== -1; }) : this.all;
      },
      get current() {
        var id = this.selected;
        return this.all.filter(function (o) { return o.id === id; })[0] || null;
      },
      get label() { return this.current ? this.current.n : 'Leave open'; },
      show() {
        this.open = true;
        this.q = '';
        this.cursor = this.selected ? Math.max(0, this.filtered.findIndex(function (o) { return o.id === this.selected; }.bind(this))) : -1;
        var self = this;
        this.$nextTick(function () { if (self.$refs.q) { self.$refs.q.focus(); } });
      },
      hide() { this.open = false; this.q = ''; },
      pick(id) { this.selected = id; this.hide(); },
      move(d) {
        var n = this.filtered.length;
        if (!n) return;
        var lo = -1, hi = n - 1;
        this.cursor = this.cursor < lo ? hi : (this.cursor > hi ? lo : this.cursor + d);
        if (this.cursor < 0) this.cursor = hi;
        else if (this.cursor > hi) this.cursor = lo;
        this.$nextTick(function () {
          var el = this.$refs.list && this.$refs.list.children[this.cursor];
          if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        }.bind(this));
      },
      choose() {
        if (this.cursor === -1) { this.pick(0); return; }
        var o = this.filtered[this.cursor];
        if (o) this.pick(o.id);
      }
    };
  });
});
</script>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="dp-head">
  <div class="dp-head-txt">
    <p class="eyebrow">Operations</p>
    <h1 class="font-display">Dispatch board</h1>
    <p class="dp-sub">The run sheet for <strong><?= e($dayLabel) ?></strong>. Trips run top to bottom by departure time. Amber means the trip still needs a driver or a car.</p>
  </div>
  <nav class="dp-daybar" aria-label="Choose day">
    <a class="dp-day-step" href="<?= url('admin/dispatch.php?day=' . $prevDay) ?>" aria-label="Previous day"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
    <label class="dp-daypick">
      <span class="bk-k">Day</span>
      <form method="get" class="dp-dayform" action="<?= url('admin/dispatch.php') ?>">
        <input type="date" name="day" class="dp-dayinput" value="<?= e($day) ?>" aria-label="Pick a date" onchange="this.form.submit()">
      </form>
    </label>
    <a class="dp-day-step" href="<?= url('admin/dispatch.php?day=' . $nextDay) ?>" aria-label="Next day"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
    <?php if (!$isToday): ?><a class="dp-day-today" href="<?= url('admin/dispatch.php') ?>">Back to today</a><?php endif; ?>
  </nav>
</header>

<p class="dp-meta">
  <span><b><?= $needsCover ?></b> <?= $needsCover === 1 ? 'trip needs' : 'trips need' ?> cover</span>
  <span><b><?= $onRoad ?></b> on the road</span>
  <span><b><?= count($upcoming) ?></b> on the sheet</span>
  <span><b><?= $freeCrew ?>/<?= count($drivers) ?></b> crew free</span>
  <span><b><?= $freeCars ?>/<?= count($vehicles) ?></b> cars free</span>
</p>

<?php if ($waitingPrice > 0): ?>
  <p class="dp-attn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= $waitingPrice ?> <?= $waitingPrice === 1 ? 'trip from' : 'trips from' ?> here on still <?= $waitingPrice === 1 ? 'has' : 'have' ?> no price, so <?= $waitingPrice === 1 ? 'it' : 'they' ?> can't be dispatched yet. <a href="<?= url('admin/bookings.php?action=list&status=awaiting_pricing') ?>">Open the pricing queue</a>.</p>
<?php endif; ?>

<div class="dp-grid">
  <section class="dp-sheet-wrap" aria-label="Run sheet">
    <h2 class="bk-sec-h">Run sheet <?php if ($upcoming): ?><span class="bk-sub"><?= count($upcoming) ?> <?= count($upcoming) === 1 ? 'trip' : 'trips' ?> &middot; <?= $needsCover ?> still to cover</span><?php endif; ?></h2>
    <?php if (!$upcoming): ?>
      <div class="dp-empty">
        <p class="dp-empty-t">Nothing on the sheet for <?= e($dayLabel) ?>.</p>
        <p>No trip is scheduled to leave on this day yet. <a href="<?= url('admin/bookings.php?action=list&status=confirmed') ?>">Look at confirmed bookings</a> or <a href="<?= url('admin/dispatch.php') ?>">jump back to today</a>.</p>
      </div>
    <?php else: ?>
      <ol class="dp-sheet">
        <?php
        $prevMin = null;
        foreach ($upcoming as $u):
            $hh = (int)substr((string)$u['pickup_time'], 0, 2);
            $mm = (int)substr((string)$u['pickup_time'], 3, 2);
            $min = $hh * 60 + $mm;
            $covered = (bool)($u['driver_id'] || $u['temporary_driver_name']);
            $who = (string)($u['dname'] ?: ($u['temporary_driver_name'] ?? ''));
            $car = trim((string)($u['vname'] ?: ''));
            $carPlate = (string)($u['vplate'] ?: '');
            $live = in_array($u['status'], ['on_the_way', 'arrived', 'at_pickup_location', 'on_board'], true);
            if ($prevMin !== null) {
                $gap = $min - $prevMin;
                if ($gap >= 45) {
                    $g = intdiv($gap, 60); $gm = $gap % 60;
                    echo '<li class="dp-gap"><span>' . $g . ' h' . ($gm ? ' ' . $gm . ' m' : '') . ' clear</span></li>';
                }
            }
            $prevMin = $min;
        ?>
        <li class="dp-row<?= $covered ? '' : ' is-needy' ?><?= $live ? ' is-live' : '' ?>">
          <div class="dp-when">
            <span class="dp-clock"><?= e(sprintf('%02d:%02d', $hh, $mm)) ?></span>
            <span class="dp-rel"><?php
              $nowMin = (int)date('G') * 60 + (int)date('i');
              if (!$isToday) echo e(date('D', strtotime($day)));
              elseif ($min - $nowMin >= 60) echo 'in ' . intdiv($min - $nowMin, 60) . 'h';
              elseif ($min - $nowMin >= 1) echo 'in ' . ($min - $nowMin) . 'm';
              elseif ($min - $nowMin >= -20) echo 'now';
              else echo 'gone';
            ?></span>
          </div>
          <div class="dp-body">
            <div class="dp-line">
              <a class="dp-num" href="<?= url('admin/bookings.php?action=view&n=' . $u['booking_number']) ?>"><?= e($u['booking_number']) ?></a>
              <span class="dp-route"><?= e($u['pickup_location']) ?></span>
              <i class="fa-solid fa-arrow-right dp-arrow" aria-hidden="true"></i>
              <span class="dp-route"><?= e($u['destination_location']) ?></span>
            </div>
            <div class="dp-facts">
              <span><?= e($u['passengers']) ?> pax</span>
              <span><?= e($u['luggage']) ?> bag<?= (int)$u['luggage'] === 1 ? '' : 's' ?></span>
              <span><?= e(ucwords(str_replace('_', ' ', (string)$u['service_type']))) ?></span>
              <?= status_pill((string)$u['status']) ?>
            </div>
            <div class="dp-crew">
              <?php if ($covered && $car !== ''): ?>
                <span class="dp-t"><i class="fa-solid fa-user" aria-hidden="true"></i><?= e($who !== '' ? $who : 'Driver') ?></span>
                <span class="dp-t"><i class="fa-solid fa-car" aria-hidden="true"></i><?= e($car) ?><?php if ($carPlate !== ''): ?><b class="dp-plate"><?= e($carPlate) ?></b><?php endif; ?></span>
              <?php elseif ($covered): ?>
                <span class="dp-t"><i class="fa-solid fa-user" aria-hidden="true"></i><?= e($who !== '' ? $who : 'Driver') ?></span>
                <span class="dp-nudge">No car yet</span>
              <?php else: ?>
                <span class="dp-nudge"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> Needs a driver<?= $car !== '' ? ' and a car' : '' ?></span>
              <?php endif; ?>
            </div>
            <details class="dp-reveal">
              <summary class="dp-reveal-btn"><?= $covered ? 'Change driver or car' : 'Cover this trip' ?></summary>
              <form method="post" class="dp-form">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="assign">
                <input type="hidden" name="booking_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="day" value="<?= e($day) ?>">
                <div class="dp-form-row">
                  <div class="dp-pk-wrap">
                    <span class="bk-k" id="dl_<?= (int)$u['id'] ?>">Driver</span>
                    <div class="pk" x-data="dpPicker('drivers', <?= (int)($u['driver_id'] ?? 0) ?>)" @keydown.escape="hide()">
                      <input type="hidden" name="driver_id" :value="selected">
                      <button type="button" class="pk-btn" @click="open ? hide() : show()" :aria-expanded="open" aria-haspopup="listbox" aria-labelledby="dl_<?= (int)$u['id'] ?>">
                        <template x-if="current"><span class="pk-thumb pk-thumb-ini" x-text="current.ini"></span></template>
                        <span class="pk-btn-txt" :class="current ? '' : 'is-empty'" x-text="label"></span>
                        <i class="fa-solid fa-chevron-down pk-caret" aria-hidden="true"></i>
                      </button>
                      <div class="pk-pop" x-show="open" @click.outside="hide()" x-cloak>
                        <div class="pk-search">
                          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                          <input type="search" x-ref="q" x-model="q" placeholder="Search driver" aria-label="Search driver by name or phone"
                            @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="choose()" @keydown.esc="hide()">
                        </div>
                        <ul class="pk-list" x-ref="list" role="listbox" aria-label="Drivers">
                          <li class="pk-item pk-item-none" :class="cursor === -1 ? 'is-cur' : ''" @mouseenter="cursor = -1" @click="pick(0)">
                            <span class="pk-thumb pk-thumb-none"><i class="fa-solid fa-minus" aria-hidden="true"></i></span>
                            <span class="pk-item-body"><b>Leave open</b><small>Nobody assigned yet</small></span>
                          </li>
                          <template x-for="(o, i) in filtered" :key="o.id">
                            <li class="pk-item" :class="cursor === i ? 'is-cur' : ''" role="option" :aria-selected="selected === o.id" @mouseenter="cursor = i" @click="pick(o.id)">
                              <span class="pk-thumb pk-thumb-ini" x-text="o.ini"></span>
                              <span class="pk-item-body">
                                <b x-text="o.n"></b>
                                <small><span x-text="o.ph || 'No phone on file'"></span> &middot; <span :class="o.free ? 'pk-free' : 'pk-busy'" x-text="o.free ? 'Free' : 'On a trip'"></span></small>
                              </span>
                              <i class="fa-solid fa-check pk-tick" x-show="selected === o.id" aria-hidden="true"></i>
                            </li>
                          </template>
                        </ul>
                        <p class="pk-none" x-show="filtered.length === 0" x-cloak>No driver matches &ldquo;<span x-text="q"></span>&rdquo;.</p>
                      </div>
                    </div>
                  </div>
                  <div class="dp-pk-wrap">
                    <span class="bk-k" id="cl_<?= (int)$u['id'] ?>">Car</span>
                    <div class="pk pk--end" x-data="dpPicker('cars', <?= (int)($u['vehicle_id'] ?? 0) ?>)" @keydown.escape="hide()">
                      <input type="hidden" name="vehicle_id" :value="selected">
                      <button type="button" class="pk-btn" @click="open ? hide() : show()" :aria-expanded="open" aria-haspopup="listbox" aria-labelledby="cl_<?= (int)$u['id'] ?>">
                        <template x-if="current"><img class="pk-thumb pk-thumb-img" :src="current.img" alt="" x-text="current.n" decoding="async"></template>
                        <span class="pk-btn-txt" :class="current ? '' : 'is-empty'" x-text="label"></span>
                        <i class="fa-solid fa-chevron-down pk-caret" aria-hidden="true"></i>
                      </button>
                      <div class="pk-pop" x-show="open" @click.outside="hide()" x-cloak>
                        <div class="pk-search">
                          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                          <input type="search" x-ref="q" x-model="q" placeholder="Search car, model or plate" aria-label="Search car by name or plate"
                            @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="choose()" @keydown.esc="hide()">
                        </div>
                        <ul class="pk-list" x-ref="list" role="listbox" aria-label="Cars">
                          <li class="pk-item pk-item-none" :class="cursor === -1 ? 'is-cur' : ''" @mouseenter="cursor = -1" @click="pick(0)">
                            <span class="pk-thumb pk-thumb-none"><i class="fa-solid fa-minus" aria-hidden="true"></i></span>
                            <span class="pk-item-body"><b>Leave open</b><small>No car assigned yet</small></span>
                          </li>
                          <template x-for="(o, i) in filtered" :key="o.id">
                            <li class="pk-item" :class="cursor === i ? 'is-cur' : ''" role="option" :aria-selected="selected === o.id" @mouseenter="cursor = i" @click="pick(o.id)">
                              <img class="pk-thumb pk-thumb-img" :src="o.img" :alt="o.n" loading="lazy" decoding="async" onerror="this.style.visibility='hidden'">
                              <span class="pk-item-body">
                                <b x-text="o.n"></b>
                                <small><span class="pk-plate" x-text="o.plate || 'No plate'"></span> &middot; <span x-text="o.cap"></span></small>
                                <small class="pk-rates"><span x-text="o.mi ? '$' + o.mi.toFixed(2) + '/mi' : 'No mile rate'"></span> <span class="pk-rates-b" x-text="o.hr ? '$' + o.hr.toFixed(2) + '/hr' : 'No hourly rate'"></span> <span :class="o.free ? 'pk-free' : 'pk-busy'" x-text="o.free ? 'Free' : 'On a trip'"></span></small>
                              </span>
                              <i class="fa-solid fa-check pk-tick" x-show="selected === o.id" aria-hidden="true"></i>
                            </li>
                          </template>
                        </ul>
                        <p class="pk-none" x-show="filtered.length === 0" x-cloak>No car matches &ldquo;<span x-text="q"></span>&rdquo;.</p>
                      </div>
                    </div>
                  </div>
                </div>
                <p class="bk-k dp-form-cap">No record in the system?</p>
                <div class="dp-form-row">
                  <div>
                    <label class="bk-k" for="tn_<?= (int)$u['id'] ?>">Temp driver name</label>
                    <input id="tn_<?= (int)$u['id'] ?>" name="tmp_driver_name" class="input" value="<?= e((string)($u['temporary_driver_name'] ?? '')) ?>" placeholder="Who is covering it">
                  </div>
                  <div>
                    <label class="bk-k" for="tp_<?= (int)$u['id'] ?>">Temp driver phone</label>
                    <input id="tp_<?= (int)$u['id'] ?>" name="tmp_driver_phone" class="input" value="<?= e((string)($u['temporary_driver_phone'] ?? '')) ?>" placeholder="Their mobile number">
                  </div>
                </div>
                <div>
                  <label class="bk-k" for="tv_<?= (int)$u['id'] ?>">Temp car</label>
                  <input id="tv_<?= (int)$u['id'] ?>" name="tmp_vehicle" class="input" placeholder="Car that isn't in your fleet list">
                </div>
                <div class="dp-form-foot">
                  <button class="dp-btn dp-btn-go"><i class="fa-solid fa-check" aria-hidden="true"></i> <?= $covered ? 'Save change' : 'Assign driver' ?></button>
                  <a class="dp-btn dp-btn-line" href="<?= url('admin/bookings.php?action=view&n=' . $u['booking_number']) ?>">Open booking</a>
                </div>
              </form>
            </details>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>

  <aside class="dp-side">
    <section class="dp-sec">
      <h2 class="bk-sec-h">Crew <span class="bk-sub"><?= count($drivers) ?> active</span></h2>
      <?php if (!$drivers): ?><p class="bk-empty">No active drivers yet. <a href="<?= url('admin/drivers.php') ?>">Add one</a>.</p><?php endif; ?>
      <ul class="dp-list">
        <?php foreach ($drivers as $d):
          $free = DispatchService::driverAvailable($pdo, (int)$d['id'], $day);
        ?>
        <li class="dp-listrow">
          <span class="dp-listname"><?= e($d['name']) ?></span>
          <span class="dp-listmeta"><?= $free ? 'Free' : 'On a trip' ?><?= $d['phone'] ? ' &middot; ' . e($d['phone']) : '' ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="dp-sec">
      <h2 class="bk-sec-h">Cars <span class="bk-sub"><?= count($vehicles) ?> in fleet</span></h2>
      <?php if (!$vehicles): ?><p class="bk-empty">No active vehicles. <a href="<?= url('admin/vehicles.php') ?>">Add one</a>.</p><?php endif; ?>
      <ul class="dp-cars">
        <?php foreach ($vehicles as $v):
          $free = DispatchService::vehicleAvailable($pdo, (int)$v['id'], $day, '00:00');
        ?>
        <li class="dp-car<?= $free ? '' : ' is-taken' ?>">
          <b class="dp-plate"><?= e((string)($v['plate'] ?: '-')) ?></b>
          <span class="dp-carname"><?= e($v['make'] . ' ' . $v['model']) ?></span>
          <span class="dp-carmeta"><?= (int)$v['passenger_capacity'] ?> pax &middot; <?= (int)$v['luggage_capacity'] ?> bags</span>
          <span class="dp-carstate"><?= $free ? 'Free' : 'On a trip' ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="dp-sec">
      <h2 class="bk-sec-h">Recent moves <span class="bk-sub">Latest <?= count($history) ?></span></h2>
      <?php if (!$history): ?><p class="bk-empty">No dispatch changes recorded yet.</p><?php endif; ?>
      <ul class="dp-moves">
        <?php foreach ($history as $h): ?>
        <li class="dp-move">
          <p class="dp-move-what">
            <?= e((string)($h['old_dname'] ?: 'Open')) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i> <?= e((string)($h['new_dname'] ?: 'Open')) ?>
            <span class="dp-move-car"><?= e(trim((string)($h['old_vmake'] ?? '') . ' ' . (string)($h['old_vmodel'] ?? ''))) ?> &rarr; <?= e(trim((string)($h['new_vmake'] ?? '') . ' ' . (string)($h['new_vmodel'] ?? ''))) ?></span>
          </p>
          <p class="dp-move-meta"><a href="<?= url('admin/bookings.php?action=view&n=' . $h['booking_number']) ?>"><?= e($h['booking_number']) ?></a> &middot; <?= e((string)($h['note'] ?? '')) ?> &middot; <?= e($h['created_at']) ?></p>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </aside>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Dispatch | Admin';
$navActive = 'dispatch.php';
require APP_ROOT . '/views/layouts/admin.php';