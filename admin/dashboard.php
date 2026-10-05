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
$recent = $pdo->query('SELECT booking_number, pickup_date, service_type, payment_status, total FROM bookings ORDER BY id DESC LIMIT 8')->fetchAll();
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

<div class="grid lg:grid-cols-[1fr_320px] gap-4 mt-4 items-start">
  <!-- Recent bookings -->
  <div class="card rounded-2xl p-5">
    <div class="flex items-center justify-between">
      <h2 class="font-display text-2xl">Latest bookings</h2>
      <a href="<?= url('admin/bookings.php') ?>" class="text-xs underline">View all</a>
    </div>
    <div class="table-wrap mt-2"><table class="data"><thead><tr><th>Number</th><th>Date</th><th>Service</th><th>Payment</th><th>Total</th></tr></thead><tbody>
    <?php foreach ($recent as $r): ?><tr>
      <td><a class="underline" href="<?= url('admin/bookings.php?action=view&n=' . $r['booking_number']) ?>"><?= e($r['booking_number']) ?></a></td>
      <td><?= e($r['pickup_date']) ?></td><td><?= e($r['service_type']) ?></td><td><?= e($r['payment_status']) ?></td><td class="tabular">$<?= money($r['total']) ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="5" class="text-xs">No bookings yet.</td></tr><?php endif; ?>
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
