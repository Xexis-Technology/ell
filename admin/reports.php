<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
require_role('admin');

// ---- the window, and the one immediately before it -------------------------
$today = date('Y-m-d');
$rawFrom = trim((string)($_GET['from'] ?? ''));
$rawTo = trim((string)($_GET['to'] ?? ''));

$valid = static function (string $d): bool {
    $p = DateTime::createFromFormat('!Y-m-d', $d);
    return $p !== false && $p->format('Y-m-d') === $d;
};

// Default to the last 30 days rather than the calendar month: this month is six
// days long in early October, which reads as "no business" rather than a window.
$from = $valid($rawFrom) ? $rawFrom : date('Y-m-d', strtotime('-29 days'));
$to = $valid($rawTo) ? $rawTo : $today;
if ($from > $to) {
    // A reversed range silently returns nothing, which reads as "no business".
    [$from, $to] = [$to, $from];
    $flipped = true;
} else {
    $flipped = false;
}
$days = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
$prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' day'));

$preset = (string)($_GET['preset'] ?? '');
// A plain list, because the query closures destructure it as [$from, $to].
$q = static fn(string $f, string $t): array => [$f, $t];
$windows = [
    '7' => ['Last 7 days', date('Y-m-d', strtotime('-6 days')), $today],
    '30' => ['Last 30 days', date('Y-m-d', strtotime('-29 days')), $today],
    'month' => ['This month', date('Y-m-01'), $today],
    'prev' => ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '90' => ['Last 90 days', date('Y-m-d', strtotime('-89 days')), $today],
];

