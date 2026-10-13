<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');

$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$initial = strtoupper(mb_substr((string)($admin['name'] ?? 'A'), 0, 1));

function pct_change(float $cur, float $prev): ?float
{
    if ($prev == 0.0) return null;
    return round(($cur - $prev) / $prev * 100, 1);
}
function delta_chip(?float $pct): string
{
    if ($pct === null) return '<span class="tabular text-[11px] text-[#AB8868]">—</span>';
    $up = $pct >= 0;
    $cls = $up ? 'color:#8fce9b' : 'color:#e08a85';
    return '<span class="tabular text-[11px]" style="' . $cls . '">' . ($up ? '+' : '') . $pct . '%</span>';
}

// Stat cards with real period-over-period deltas
$pickToday = (int)$pdo->query('SELECT COUNT(*) c FROM bookings WHERE pickup_date = CURDATE()')->fetch()['c'];
$pickYday = (int)$pdo->query('SELECT COUNT(*) c FROM bookings WHERE pickup_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)')->fetch()['c'];
$new7 = (int)$pdo->query('SELECT COUNT(*) c FROM bookings WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')->fetch()['c'];
$newPrior7 = (int)$pdo->query('SELECT COUNT(*) c FROM bookings WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)')->fetch()['c'];
$paid7 = (int)$pdo->query('SELECT COUNT(*) c FROM bookings WHERE payment_status = "paid" AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')->fetch()['c'];
$paidPrior7 = (int)$pdo->query('SELECT COUNT(*) c FROM bookings WHERE payment_status = "paid" AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)')->fetch()['c'];
$rev30 = (float)$pdo->query('SELECT COALESCE(SUM(total),0) c FROM bookings WHERE payment_status = "paid" AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)')->fetch()['c'];
$revPrior30 = (float)$pdo->query('SELECT COALESCE(SUM(total),0) c FROM bookings WHERE payment_status = "paid" AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)')->fetch()['c'];

// Revenue chart (real daily paid totals)
$range = in_array($_GET['range'] ?? '', ['7', '14', '30'], true) ? (int)$_GET['range'] : 14;
$st = $pdo->prepare('SELECT DATE(created_at) d, COALESCE(SUM(CASE WHEN payment_status = "paid" THEN total ELSE 0 END),0) t, COUNT(*) c FROM bookings WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL :r DAY) GROUP BY DATE(created_at) ORDER BY d');
$st->bindValue(':r', $range - 1, PDO::PARAM_INT);
$st->execute();
$days = $st->fetchAll();
$byDay = [];
foreach ($days as $d) $byDay[$d['d']] = $d;
$series = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $dt = date('Y-m-d', strtotime("-$i days"));
    $series[] = ['d' => $dt, 't' => (float)($byDay[$dt]['t'] ?? 0)];
}
$chartTotal = array_sum(array_column($series, 't'));

$attention = [
    ['n' => (int)$pdo->query('SELECT COUNT(*) c FROM bookings WHERE status = "awaiting_pricing"')->fetch()['c'], 'label' => 'Awaiting pricing', 'url' => 'admin/bookings.php?status=awaiting_pricing'],
    ['n' => (int)$pdo->query('SELECT COUNT(*) c FROM drivers WHERE status = "pending"')->fetch()['c'], 'label' => 'Drivers to activate', 'url' => 'admin/drivers.php?status=pending'],
    ['n' => (int)$pdo->query('SELECT COUNT(*) c FROM driver_payouts WHERE status = "requested"')->fetch()['c'], 'label' => 'Payout requests', 'url' => 'admin/payouts.php'],
];
/*
  The dashboard asks one question — what landed since I last looked — so this
  panel is capped at ten arrivals instead of growing with the business, and the
  ten it shows carry the one measure the bookings board cannot give: how long
  each booking has been sitting here waiting on the desk. The board sorts by when
  the car is needed; this sorts by arrival, and age only means something on an
  arrival list.
*/
$recent = $pdo->query('SELECT b.booking_number, b.pickup_date, b.pickup_time, b.pickup_location, b.destination_location, b.service_type, b.status, b.payment_status, b.total, b.created_at, c.name AS cname, b.guest_name, v.make, v.model FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN vehicles v ON v.id = b.vehicle_id ORDER BY b.id DESC LIMIT 10')->fetchAll();
$recentTotal = (int)$pdo->query('SELECT COUNT(*) c FROM bookings')->fetch()['c'];
$recentMore = $recentTotal > count($recent);

/* Stages where the ball is in the office's court rather than the driver's. */
$onTheDesk = ['booking_received', 'awaiting_pricing', 'pricing_finalized', 'pending_payment', 'payment_failed'];
$waitingFor = static function (array $r) use ($onTheDesk): array {
    if (!in_array((string)$r['status'], $onTheDesk, true)) return [false, '', 0];
    $mins = max(1, (int)floor((time() - strtotime((string)$r['created_at'])) / 60));
    if ($mins < 60) return [true, $mins . 'm', $mins];
    $h = (int)floor($mins / 60);
    if ($h < 48) return [true, $h . 'h', $mins];
    $d = (int)floor($h / 24);
    return [true, $d . 'd', $mins];
};
ob_start();
?>
<!-- Greeting bar -->
<div class="flex items-center justify-between gap-3 flex-wrap">
  <div>
    <h1 class="font-display text-3xl"><?= e($greet) ?>, <?= e($admin['name']) ?>!</h1>
    <p class="text-sm text-[#AB8868]">Here's what's happening with your store today</p>
  </div>
  <div class="flex items-center gap-2">
    <span class="tabular text-xs border border-[#3a3a3d] rounded-full px-4 py-2"><?= e(date('d M Y')) ?></span>
    <?php $alerts = array_sum(array_column($attention, 'n')); ?>
    <a href="<?= url('admin/bookings.php?status=awaiting_pricing') ?>" aria-label="<?= (int)$alerts ?> items need attention" class="relative w-10 h-10 rounded-full border border-[#3a3a3d] inline-flex items-center justify-center text-[#F3D4A6]">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M10.3 21a2 2 0 0 0 3.4 0"/></svg>
      <?php if ($alerts > 0): ?><span class="absolute -top-1 -right-1 tabular min-w-[20px] h-5 px-1 rounded-full bg-[#A3322F] text-white text-[11px] inline-flex items-center justify-center"><?= (int)$alerts ?></span><?php endif; ?>
    </a>
    <span class="w-10 h-10 rounded-full bg-[#D9B978] text-[#0A0A0C] font-bold inline-flex items-center justify-center" aria-hidden="true"><?= e($initial) ?></span>
  </div>
</div>

<!-- Jump to: the desks that get opened between phone calls. The sidebar
     already lists everything, so this is deliberately the short list a person
     reaches for mid-conversation, not a second navigation to keep in sync. -->
<nav class="dash-jump" aria-label="Jump to a section">
  <span class="dash-jump-label">Jump to</span>
  <?php foreach ([
    ['Dispatch', 'fa-paper-plane', 'admin/dispatch.php'],
    ['Customers', 'fa-users', 'admin/customers.php'],
    ['Drivers', 'fa-id-card', 'admin/drivers.php'],
    ['Pricing', 'fa-tag', 'admin/pricing.php'],
    ['Payments', 'fa-credit-card', 'admin/payments.php'],
    ['Payouts', 'fa-money-bill-wave', 'admin/payouts.php'],
    ['Reports', 'fa-chart-line', 'admin/reports.php'],
  ] as [$jumpLabel, $jumpIcon, $jumpHref]): ?>
    <a class="dash-jump-btn" href="<?= url($jumpHref) ?>"><i class="fa-solid <?= e($jumpIcon) ?>" aria-hidden="true"></i><?= e($jumpLabel) ?></a>
  <?php endforeach; ?>
</nav>