// ---- money -----------------------------------------------------------------
// Net, not gross: refunds actually given back inside the window are subtracted,
// so a period that took money back does not read as a good period.
$money = static function (PDO $pdo, string $from, string $to) use ($q): array {
    [$f, $t] = $q($from, $to);

    $st = $pdo->prepare('SELECT COALESCE(SUM(p.amount),0) gross, COUNT(*) n
        FROM payments p JOIN bookings b ON b.id = p.booking_id
        WHERE DATE(p.paid_at) BETWEEN ? AND ? AND p.status IN ("paid","partially_refunded")');
    $st->execute([$f, $t]);
    $taken = $st->fetch();

    $st = $pdo->prepare('SELECT COALESCE(SUM(r.amount),0) back, COUNT(*) n
        FROM refunds r JOIN payments p ON p.id = r.payment_id
        WHERE DATE(r.created_at) BETWEEN ? AND ? AND r.status = "succeeded"');
    $st->execute([$f, $t]);
    $given = $st->fetch();

    $st = $pdo->prepare('SELECT COALESCE(SUM(total),0) t FROM bookings
        WHERE pickup_date BETWEEN ? AND ? AND status IN ("cancelled","refunded")');
    $st->execute([$f, $to]);
    $lost = (float)$st->fetchColumn();

    $st = $pdo->prepare('SELECT COALESCE(SUM(total),0) t, COUNT(*) n FROM bookings
        WHERE pickup_date BETWEEN ? AND ? AND payment_status IN ("pending","failed")');
    $st->execute([$f, $t]);
    $outstanding = $st->fetch();

    // Net per day, on the same payment-date axis as the figures above, so the
    // strip and the headline can never disagree. A refund-only day reads negative.
    $st = $pdo->prepare('SELECT dy, SUM(v) net FROM (
        SELECT DATE(paid_at) dy, SUM(amount) v FROM payments
            WHERE status IN ("paid","partially_refunded") GROUP BY DATE(paid_at)
        UNION ALL
        SELECT DATE(created_at) dy, -SUM(amount) v FROM refunds
            WHERE status = "succeeded" GROUP BY DATE(created_at)
    ) x WHERE dy BETWEEN ? AND ? GROUP BY dy ORDER BY dy');
    $st->execute([$f, $t]);
    $series = $st->fetchAll();

    return [
        'taken' => (float)$taken['gross'], 'taken_n' => (int)$taken['n'],
        'series' => $series,
        'given' => (float)$given['back'], 'given_n' => (int)$given['n'],
        'net' => round((float)$taken['gross'] - (float)$given['back'], 2),
        'lost' => round($lost, 2),
        'outstanding' => round((float)$outstanding['t'], 2),
        'outstanding_n' => (int)$outstanding['n'],
        'avg' => (int)$taken['n'] > 0 ? round((float)$taken['gross'] / (int)$taken['n'], 2) : 0.0,
    ];
};
$moneyNow = $money($pdo, $from, $to);
$moneyPrev = $money($pdo, $prevFrom, $prevTo);

// Walk every day of the window so quiet days show as gaps rather than
// disappearing. Very wide windows keep the most recent slice.
$stripDays = min(120, max(1, $days));
$netStrip = [];
for ($i = $stripDays - 1; $i >= 0; $i--) {
    $netStrip[date('Y-m-d', strtotime($to . " -{$i} day"))] = 0.0;
}
$netMoved = 0;
foreach ($moneyNow['series'] as $sg) {
    $d = (string)$sg['dy'];
    if (isset($netStrip[$d])) {
        $netStrip[$d] = (float)$sg['net'];
        $netMoved++;
    }
}
$netPeak = 0.0;
foreach ($netStrip as $nv) $netPeak = max($netPeak, abs($nv));

// ---- work ------------------------------------------------------------------
$work = static function (PDO $pdo, string $from, string $to) use ($q): array {
    [$f, $t] = $q($from, $to);
    $out = ['booked' => 0, 'finished' => 0, 'cancelled' => 0, 'awaiting' => 0, 'pax' => 0, 'miles' => 0.0, 'hours' => 0.0];

    $st = $pdo->prepare('SELECT
        COUNT(*) booked,
        SUM(status = "finish") finished,
        SUM(status IN ("cancelled","refunded")) cancelled,
        SUM(status IN ("awaiting_pricing","pricing_finalized","pending_payment","payment_failed")) awaiting,
        COALESCE(SUM(passengers),0) pax,
        COALESCE(SUM(mileage),0) miles,
        COALESCE(SUM(hours),0) hours
        FROM bookings WHERE pickup_date BETWEEN ? AND ?');
    $st->execute([$f, $t]);
    $r = $st->fetch();
    foreach ($out as $k => $_) $out[$k] = $k === 'booked' || $k === 'pax' ? (int)$r[$k] : (float)$r[$k];

    $st = $pdo->prepare('SELECT service_type, COUNT(*) c, COALESCE(SUM(total),0) t
        FROM bookings WHERE pickup_date BETWEEN ? AND ? GROUP BY service_type ORDER BY c DESC');
    $st->execute([$f, $t]);
    $services = $st->fetchAll();

    $st = $pdo->prepare('SELECT status, COUNT(*) c, COALESCE(SUM(total),0) t
        FROM bookings WHERE pickup_date BETWEEN ? AND ? GROUP BY status ORDER BY c DESC');
    $st->execute([$f, $t]);
    $states = $st->fetchAll();

    // Busiest day, so a quiet month can be traced to a single Saturday.
    $st = $pdo->prepare('SELECT pickup_date d, COUNT(*) c FROM bookings
        WHERE pickup_date BETWEEN ? AND ? GROUP BY pickup_date ORDER BY c DESC, d ASC LIMIT 5');
    $st->execute([$f, $t]);
    $days = $st->fetchAll();

    $st = $pdo->prepare('SELECT COUNT(*) c FROM bookings
        WHERE pickup_date BETWEEN ? AND ? AND pickup_date = DATE_ADD(?, INTERVAL 1 DAY)');
    $st->execute([$f, $t, $t]);
    $tomorrow = (int)$st->fetchColumn();

    return ['tot' => $out, 'services' => $services, 'states' => $states, 'busiest' => $days, 'tomorrow' => $tomorrow];
};
$workNow = $work($pdo, $from, $to);
$workPrev = $work($pdo, $prevFrom, $prevTo);

// ---- people ----------------------------------------------------------------
$people = static function (PDO $pdo, string $from, string $to) use ($q): array {
    [$f, $t] = $q($from, $to);
    $st = $pdo->prepare('SELECT d.id, d.name, d.reference,
            COUNT(e.id) trips,
            COALESCE(SUM(e.gross_amount),0) gross,
            COALESCE(SUM(e.driver_amount),0) share,
            COUNT(CASE WHEN e.status = "unpaid" THEN 1 END) open
        FROM driver_earnings e JOIN drivers d ON d.id = e.driver_id
        WHERE DATE(e.earned_at) BETWEEN ? AND ?
        GROUP BY d.id, d.name, d.reference
        ORDER BY gross DESC');
    $st->execute([$f, $t]);
    $rows = $st->fetchAll();
    foreach ($rows as $i => $r) {
        $gross = (float)$r['gross'];
        $rows[$i]['pct'] = $gross > 0 ? round((float)$r['share'] / $gross * 100, 1) : 0.0;
    }
    return $rows;
};
$peopleNow = $people($pdo, $from, $to);
$peoplePrev = $people($pdo, $prevFrom, $prevTo);

$vehiclesNow = [];
$veh = $pdo->prepare('SELECT v.id, v.make, v.model, v.slug,
        COUNT(CASE WHEN b.status = "finish" THEN 1 END) trips,
        COALESCE(SUM(CASE WHEN b.status = "finish" THEN b.total ELSE 0 END),0) earned,
        COALESCE(SUM(CASE WHEN b.status = "finish" THEN b.mileage ELSE 0 END),0) miles
    FROM vehicles v
    LEFT JOIN bookings b ON b.vehicle_id = v.id AND b.pickup_date BETWEEN ? AND ?
    WHERE v.status <> "inactive"
    GROUP BY v.id, v.make, v.model, v.slug
    ORDER BY earned DESC, trips DESC, v.make, v.model');
$veh->execute([$from, $to]);
$vehiclesNow = $veh->fetchAll();
$vehiclesPrev = [];
$vehPrev = $pdo->prepare('SELECT vehicle_id, COALESCE(SUM(CASE WHEN status = "finish" THEN total ELSE 0 END),0) t
    FROM bookings WHERE status = "finish" AND pickup_date BETWEEN ? AND ? GROUP BY vehicle_id');
$vehPrev->execute([$prevFrom, $prevTo]);
foreach ($vehPrev->fetchAll() as $r) {
    $vehiclesPrev[(int)$r['vehicle_id']] = (float)$r['t'];
}

$tab = in_array($_GET['tab'] ?? '', ['money', 'work', 'people', 'fleet'], true) ? $_GET['tab'] : 'money';
$link = static fn(string $extra): string => '?' . http_build_query(['tab' => $extra, 'from' => $from, 'to' => $to]);

$tabs = [
    'money' => ['Money', 'Taken in, given back, net'],
    'work' => ['Work', 'Bookings, days, distances'],
    'people' => ['Chauffeurs', 'Who earned what'],
    'fleet' => ['Cars', 'Which car earns its keep'],
];

/** "up $310" / "down $80" / "level" — plain words, never a bare percentage. */
$delta = static function (float $now, float $was): array {
    $d = round($now - $was, 2);
    $dir = $d > 0.005 ? 'up' : ($d < -0.005 ? 'down' : 'level');
    return ['dir' => $dir, 'amount' => abs($d), 'text' => $dir === 'level' ? 'level with the period before' : ($dir === 'up' ? 'up' : 'down') . ' $' . money(abs($d))];
};

/** Same, for counts. A booking count has no dollar sign. */
$deltaN = static function (float $now, float $was): array {
    $d = (int)round($now) - (int)round($was);
    $dir = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'level');
    return ['dir' => $dir, 'amount' => abs($d), 'text' => $dir === 'level' ? 'level with the period before' : abs($d) . ' ' . ($dir === 'up' ? 'more' : 'fewer') . ' than the period before'];
};

$fmtRange = static fn(string $f, string $t): string => ($f === $t ? date('j M', strtotime($f)) : date('j M', strtotime($f)) . ' – ' . date('j M', strtotime($t)));

ob_start();
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Money</p>
    <h1 class="font-display text-3xl mt-1">Reports</h1>
    <?php if ($flipped): ?><p class="rp-note">Dates were the wrong way round, so they have been swapped.</p><?php endif; ?>
  </div>
</div>

<form method="get" class="rp-window" aria-label="Reporting period">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="rp-when">
    <label class="pz-sr" for="rp-from">From</label>
    <input id="rp-from" type="date" name="from" class="input" max="<?= e($today) ?>" value="<?= e($from) ?>">
    <span class="rp-arrow" aria-hidden="true">&rarr;</span>
    <label class="pz-sr" for="rp-to">To</label>
    <input id="rp-to" type="date" name="to" class="input" max="<?= e($today) ?>" value="<?= e($to) ?>">
    <button class="rp-go">Show this period</button>
  </div>
  <div class="rp-presets">
    <?php foreach ($windows as $key => [$label, $pf, $pt]): ?>
      <a class="rp-preset" href="<?= url('admin/reports.php?tab=' . $tab . '&from=' . $pf . '&to=' . $pt) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <p class="rp-compare">Every figure is compared with <b><?= e($fmtRange($prevFrom, $prevTo)) ?></b>, the <?= $days ?> <?= $days === 1 ? 'day' : 'days' ?> before.</p>
</form>

<nav class="pz-tabs" aria-label="Report sections">
  <?php foreach ($tabs as $key => [$label, $sub]): ?>
    <a class="pz-tab<?= $tab === $key ? ' is-on' : '' ?>" href="<?= url('admin/reports.php' . $link($key)) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>>
      <span class="pz-tab-l"><?= e($label) ?></span>
      <span class="pz-tab-s"><?= e($sub) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'money'):
    $dNet = $delta($moneyNow['net'], $moneyPrev['net']);
    $dAvg = $delta($moneyNow['avg'], $moneyPrev['avg']); ?>
  <section class="rp-hero" aria-labelledby="rp-net-h">
    <div class="rp-net">
      <p class="rp-net-k">Net income &mdash; <?= e($fmtRange($from, $to)) ?></p>
      <p class="rp-net-v">$<?= money($moneyNow['net']) ?></p>
      <p class="rp-net-d is-<?= e($dNet['dir']) ?>"><?= e($dNet['text']) ?></p>
      <p class="rp-net-s"><?= $moneyNow['taken_n'] ?> <?= $moneyNow['taken_n'] === 1 ? 'payment' : 'payments' ?> taken<?= $moneyNow['given'] > 0 ? ', minus ' . $moneyNow['given_n'] . ' ' . ($moneyNow['given_n'] === 1 ? 'refund' : 'refunds') : '' ?></p>

      <?php if ($netMoved > 0): ?>
      <div class="rp-strip" role="img" aria-label="Net income day by day across <?= e($fmtRange($from, $to)) ?>">
        <?php foreach ($netStrip as $ndy => $nv): ?>
        <span class="rp-slot<?= $nv < 0 ? ' is-out' : '' ?>" title="<?= e(date('D j M', strtotime((string)$ndy))) ?> &mdash; $<?= money($nv) ?>"><i style="height:<?= $netPeak > 0 ? max(2, (int)round(abs($nv) / $netPeak * 100)) : 0 ?>%"></i></span>
        <?php endforeach; ?>
      </div>
      <p class="rp-strip-k">Net by day &mdash; money moved on <b><?= $netMoved ?></b> of <?= $stripDays ?> days</p>
      <?php endif; ?>
    </div>
    <div class="rp-side">
      <div class="rp-fig">
        <p class="rp-fig-k">Taken in</p>
        <p class="rp-fig-v">$<?= money($moneyNow['taken']) ?></p>
        <p class="rp-fig-s"><?= e($delta($moneyNow['taken'], $moneyPrev['taken'])['text']) ?></p>
      </div>
      <div class="rp-fig<?= $moneyNow['given'] > 0 ? ' is-out' : '' ?>">
        <p class="rp-fig-k">Given back</p>
        <p class="rp-fig-v"><?= $moneyNow['given'] > 0 ? '−$' . money($moneyNow['given']) : '$0.00' ?></p>
        <p class="rp-fig-s"><?= $moneyNow['given'] > 0 ? $moneyNow['given_n'] . ' ' . ($moneyNow['given_n'] === 1 ? 'refund' : 'refunds') : 'nothing refunded' ?></p>
      </div>
      <div class="rp-fig">
        <p class="rp-fig-k">Average per payment</p>
        <p class="rp-fig-v">$<?= money($moneyNow['avg']) ?></p>
        <p class="rp-fig-s"><?= e($dAvg['text']) ?></p>
      </div>
      <div class="rp-fig<?= $moneyNow['outstanding'] > 0 ? ' is-wait' : '' ?>">
        <p class="rp-fig-k">Booked, not paid</p>
        <p class="rp-fig-v">$<?= money($moneyNow['outstanding']) ?></p>
        <p class="rp-fig-s"><?= $moneyNow['outstanding_n'] ?> <?= $moneyNow['outstanding_n'] === 1 ? 'booking' : 'bookings' ?> in this period</p>
      </div>
      <div class="rp-fig<?= $moneyNow['lost'] > 0 ? ' is-out' : '' ?>">
        <p class="rp-fig-k">Cancelled work</p>
        <p class="rp-fig-v">$<?= money($moneyNow['lost']) ?></p>
        <p class="rp-fig-s"><?= $moneyNow['lost'] > 0 ? 'booked then dropped' : 'none dropped' ?></p>
      </div>
    </div>
  </section>

  <p class="rp-hint">Net subtracts refunds actually given back inside this period. A period that returned money reads lower here than gross would suggest &mdash; which is the honest figure.</p>

<?php elseif ($tab === 'work'):
    $dBooked = $deltaN((float)$workNow['tot']['booked'], (float)$workPrev['tot']['booked']);
    $dFin = $deltaN((float)$workNow['tot']['finished'], (float)$workPrev['tot']['finished']); ?>
  <div class="rp-band">
    <div class="rp-bfig">
      <p class="rp-bfig-k">Booked</p>
      <p class="rp-bfig-v"><?= (int)$workNow['tot']['booked'] ?></p>
      <p class="rp-bfig-d is-<?= e($dBooked['dir']) ?>"><?= e($dBooked['text']) ?></p>
    </div>
    <div class="rp-bfig">
      <p class="rp-bfig-k">Completed</p>
      <p class="rp-bfig-v"><?= (int)$workNow['tot']['finished'] ?></p>
      <p class="rp-bfig-d is-<?= e($dFin['dir']) ?>"><?= e($dFin['text']) ?></p>
    </div>
    <div class="rp-bfig">
      <p class="rp-bfig-k">Still open</p>
      <p class="rp-bfig-v"><?= (int)$workNow['tot']['awaiting'] ?></p>
      <p class="rp-bfig-d">not yet finished</p>
    </div>
    <div class="rp-bfig<?= (int)$workNow['tot']['cancelled'] > 0 ? ' is-out' : '' ?>">
      <p class="rp-bfig-k">Cancelled</p>
      <p class="rp-bfig-v"><?= (int)$workNow['tot']['cancelled'] ?></p>
      <p class="rp-bfig-d">booked then dropped</p>
    </div>
    <div class="rp-bfig">
      <p class="rp-bfig-k">Miles driven</p>
      <p class="rp-bfig-v"><?= number_format((float)$workNow['tot']['miles'], (float)$workNow['tot']['miles'] == (int)(float)$workNow['tot']['miles'] ? 0 : 1) ?></p>
      <p class="rp-bfig-d"><?= e($delta((float)$workNow['tot']['miles'], (float)$workPrev['tot']['miles'])['text']) ?></p>
    </div>
    <div class="rp-bfig">
      <p class="rp-bfig-k">Hours hired</p>
      <p class="rp-bfig-v"><?= number_format((float)$workNow['tot']['hours'], (float)$workNow['tot']['hours'] == (int)(float)$workNow['tot']['hours'] ? 0 : 1) ?></p>
      <p class="rp-bfig-d"><?= e($delta((float)$workNow['tot']['hours'], (float)$workPrev['tot']['hours'])['text']) ?></p>
    </div>
  </div>

  <div class="rp-two">
    <section class="rp-sec">
      <h2 class="pz-h">By service</h2>
      <?php if (!$workNow['services']): ?>
        <p class="rp-blank">No bookings were made in this period.</p>
      <?php else: ?>
        <div class="table-wrap pz-table-wrap">
          <table class="data pz-table rp-table">
            <thead><tr><th>Service</th><th>Booked</th><th>Value</th><th>Share</th></tr></thead>
            <tbody>
            <?php $svcTotal = (float)array_sum(array_column($workNow['services'], 't')); ?>
            <?php foreach ($workNow['services'] as $s):
              $share = $svcTotal > 0 ? (float)$s['t'] / $svcTotal * 100 : 0; ?>
              <tr>
                <th scope="row"><?= e(ucfirst(str_replace('_', ' ', (string)$s['service_type']))) ?></th>
                <td class="pz-num" data-l="Booked"><?= (int)$s['c'] ?></td>
                <td class="pz-num" data-l="Value">$<?= money($s['t']) ?></td>
                <td class="rp-share" data-l="Share"><i style="width:<?= e((string)round($share, 1)) ?>%"></i><span><?= e(rtrim(rtrim(number_format($share, 1), '0'), '.')) ?>%</span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="rp-sec">
      <h2 class="pz-h">Where every booking sits</h2>
      <p class="rp-lede">Grey means booked but not yet turned into a ride &mdash; awaiting pricing or payment, not lost.</p>
      <?php if (!$workNow['states']): ?>
        <p class="rp-blank">No bookings were made in this period.</p>
      <?php else: ?>
        <ul class="rp-states">
          <?php foreach ($workNow['states'] as $s): ?>
            <li class="<?= $s['status'] === 'finish' ? 'is-done' : (in_array($s['status'], ['cancelled', 'refunded'], true) ? 'is-out' : '') ?>">
              <span class="rp-st-k"><?= e(ucfirst(str_replace('_', ' ', (string)$s['status']))) ?></span>
              <span class="rp-st-c"><?= (int)$s['c'] ?></span>
              <span class="rp-st-t">$<?= money($s['t']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <section class="rp-sec">
    <h2 class="pz-h">Busiest days</h2>
    <?php if (!$workNow['busiest']): ?>
      <p class="rp-blank">No bookings to rank in this period.</p>
    <?php else: ?>
      <ul class="rp-days">
        <?php $peak = (int)$workNow['busiest'][0]['c']; foreach ($workNow['busiest'] as $d): ?>
          <li>
            <span class="rp-day-d"><?= e(date('D j M', strtotime((string)$d['d']))) ?></span>
            <span class="rp-day-bar"><i style="width:<?= (int)round((int)$d['c'] / max(1, $peak) * 100) ?>%"></i></span>
            <span class="rp-day-c"><?= (int)$d['c'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($workNow['tomorrow'] > 0): ?>
      <p class="rp-flag"><?= $workNow['tomorrow'] ?> <?= $workNow['tomorrow'] === 1 ? 'booking' : 'bookings' ?> already booked for tomorrow.</p>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'people'):
    $prevBy = [];
    foreach ($peoplePrev as $r) $prevBy[(int)$r['id']] = (float)$r['gross']; ?>
  <div class="rp-col">
    <?php if (!$peopleNow): ?>
      <div class="rp-blank-card">
        <p class="rp-blank-h">No chauffeur earnings in this period</p>
        <p>A trip earns its split when it is finished, so this fills in as rides complete. <a href="<?= url('admin/earnings.php') ?>">See the earnings ledger</a> for who is owed.</p>
      </div>
    <?php else: ?>
      <p class="rp-lede">Gross is the fare; the chauffeur's share is what they keep. The bar shows their cut of their own fare.</p>
      <ul class="rp-people">
        <?php foreach ($peopleNow as $r):
          $was = $prevBy[(int)$r['id']] ?? 0.0;
          $dd = $delta((float)$r['gross'], $was); ?>
          <li class="rp-person">
            <div class="rp-person-top">
              <span class="rp-person-n"><?= e($r['name']) ?></span>
              <?php if ($r['reference']): ?><code class="rp-person-r"><?= e($r['reference']) ?></code><?php endif; ?>
              <?php if ((int)$r['open'] > 0): ?><span class="rp-open"><?= (int)$r['open'] ?> unpaid</span><?php endif; ?>
              <span class="rp-person-v">$<?= money($r['gross']) ?></span>
            </div>
            <span class="rp-bar" role="img" aria-label="Chauffeur keeps <?= e(rtrim(rtrim(number_format((float)$r['pct'], 1), '0'), '.')) ?> percent, $<?= money($r['share']) ?> of $<?= money($r['gross']) ?>">
              <i style="width:<?= e((string)$r['pct']) ?>%"></i>
            </span>
            <p class="rp-person-s">
              <?= (int)$r['trips'] ?> <?= (int)$r['trips'] === 1 ? 'trip' : 'trips' ?>
              &middot; keeps <?= e(rtrim(rtrim(number_format((float)$r['pct'], 1), '0'), '.')) ?>% ($<?= money($r['share']) ?>)
              &middot; <?= e($dd['text']) ?>
            </p>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div class="rp-col">
    <p class="rp-lede">Which car actually turns up and earns. A car that sits idle still costs you its share of the fleet.</p>
    <div class="table-wrap pz-table-wrap">
      <table class="data pz-table rp-table">
        <thead><tr><th>Car</th><th>Trips</th><th>Earned</th><th>Miles</th><th>Against last period</th></tr></thead>
        <tbody>
        <?php foreach ($vehiclesNow as $v):
          $was = $vehiclesPrev[(int)$v['id']] ?? 0.0;
          $dd = $delta((float)$v['earned'], $was); ?>
          <tr>
            <th scope="row">
              <a class="rp-car" href="<?= url('services/fleet.php?vehicle=' . urlencode((string)$v['slug'])) ?>" target="_blank" rel="noopener"><?= e($v['make'] . ' ' . $v['model']) ?></a>
            </th>
            <td class="pz-num" data-l="Trips"><?= (int)$v['trips'] ?></td>
            <td class="pz-num" data-l="Earned">$<?= money($v['earned']) ?></td>
            <td class="pz-num" data-l="Miles"><?= number_format((float)$v['miles'], 0) ?></td>
            <td class="rp-dd is-<?= e($dd['dir']) ?>" data-l="Against last period"><?= e($dd['text']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
$pageTitle = 'Reports | Admin';
$navActive = 'reports.php';
require APP_ROOT . '/views/layouts/admin.php';