<!-- Stat cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
  <div class="card rounded-2xl p-5">
    <p class="label">Pickups today</p>
    <p class="flex items-baseline gap-2 mt-1"><span class="tabular font-display text-3xl"><?= (int)$pickToday ?></span><?= delta_chip(pct_change($pickToday, $pickYday)) ?></p>
    <p class="text-[11px] text-[#AB8868] mt-1">vs yesterday</p>
  </div>
  <div class="card rounded-2xl p-5">
    <p class="label">New bookings · 7d</p>
    <p class="flex items-baseline gap-2 mt-1"><span class="tabular font-display text-3xl"><?= (int)$new7 ?></span><?= delta_chip(pct_change($new7, $newPrior7)) ?></p>
    <p class="text-[11px] text-[#AB8868] mt-1">vs prior 7 days</p>
  </div>
  <div class="card rounded-2xl p-5">
    <p class="label">Paid rides · 7d</p>
    <p class="flex items-baseline gap-2 mt-1"><span class="tabular font-display text-3xl"><?= (int)$paid7 ?></span><?= delta_chip(pct_change($paid7, $paidPrior7)) ?></p>
    <p class="text-[11px] text-[#AB8868] mt-1">vs prior 7 days</p>
  </div>
  <div class="card rounded-2xl p-5">
    <p class="label">Revenue paid · 30d</p>
    <p class="flex items-baseline gap-2 mt-1"><span class="tabular font-display text-3xl">$<?= money($rev30) ?></span><?= delta_chip(pct_change($rev30, $revPrior30)) ?></p>
    <p class="text-[11px] text-[#AB8868] mt-1">vs prior 30 days</p>
  </div>
</div>

<!-- Revenue report -->
<div class="card rounded-2xl p-5 md:p-6 mt-4">
  <div class="flex items-center justify-between flex-wrap gap-2">
    <div><h2 class="font-display text-2xl">Revenue report</h2><p class="text-xs text-[#AB8868]">Paid bookings per day</p></div>
    <div class="flex gap-1" role="group" aria-label="Range">
      <?php foreach ([7, 14, 30] as $r): ?>
      <a href="<?= url('admin/dashboard.php?range=' . $r) ?>" class="tabular text-xs px-3 py-1.5 rounded-full <?= $range === $r ? 'bg-[#D9B978] text-[#0A0A0C] font-semibold' : 'border border-[#3a3a3d] text-[#AB8868]' ?>"><?= $r ?>d</a>
      <?php endforeach; ?>
    </div>
  </div>
  <p class="tabular font-display text-4xl mt-2">$<?= money($chartTotal) ?></p>
  <?php if ($chartTotal > 0): ?>
  <div class="mt-2" style="position:relative;height:260px">
    <canvas id="revChart" role="img" aria-label="Daily paid revenue, total $<?= money($chartTotal) ?>"></canvas>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
  <script>
  (function () {
    const labels = <?= json_encode(array_map(fn($p) => date('M j', strtotime($p['d'])), $series), JSON_HEX_TAG) ?>;
    const values = <?= json_encode(array_map(fn($p) => (float)$p['t'], $series)) ?>;
    const el = document.getElementById('revChart');
    if (!el || typeof Chart === 'undefined') return;
    const ctx = el.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 260);
    grad.addColorStop(0, 'rgba(217,185,120,0.35)');
    grad.addColorStop(1, 'rgba(217,185,120,0)');
    new Chart(el, {
      type: 'line',
      data: {labels: labels, datasets: [{data: values, borderColor: '#D9B978', backgroundColor: grad, fill: true, tension: 0.35, pointRadius: 3, pointBackgroundColor: '#0A0A0C', pointBorderColor: '#D9B978', borderWidth: 2.5}]},
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {legend: {display: false}, tooltip: {callbacks: {label: (c) => ' $' + Number(c.parsed.y).toFixed(2)}}},
        scales: {
          x: {ticks: {color: '#AB8868', maxTicksLimit: 8}, grid: {display: false}},
          y: {ticks: {color: '#AB8868', callback: (v) => '$' + v}, grid: {color: '#262628'}}
        }
      }
    });
  })();
  </script>
  <?php else: ?>
  <p class="text-sm text-[#AB8868] mt-4">No paid rides in this range yet.</p>
  <?php endif; ?>
</div>

<div class="dash-cols grid xl:grid-cols-[1fr_320px] gap-4 mt-4 items-start">
  <!-- Recent bookings -->
  <div class="card rounded-2xl p-5">
    <div class="dash-latest-head">
      <div>
        <h2 class="font-display text-2xl">Latest bookings</h2>
        <p class="dash-latest-sub">The <?= count($recent) ?> most recent<?= $recentMore ? ' of ' . $recentTotal : '' ?></p>
      </div>
      <?php if ($recentMore): ?>
        <a href="<?= url('admin/bookings.php') ?>" class="dash-more">View all <?= $recentTotal ?> bookings<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
      <?php endif; ?>
    </div>
    <div class="table-wrap mt-2"><table class="data dash-sheet"><thead><tr>
      <th>Pickup</th><th>Guest</th><th class="dash-h-num">Total</th><th>Stage</th><th class="dash-h-wait">Waiting</th>
    </tr></thead><tbody>
    <?php foreach ($recent as $r):
      $isWaiting = $waitingFor($r);
      $rid = (string)$r['booking_number'];
      $guest = trim((string)($r['cname'] ?: $r['guest_name']));
      if ($guest === '') $guest = 'Guest booking';
      $car = trim(((string)($r['make'] ?? '')) . ' ' . ((string)($r['model'] ?? '')));
      $svc = trim((string)$r['service_type']);
      $time = substr((string)$r['pickup_time'], 0, 5);
      if ($time === '' || $time === '00:00') $time = '';
      $m = (int)$isWaiting[2];
      $spoken = $isWaiting[2] < 60
        ? $isWaiting[2] . ' minute' . ($isWaiting[2] === 1 ? '' : 's')
        : ($m < 2880 ? intdiv($m, 60) . ' hour' . (intdiv($m, 60) === 1 ? '' : 's') : intdiv($m, 1440) . ' day' . (intdiv($m, 1440) === 1 ? '' : 's'));
    ?>
    <tr>
      <td class="tabular dash-when">
        <?php if ($time !== ''): ?><b class="dash-time"><?= e($time) ?></b><?php endif; ?>
        <span class="dash-date"><?= e(date('D j M', strtotime((string)$r['pickup_date']))) ?></span>
        <a class="dash-ref" href="<?= url('admin/bookings.php?action=view&n=' . $rid) ?>"><?= e($rid) ?></a>
      </td>
      <td class="dash-guest">
        <a class="dash-name" href="<?= url('admin/bookings.php?action=view&n=' . $rid) ?>"><?= e($guest) ?></a>
        <?php if ($car !== ''): ?><span class="dash-car"><?= e($car) ?></span><?php endif; ?>
      </td>
      <td class="dash-h-num tabular">$<?= money($r['total']) ?></td>
      <td><?= status_pill((string)$r['status']) ?></td>
      <td class="dash-h-wait"><?php if ($isWaiting[0]): ?><span class="dash-wait<?= $isWaiting[2] >= 4320 ? ' is-old' : '' ?>" title="Waiting <?= e($spoken) ?> since this booking arrived"><?= e($isWaiting[1]) ?></span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="5" class="text-xs">No bookings yet. <a class="underline" href="<?= url('admin/bookings.php?action=create') ?>">Create the first one</a>.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <!-- Needs attention -->
  <div class="card rounded-2xl p-5">
    <h2 class="font-display text-2xl">Needs attention</h2>
    <?php if ($alerts === 0): ?>
      <p class="text-sm text-[#AB8868] mt-3">All clear — nothing waiting on you.</p>
    <?php else: ?>
      <ul class="mt-3 space-y-3">
      <?php foreach ($attention as $a): if ($a['n'] <= 0) continue; ?>
        <li><a href="<?= url($a['url']) ?>" class="flex items-center justify-between gap-2 border border-[#2a2a2b] hover:border-[#C8A96B] rounded-xl px-4 py-3">
          <span class="text-sm"><?= e($a['label']) ?></span>
          <span class="tabular text-sm font-bold text-[#F3D4A6]"><?= (int)$a['n'] ?> →</span>
        </a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Dashboard | Admin';
$navActive = 'dashboard.php';
require APP_ROOT . '/views/layouts/admin.php';
