<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;

/** Normalise a coupon/charge code the way the tables index it. */
$cleanCode = static fn(string $raw): string => preg_replace('/[^a-z_]/', '', strtolower(trim($raw))) ?? '';
$chargeTypes = [
    'flat' => 'Flat — charged once',
    'per_unit' => 'Per unit — charged per quantity',
    'per_mile' => 'Per mile — scales with distance',
    'per_hour' => 'Per hour — scales with time',
];
$dtLocal = static fn(string $v): ?string => ($v = trim($v)) !== '' ? str_replace('T', ' ', $v) . ':00' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    $tab = in_array($_POST['tab'] ?? '', ['fares', 'charges', 'coupons', 'waiting'], true) ? $_POST['tab'] : 'fares';
    $isErr = false;

    if ($op === 'mode') {
        $mode = ($_POST['pricing_mode'] ?? '') === 'hourly' ? 'hourly' : 'per_mile';
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute(['pricing_mode', $mode]);
        audit($pdo, 'admin', (int)$admin['id'], 'pricing.mode', null, null, ['mode' => $mode]);
        $msg = 'Pricing mode set to ' . ($mode === 'hourly' ? 'hourly' : 'per-mile') . '.';
    } elseif ($op === 'tax') {
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute(['tax_percent', (string)max(0, (float)($_POST['tax_percent'] ?? 0))]);
        $msg = 'Tax updated.';

        // ---- additional charges --------------------------------------------
    } elseif ($op === 'charge') {
        $code = $cleanCode((string)($_POST['code'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $ct = in_array($_POST['calculation_type'] ?? '', array_keys($chargeTypes), true) ? $_POST['calculation_type'] : 'flat';
        $amount = (float)($_POST['amount'] ?? 0);
        if ($code === '' || $name === '') {
            $msg = 'A charge needs a code and a name.';
            $isErr = true;
            $tab = 'charges';
        } elseif ($amount < 0) {
            $msg = 'A rate cannot be negative.';
            $isErr = true;
            $tab = 'charges';
        } else {
            $pdo->prepare('INSERT INTO additional_charge_types (code, name, calculation_type, amount, active) VALUES (?,?,?,?,1)
                ON DUPLICATE KEY UPDATE name=VALUES(name), calculation_type=VALUES(calculation_type), amount=VALUES(amount), active=1')
                ->execute([$code, $name, $ct, $amount]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.charge_saved', 'pricing_charge', 0, ['code' => $code, 'name' => $name, 'amount' => $amount]);
            $msg = ucfirst(str_replace('_', ' ', $name)) . ' saved.';
            $tab = 'charges';
        }
    } elseif ($op === 'charge_edit') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $pdo->query('SELECT * FROM additional_charge_types WHERE id = ' . $id)->fetch();
        $name = trim((string)($_POST['name'] ?? ''));
        $ct = in_array($_POST['calculation_type'] ?? '', array_keys($chargeTypes), true) ? $_POST['calculation_type'] : 'flat';
        $amount = (float)($_POST['amount'] ?? 0);
        if (!$cur) {
            $msg = 'That charge is already gone.';
            $isErr = true;
        } elseif ($name === '') {
            $msg = 'A charge needs a name.';
            $isErr = true;
        } elseif ($amount < 0) {
            $msg = 'A rate cannot be negative.';
            $isErr = true;
        } else {
            // The code stays put: bookings already reference it by code.
            $pdo->prepare('UPDATE additional_charge_types SET name=?, calculation_type=?, amount=?, active=? WHERE id=?')
                ->execute([$name, $ct, $amount, isset($_POST['active']) ? 1 : 0, $id]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.charge_updated', 'pricing_charge', $id, ['name' => $name, 'amount' => $amount]);
            $msg = ucfirst(str_replace('_', ' ', $name)) . ' updated.';
        }
        $tab = 'charges';
    } elseif ($op === 'charge_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $pdo->query('SELECT * FROM additional_charge_types WHERE id = ' . $id)->fetch();
        if (!$cur) {
            $msg = 'That charge is already gone.';
            $isErr = true;
        } else {
            $pdo->prepare('DELETE FROM additional_charge_types WHERE id = ?')->execute([$id]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.charge_deleted', 'pricing_charge', $id, ['code' => $cur['code'], 'name' => $cur['name']]);
            $msg = $cur['name'] . ' removed.';
        }
        $tab = 'charges';

        // ---- coupons --------------------------------------------------------
    } elseif ($op === 'coupon') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $type = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $value = (float)($_POST['value'] ?? 0);
        $min = max(0, (float)($_POST['minimum_subtotal'] ?? 0));
        $used = $_POST['usage_limit'] !== '' ? (int)$_POST['usage_limit'] : null;
        $per = $_POST['customer_limit'] !== '' ? (int)$_POST['customer_limit'] : null;
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,32}$/', $code)) {
            $msg = 'Use 2–32 letters, numbers, dashes or underscores for the code.';
            $isErr = true;
            $tab = 'coupons';
        } elseif ($value <= 0) {
            $msg = 'Give the discount a value above zero.';
            $isErr = true;
            $tab = 'coupons';
        } elseif ($type === 'percent' && $value > 100) {
            $msg = 'A percentage discount cannot be over 100.';
            $isErr = true;
            $tab = 'coupons';
        } else {
            $pdo->prepare('INSERT INTO coupons (code, type, value, minimum_subtotal, usage_limit, customer_limit, starts_at, expires_at, active) VALUES (?,?,?,?,?,?,?,?,1)
                ON DUPLICATE KEY UPDATE type=VALUES(type), value=VALUES(value), minimum_subtotal=VALUES(minimum_subtotal), usage_limit=VALUES(usage_limit), customer_limit=VALUES(customer_limit), starts_at=VALUES(starts_at), expires_at=VALUES(expires_at), active=1')
                ->execute([$code, $type, $value, $min, $used, $per, $dtLocal((string)($_POST['starts_at'] ?? '')), $dtLocal((string)($_POST['expires_at'] ?? ''))]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.coupon_saved', 'coupon', 0, ['code' => $code, 'value' => $value, 'type' => $type]);
            $msg = $code . ' is ready to use.';
            $tab = 'coupons';
        }
    } elseif ($op === 'coupon_edit') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $pdo->query('SELECT * FROM coupons WHERE id = ' . $id)->fetch();
        $type = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $value = (float)($_POST['value'] ?? 0);
        if (!$cur) {
            $msg = 'That coupon is already gone.';
            $isErr = true;
        } elseif ($value <= 0) {
            $msg = 'Give the discount a value above zero.';
            $isErr = true;
        } elseif ($type === 'percent' && $value > 100) {
            $msg = 'A percentage discount cannot be over 100.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE coupons SET type=?, value=?, minimum_subtotal=?, usage_limit=?, customer_limit=?, starts_at=?, expires_at=?, active=? WHERE id=?')
                ->execute([
                    $type, $value, max(0, (float)($_POST['minimum_subtotal'] ?? 0)),
                    $_POST['usage_limit'] !== '' ? (int)$_POST['usage_limit'] : null,
                    $_POST['customer_limit'] !== '' ? (int)$_POST['customer_limit'] : null,
                    $dtLocal((string)($_POST['starts_at'] ?? '')), $dtLocal((string)($_POST['expires_at'] ?? '')),
                    isset($_POST['active']) ? 1 : 0, $id,
                ]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.coupon_updated', 'coupon', $id, ['value' => $value, 'type' => $type]);
            $msg = $cur['code'] . ' updated.';
        }
        $tab = 'coupons';
    } elseif ($op === 'coupon_toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE coupons SET active = 1 - active WHERE id = ?')->execute([$id]);
        $c = $pdo->query('SELECT code, active FROM coupons WHERE id = ' . $id)->fetch();
        if ($c) {
            $msg = $c['code'] . (int)$c['active'] ? ' resumed.' : ' paused.';
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.coupon_toggled', 'coupon', $id, ['active' => (int)$c['active']]);
        }
        $tab = 'coupons';
    } elseif ($op === 'coupon_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $pdo->query('SELECT * FROM coupons WHERE id = ' . $id)->fetch();
        // bookings.coupon_id and coupon_redemptions.coupon_id both reference this
        // row, so a code that has ever been redeemed can only be paused.
        $inUse = (int)$pdo->query('SELECT COUNT(*) c FROM coupon_redemptions WHERE coupon_id = ' . $id)->fetchColumn()
            + (int)$pdo->query('SELECT COUNT(*) c FROM bookings WHERE coupon_id = ' . $id)->fetchColumn();
        if (!$cur) {
            $msg = 'That coupon is already gone.';
            $isErr = true;
        } elseif ($inUse > 0) {
            $msg = $cur['code'] . ' has been used on ' . $inUse . ($inUse === 1 ? ' booking' : ' bookings') . '. Pause it instead so the record stays intact.';
            $isErr = true;
        } else {
            $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.coupon_deleted', 'coupon', $id, ['code' => $cur['code']]);
            $msg = $cur['code'] . ' deleted.';
        }
        $tab = 'coupons';

        // ---- waiting rules ---------------------------------------------------
    } elseif ($op === 'waiting') {
        $cat = $cleanCode((string)($_POST['category'] ?? ''));
        if ($cat === '') {
            $msg = 'Give the waiting rule a trip type.';
            $isErr = true;
        } else {
            $pdo->prepare('INSERT INTO waiting_rules (category, free_minutes, charge_interval_minutes, charge_per_interval, active) VALUES (?,?,?,?,1)
                ON DUPLICATE KEY UPDATE free_minutes=VALUES(free_minutes), charge_interval_minutes=VALUES(charge_interval_minutes), charge_per_interval=VALUES(charge_per_interval), active=1')
                ->execute([$cat, max(0, (int)($_POST['free_minutes'] ?? 0)), max(1, (int)($_POST['charge_interval_minutes'] ?? 10)), max(0, (float)($_POST['charge_per_interval'] ?? 0))]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.waiting_saved', 'waiting_rule', 0, ['category' => $cat]);
            $msg = ucfirst(str_replace('_', ' ', $cat)) . ' waiting rule saved.';
            $tab = 'waiting';
        }
    } elseif ($op === 'waiting_edit') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $pdo->query('SELECT * FROM waiting_rules WHERE id = ' . $id)->fetch();
        if (!$cur) {
            $msg = 'That waiting rule is already gone.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE waiting_rules SET free_minutes=?, charge_interval_minutes=?, charge_per_interval=?, active=? WHERE id=?')
                ->execute([max(0, (int)($_POST['free_minutes'] ?? 0)), max(1, (int)($_POST['charge_interval_minutes'] ?? 10)), max(0, (float)($_POST['charge_per_interval'] ?? 0)), isset($_POST['active']) ? 1 : 0, $id]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.waiting_updated', 'waiting_rule', $id, null);
            $msg = ucfirst(str_replace('_', ' ', (string)$cur['category'])) . ' waiting rule updated.';
        }
        $tab = 'waiting';
    } elseif ($op === 'waiting_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $pdo->query('SELECT * FROM waiting_rules WHERE id = ' . $id)->fetch();
        if (!$cur) {
            $msg = 'That waiting rule is already gone.';
            $isErr = true;
        } else {
            // CloseSession reads the rule by category; without one it falls back
            // to a 15-minute default, so deleting is safe but does change the
            // bill for that trip type. Say so rather than deleting silently.
            $pdo->prepare('DELETE FROM waiting_rules WHERE id = ?')->execute([$id]);
            audit($pdo, 'admin', (int)$admin['id'], 'pricing.waiting_deleted', 'waiting_rule', $id, ['category' => $cur['category']]);
            $msg = ucfirst(str_replace('_', ' ', (string)$cur['category'])) . ' rule removed. Waiting on that trip type now falls back to 15 free minutes, billed every 10.';
        }
        $tab = 'waiting';

        // ---- close a live waiting session ------------------------------------
    } elseif ($op === 'waiting_close') {
        $r = WaitingService::closeSession($pdo, (int)$_POST['booking_id'], $_POST['category'] ?? 'point_to_point', (int)$_POST['waited_minutes'], (int)$admin['id']);
        if (isset($r['error'])) {
            $msg = $r['error'];
            $isErr = true;
        } else {
            $msg = 'Waiting session closed. Charge $' . money($r['charge']) . ' added as pending invoice line.';
            if ($r['charge'] > 0) {
                $wb = $pdo->query('SELECT * FROM bookings WHERE id = ' . (int)$_POST['booking_id'])->fetch();
                if ($wb) {
                    $wemail = $wb['guest_email'];
                    if ($wb['customer_id']) $wemail = $pdo->query('SELECT email FROM customers WHERE id = ' . $wb['customer_id'])->fetch()['email'] ?? $wemail;
                    if ($wemail) NotificationService::bookingEmail($pdo, 'waiting-charge', $wb, $wemail, $wb['customer_id'], $wb['customer_id'] ? 'customer' : 'guest', ['html' => '<p>A waiting charge of <strong>$' . money($r['charge']) . '</strong> was added to booking <strong>' . e($wb['booking_number']) . '</strong>. It will be collected via pending invoice — no automatic card charge was made.</p>']);
                }
            }
        }
        $tab = 'waiting';
    }

    header('Location: ' . url('admin/pricing.php?tab=' . $tab . ($msg !== '' ? '&' . ($isErr ? 'err=1&' : '') . 'msg=' . urlencode($msg) : '')));
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
$isErr = isset($_GET['err']);
$tab = in_array($_GET['tab'] ?? '', ['fares', 'charges', 'coupons', 'waiting'], true) ? $_GET['tab'] : 'fares';
$editId = (int)($_GET['edit'] ?? 0);

$charges = $pdo->query('SELECT * FROM additional_charge_types ORDER BY code')->fetchAll();

$coupons = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id) AS redemptions,
        (SELECT COUNT(*) FROM bookings b WHERE b.coupon_id = c.id) AS booked
    FROM coupons c ORDER BY c.active DESC, c.code LIMIT 200')->fetchAll();

$rules = $pdo->query('SELECT * FROM waiting_rules ORDER BY category')->fetchAll();

$openWaiting = (int)$pdo->query('SELECT COUNT(*) c FROM waiting_sessions WHERE status = "active"')->fetchColumn();

/** For a datetime-local input: the stored value as Y-m-d\TH:i. */
$toLocal = static function (?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    return $t ? date('Y-m-d\TH:i', $t) : '';
};

// ---- the fare meter: real cars, real rates, real engine ----------------------
$mode = PricingService::activeMode($pdo);
$taxPct = (float)setting($pdo, 'tax_percent', '0');
$fleet = $pdo->query('SELECT v.id, v.make, v.model, v.passenger_capacity, v.luggage_capacity, v.slug,
        pr.per_mile_rate, pr.hourly_rate
    FROM vehicles v
    LEFT JOIN pricing_rates pr ON pr.vehicle_id = v.id AND pr.active = 1
    WHERE v.status <> "inactive"
    ORDER BY v.make, v.model')->fetchAll();

$chargeBy = [];
foreach ($charges as $c) if ((int)$c['active'] === 1) $chargeBy[$c['code']] = $c;

$quoteFor = static function (int $vehicleId, float $qty, array $extras, string $coupon) use ($pdo): array {
    $vehicle = null;
    foreach ($GLOBALS['fleet'] as $f) if ((int)$f['id'] === $vehicleId) { $vehicle = $f; break; }
    if (!$vehicle) return ['errors' => ['vehicle_id' => 'No car available to price.']];
    $input = [
        'service_type' => $GLOBALS['mode'] === 'hourly' ? 'hourly' : 'point_to_point',
        'vehicle_id' => $vehicleId,
        'passengers' => 1,
        'luggage' => 0,
        'stops' => [],
    ];
    if ($GLOBALS['mode'] === 'hourly') $input['hours'] = max(2, $qty);
    else $input['mileage'] = $qty;
    foreach ($extras as $code => $n) if ($n > 0) $input['addons'][$code] = ['qty' => $n];
    if ($coupon !== '') $input['coupon_code'] = $coupon;
    return PricingService::calculate($pdo, $input);
};

// Worked examples whose quantities follow the active mode, so an "all night"
// is 6 hours when billing hourly and never a 6-mile trip wearing that label.
$scenarios = $mode === 'hourly'
    ? [['Short hire', 2.0, []], ['Airport run', 4.0, ['meet_greet' => 1]], ['All night', 6.0, ['meet_greet' => 1, 'child_seat' => 1]]]
    : [['Short hop', 12.0, []], ['Airport run', 38.0, ['meet_greet' => 1]], ['Long run', 90.0, ['meet_greet' => 1, 'child_seat' => 1]]];

$examples = [];
$demoId = (int)($fleet[0]['id'] ?? 0);
foreach ($scenarios as [$label, $qty, $extras]) {
    $q = $quoteFor($demoId, $qty, $extras, '');
    $examples[] = [
        'label' => $label,
        'qty' => $qty,
        'ok' => !isset($q['errors']),
        'errors' => $q['errors'] ?? [],
        'lines' => $q['lines'] ?? [],
        'discount' => $q['discount'] ?? 0,
        'total' => $q['total'] ?? 0,
    ];
}

$tabs = [
    'fares' => ['Fares', 'What a ride costs right now'],
    'charges' => ['Charges', 'Tolls, parking, waiting, extras'],
    'coupons' => ['Coupons', 'Codes customers can give'],
    'waiting' => ['Waiting', 'Kerbside waiting rules'],
];
$countFor = [
    'charges' => count($charges),
    'coupons' => count($coupons),
    'waiting' => count($rules),
];

ob_start();
?>
<?php if ($msg !== ''): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="page-head">
  <div>
    <p class="eyebrow">Money</p>
    <h1 class="font-display text-3xl mt-1">Pricing</h1>
  </div>
</div>

<nav class="pz-tabs" aria-label="Pricing sections">
  <?php foreach ($tabs as $key => [$label, $sub]): ?>
    <a class="pz-tab<?= $tab === $key ? ' is-on' : '' ?>" href="<?= url('admin/pricing.php?tab=' . $key) ?>"
       <?= $tab === $key ? 'aria-current="page"' : '' ?>>
      <span class="pz-tab-l"><?= e($label) ?></span>
      <span class="pz-tab-s"><?= e($sub) ?></span>
      <?php if (isset($countFor[$key])): ?><span class="pz-tab-n"><?= $countFor[$key] ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'fares'): ?>
<div class="pz-wrap">
  <section class="pz-meter" aria-labelledby="pz-meter-h">
    <header class="pz-meter-head">
      <h2 id="pz-meter-h" class="pz-h">Fare meter</h2>
      <p class="pz-hint">Worked examples for <?= e(trim((string)($fleet[0]['make'] ?? 'the fleet') . ' ' . (string)($fleet[0]['model'] ?? ''))) ?>, recalculated by the pricing engine rather than by hand.</p>
    </header>

    <div class="pz-tape">
      <?php foreach ($examples as $ex): ?>
        <article class="pz-ex<?= $ex['ok'] ? '' : ' is-bad' ?>">
          <div class="pz-ex-top">
            <span class="pz-ex-name"><?= e($ex['label']) ?></span>
            <span class="pz-ex-qty"><?= e(rtrim(rtrim(number_format($ex['qty'], 1), '0'), '.')) ?> <?= $mode === 'hourly' ? 'hr' : 'mi' ?></span>
          </div>
          <?php if (!$ex['ok']): ?>
            <p class="pz-ex-err"><?= e(implode(' ', $ex['errors'])) ?></p>
          <?php else: ?>
            <div class="pz-lines">
              <?php foreach ($ex['lines'] as $ln):
                $meta = in_array($ln['source'], ['coupon', 'tax'], true); ?>
                <div class="pz-line<?= $meta ? ' is-meta' : '' ?><?= $ln['total'] < 0 ? ' is-minus' : '' ?>">
                  <span class="pz-line-k"><?= e($ln['description']) ?></span>
                  <span class="pz-line-v"><?= $ln['total'] < 0 ? '−' : '' ?>$<?= money(abs((float)$ln['total'])) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
            <footer class="pz-tot">
              <span class="pz-tot-k">Total</span>
              <span class="pz-tot-v">$<?= money($ex['total']) ?></span>
            </footer>
            <div class="pz-tear" aria-hidden="true"></div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="pz-rules">
      <div class="pz-rule"><span class="pz-rule-k">Mode</span><span class="pz-rule-v"><?= $mode === 'hourly' ? 'Hourly' : 'Per-mile' ?></span></div>
      <div class="pz-rule"><span class="pz-rule-k">Hourly minimum</span><span class="pz-rule-v">2 hr</span></div>
      <div class="pz-rule"><span class="pz-rule-k">Tax</span><span class="pz-rule-v"><?= e(rtrim(rtrim(number_format($taxPct, 2), '0'), '.')) ?>%</span></div>
      <div class="pz-rule"><span class="pz-rule-k">Charges</span><span class="pz-rule-v"><?= count($chargeBy) ?> active</span></div>
    </div>
  </section>

  <div class="pz-main">
    <div class="pz-two">
      <form method="post" class="pz-card">
        <?= csrf_field() ?><input type="hidden" name="op" value="mode"><input type="hidden" name="tab" value="fares">
        <label class="pz-label" for="pz-mode">How rides are priced</label>
        <select id="pz-mode" name="pricing_mode" class="input">
          <option value="per_mile" <?= $mode === 'per_mile' ? 'selected' : '' ?>>Per-mile — by the mile</option>
          <option value="hourly" <?= $mode === 'hourly' ? 'selected' : '' ?>>Hourly — by the hour</option>
        </select>
        <p class="pz-help">Hourly billing has a two-hour minimum. This applies to every booking.</p>
        <button class="pz-save">Save mode</button>
      </form>
      <form method="post" class="pz-card">
        <?= csrf_field() ?><input type="hidden" name="op" value="tax"><input type="hidden" name="tab" value="fares">
        <label class="pz-label" for="pz-tax">Tax %</label>
        <input id="pz-tax" name="tax_percent" type="number" step="0.01" min="0" class="input" value="<?= e((string)$taxPct) ?>" placeholder="0.00">
        <p class="pz-help">Applied after any coupon, on the discounted subtotal.</p>
        <button class="pz-save">Save tax</button>
      </form>
    </div>

    <div class="pz-note">
      <p class="pz-note-h">Everything else lives behind a tab</p>
      <p>Additional charges and coupons change what a fare adds up to. Waiting rules decide what a car earns while it sits on the kerb. Each of those tabs has its own add, edit and delete.</p>
      <div class="pz-note-acts">
        <a class="pz-save" href="<?= url('admin/pricing.php?tab=charges') ?>">Open charges</a>
        <a class="pz-toggle" href="<?= url('admin/pricing.php?tab=coupons') ?>">Open coupons</a>
        <a class="pz-toggle" href="<?= url('admin/pricing.php?tab=waiting') ?>">Open waiting</a>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'charges'): ?>
<div class="pz-col">
  <p class="pz-lede">Charges a dispatcher can add to a booking — tolls, parking, airport fees, extra stops. Flat charges bill once; the others scale with the trip.</p>

  <div class="table-wrap pz-table-wrap">
    <table class="data pz-table">
      <thead><tr><th>Charge</th><th>Code</th><th>How it bills</th><th>Rate</th><th>State</th><th></th></tr></thead>
      <tbody>
      <?php if (!$charges): ?>
        <tr><td colspan="6"><p class="pz-empty">No charges yet. Add the first one below.</p></td></tr>
      <?php endif; ?>
      <?php foreach ($charges as $c):
        $cid = (int)$c['id'];
        $on = (int)$c['active'] === 1;
        $editing = $editId === $cid; ?>
        <?php if ($editing): ?>
          <tr class="pz-editing">
            <td colspan="6">
              <form method="post" class="pz-edit">
                <?= csrf_field() ?><input type="hidden" name="op" value="charge_edit"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="tab" value="charges">
                <p class="pz-edit-h">Editing <?= e($c['name']) ?> <code class="pz-code"><?= e($c['code']) ?></code></p>
                <div class="pz-edit-grid">
                  <div><label class="pz-sr" for="ce-name-<?= $cid ?>">Name</label><input id="ce-name-<?= $cid ?>" name="name" class="input" required placeholder="Name" value="<?= e($c['name']) ?>"></div>
                  <div><label class="pz-sr" for="ce-type-<?= $cid ?>">How it bills</label><select id="ce-type-<?= $cid ?>" name="calculation_type" class="input"><?php foreach ($chargeTypes as $k => $label): ?><option value="<?= e($k) ?>" <?= $c['calculation_type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                  <div><label class="pz-sr" for="ce-amt-<?= $cid ?>">Rate</label><input id="ce-amt-<?= $cid ?>" name="amount" type="number" step="0.01" min="0" class="input" required placeholder="Amount" value="<?= e((string)$c['amount']) ?>"></div>
                  <label class="pz-check"><input type="checkbox" name="active" value="1" <?= $on ? 'checked' : '' ?>> Offered on bookings</label>
                </div>
                <div class="pz-edit-acts">
                  <button class="pz-save">Save changes</button>
                  <a class="pz-toggle" href="<?= url('admin/pricing.php?tab=charges') ?>">Cancel</a>
                </div>
              </form>
            </td>
          </tr>
        <?php endif; ?>
        <tr class="<?= $on ? '' : 'is-off' ?>">
          <th scope="row"><?= e($c['name']) ?></th>
          <td class="pz-code-cell"><code class="pz-code"><?= e($c['code']) ?></code></td>
          <td class="pz-dim" data-l="Bills as"><?= e($chargeTypes[$c['calculation_type']] ?? (string)$c['calculation_type']) ?></td>
          <td class="pz-num" data-l="Rate"><?= (float)$c['amount'] > 0 ? '$' . money($c['amount']) : '—' ?></td>
          <td data-l="State"><span class="pz-state<?= $on ? ' is-on' : '' ?>"><?= $on ? 'On' : 'Off' ?></span></td>
          <td>
            <div class="pz-acts">
              <?php if (!$editing): ?><a class="pz-toggle" href="<?= url('admin/pricing.php?tab=charges&edit=' . $cid) ?>" aria-label="Edit <?= e($c['name']) ?>">Edit</a><?php endif; ?>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="op" value="charge_delete"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="tab" value="charges">
                <button class="pz-toggle pz-toggle-bad" data-confirm="Delete <?= e($c['name']) ?>? Dispatchers will no longer be able to add it." aria-label="Delete <?= e($c['name']) ?>">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" class="pz-add">
    <?= csrf_field() ?><input type="hidden" name="op" value="charge"><input type="hidden" name="tab" value="charges">
    <p class="pz-add-h">Add a charge</p>
    <div class="pz-inline">
      <label class="pz-sr" for="pz-ccode">Charge code</label>
      <input id="pz-ccode" name="code" class="input" placeholder="code, e.g. toll" required>
      <label class="pz-sr" for="pz-cname">Charge name</label>
      <input id="pz-cname" name="name" class="input" placeholder="Name" required>
      <label class="pz-sr" for="pz-ctype">How it bills</label>
      <select id="pz-ctype" name="calculation_type" class="input"><?php foreach ($chargeTypes as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
      <label class="pz-sr" for="pz-camt">Rate</label>
      <input id="pz-camt" name="amount" type="number" step="0.01" min="0" class="input" placeholder="Amount" required>
      <button class="pz-save">Add charge</button>
    </div>
    <p class="pz-help">The code is how bookings refer to it — lowercase letters and underscores only.</p>
  </form>
</div>

<?php elseif ($tab === 'coupons'): ?>
<div class="pz-col">
  <p class="pz-lede">A code the customer gives at booking. The discount is taken off the subtotal before tax.</p>

  <?php if (!$coupons): ?>
    <p class="pz-empty">No coupons yet. Create one below and it works on the booking form straight away.</p>
  <?php else: ?>
    <div class="table-wrap pz-table-wrap">
      <table class="data pz-table">
        <thead><tr><th>Code</th><th>Discount</th><th>Minimum</th><th>Used</th><th>Per customer</th><th>Window</th><th>State</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($coupons as $c):
          $cid = (int)$c['id'];
          $on = (int)$c['active'] === 1;
          $inUse = (int)$c['redemptions'] + (int)$c['booked'];
          $cap = $c['usage_limit'] === null ? null : (int)$c['usage_limit'];
          $pct = $cap ? min(100, (int)round((int)$c['used_count'] / $cap * 100)) : 0;
          $editing = $editId === $cid; ?>
          <?php if ($editing): ?>
            <tr class="pz-editing">
              <td colspan="8">
                <form method="post" class="pz-edit">
                  <?= csrf_field() ?><input type="hidden" name="op" value="coupon_edit"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="tab" value="coupons">
                  <p class="pz-edit-h">Editing <code class="pz-code pz-code-big"><?= e($c['code']) ?></code></p>
                  <div class="pz-edit-grid">
                    <div><label class="pz-sr" for="ce-type-<?= $cid ?>">Discount type</label><select id="ce-type-<?= $cid ?>" name="type" class="input"><option value="percent" <?= $c['type'] === 'percent' ? 'selected' : '' ?>>Percent off</option><option value="fixed" <?= $c['type'] === 'fixed' ? 'selected' : '' ?>>Money off</option></select></div>
                    <div><label class="pz-sr" for="ce-val-<?= $cid ?>">Value</label><input id="ce-val-<?= $cid ?>" name="value" type="number" step="0.01" min="0" class="input" required value="<?= e((string)$c['value']) ?>"></div>
                    <div><label class="pz-sr" for="ce-min-<?= $cid ?>">Minimum subtotal</label><input id="ce-min-<?= $cid ?>" name="minimum_subtotal" type="number" step="0.01" min="0" class="input" value="<?= e((string)$c['minimum_subtotal']) ?>"></div>
                    <div><label class="pz-sr" for="ce-use-<?= $cid ?>">Total use limit</label><input id="ce-use-<?= $cid ?>" name="usage_limit" type="number" min="0" class="input" placeholder="No limit" value="<?= $cap === null ? '' : $cap ?>"></div>
                    <div><label class="pz-sr" for="ce-per-<?= $cid ?>">Per-customer limit</label><input id="ce-per-<?= $cid ?>" name="customer_limit" type="number" min="0" class="input" placeholder="No limit" value="<?= $c['customer_limit'] === null ? '' : (int)$c['customer_limit'] ?>"></div>
                    <div><label class="pz-sr" for="ce-start-<?= $cid ?>">Starts at</label><input id="ce-start-<?= $cid ?>" name="starts_at" type="datetime-local" class="input" value="<?= e($toLocal($c['starts_at'])) ?>"></div>
                    <div><label class="pz-sr" for="ce-end-<?= $cid ?>">Expires at</label><input id="ce-end-<?= $cid ?>" name="expires_at" type="datetime-local" class="input" value="<?= e($toLocal($c['expires_at'])) ?>"></div>
                    <label class="pz-check"><input type="checkbox" name="active" value="1" <?= $on ? 'checked' : '' ?>> Active</label>
                  </div>
                  <div class="pz-edit-acts">
                    <button class="pz-save">Save changes</button>
                    <a class="pz-toggle" href="<?= url('admin/pricing.php?tab=coupons') ?>">Cancel</a>
                  </div>
                </form>
              </td>
            </tr>
          <?php endif; ?>
          <tr class="<?= $on ? '' : 'is-off' ?>">
            <th scope="row"><code class="pz-code pz-code-big"><?= e($c['code']) ?></code></th>
            <td class="pz-num" data-l="Discount"><?= $c['type'] === 'percent' ? e(rtrim(rtrim(number_format((float)$c['value'], 2), '0'), '.')) . '%' : '$' . money($c['value']) ?></td>
            <td class="pz-num" data-l="Minimum"><?= (float)$c['minimum_subtotal'] > 0 ? '$' . money($c['minimum_subtotal']) : '—' ?></td>
            <td class="pz-num" data-l="Used">
              <?= (int)$c['used_count'] ?><?= $cap === null ? '' : ' / ' . $cap ?>
              <?php if ($cap !== null): ?><i class="pz-meterbar" style="--p:<?= $pct ?>%" aria-hidden="true"></i><?php endif; ?>
            </td>
            <td class="pz-num" data-l="Per customer"><?= $c['customer_limit'] === null ? '—' : (int)$c['customer_limit'] ?></td>
            <td class="pz-dim" data-l="Window"><?php
              $s = $toLocal($c['starts_at']);
              $x = $toLocal($c['expires_at']);
              echo $s === '' && $x === '' ? 'Always' : e(trim(($s ? date('j M', strtotime($s)) : 'now') . ' – ' . ($x ? date('j M Y', strtotime($x)) : 'open')));
            ?></td>
            <td data-l="State"><span class="pz-state<?= $on ? ' is-on' : '' ?>"><?= $on ? 'Active' : 'Paused' ?></span></td>
            <td>
              <div class="pz-acts">
                <?php if (!$editing): ?>
                  <a class="pz-toggle" href="<?= url('admin/pricing.php?tab=coupons&edit=' . $cid) ?>" aria-label="Edit <?= e($c['code']) ?>">Edit</a>
                  <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="op" value="coupon_toggle"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="tab" value="coupons">
                    <button class="pz-toggle" aria-label="<?= $on ? 'Pause' : 'Resume' ?> <?= e($c['code']) ?>"><?= $on ? 'Pause' : 'Resume' ?></button>
                  </form>
                <?php endif; ?>
                <?php if (!$editing): ?>
                  <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="op" value="coupon_delete"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="tab" value="coupons">
                    <button class="pz-toggle pz-toggle-bad" data-confirm="Delete <?= e($c['code']) ?>?<?= $inUse > 0 ? ' This code has been used on ' . $inUse . ' booking(s), so it can only be paused instead.' : '' ?>" aria-label="Delete <?= e($c['code']) ?>" <?= $inUse > 0 ? 'disabled title="Has been used on a booking — pause it instead"' : '' ?>>Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <form method="post" class="pz-add">
    <?= csrf_field() ?><input type="hidden" name="op" value="coupon"><input type="hidden" name="tab" value="coupons">
    <p class="pz-add-h">Create a coupon</p>
    <div class="pz-inline pz-inline-wrap">
      <label class="pz-sr" for="pz-cpcode">Coupon code</label>
      <input id="pz-cpcode" name="code" class="input" placeholder="CODE" required>
      <label class="pz-sr" for="pz-cptype">Discount type</label>
      <select id="pz-cptype" name="type" class="input"><option value="percent">Percent off</option><option value="fixed">Money off</option></select>
      <label class="pz-sr" for="pz-cpval">Value</label>
      <input id="pz-cpval" name="value" type="number" step="0.01" min="0" class="input" placeholder="Value" required>
      <label class="pz-sr" for="pz-cpmin">Minimum subtotal</label>
      <input id="pz-cpmin" name="minimum_subtotal" type="number" step="0.01" min="0" class="input" placeholder="Min subtotal">
      <label class="pz-sr" for="pz-cpuse">Total use limit</label>
      <input id="pz-cpuse" name="usage_limit" type="number" min="0" class="input" placeholder="Total limit">
      <label class="pz-sr" for="pz-cpper">Per-customer limit</label>
      <input id="pz-cpper" name="customer_limit" type="number" min="0" class="input" placeholder="Per-customer limit">
      <label class="pz-sr" for="pz-cpstart">Starts at</label>
      <input id="pz-cpstart" name="starts_at" type="datetime-local" class="input">
      <label class="pz-sr" for="pz-cpexp">Expires at</label>
      <input id="pz-cpexp" name="expires_at" type="datetime-local" class="input">
      <button class="pz-save">Create coupon</button>
    </div>
    <p class="pz-help">Leave the window and the limits empty for a code that never expires and can be reused.</p>
  </form>
</div>

<?php else: ?>
<div class="pz-col">
  <p class="pz-lede">A car waiting on the kerb bills in intervals once a free allowance runs out. Set the allowance per trip type.</p>

  <div class="table-wrap pz-table-wrap">
    <table class="data pz-table">
      <thead><tr><th>Trip type</th><th>Free</th><th>Then charged</th><th>Per interval</th><th>State</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rules): ?>
        <tr><td colspan="6"><p class="pz-empty">No waiting rules yet. Add one below.</p></td></tr>
      <?php endif; ?>
      <?php foreach ($rules as $r):
        $rid = (int)$r['id'];
        $on = (int)$r['active'] === 1;
        $editing = $editId === $rid; ?>
        <?php if ($editing): ?>
          <tr class="pz-editing">
            <td colspan="6">
              <form method="post" class="pz-edit">
                <?= csrf_field() ?><input type="hidden" name="op" value="waiting_edit"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="tab" value="waiting">
                <p class="pz-edit-h">Editing <?= e(ucfirst(str_replace('_', ' ', (string)$r['category']))) ?></p>
                <div class="pz-edit-grid">
                  <div><label class="pz-sr" for="we-free-<?= $rid ?>">Free minutes</label><input id="we-free-<?= $rid ?>" name="free_minutes" type="number" min="0" class="input" required value="<?= (int)$r['free_minutes'] ?>"></div>
                  <div><label class="pz-sr" for="we-int-<?= $rid ?>">Charge interval minutes</label><input id="we-int-<?= $rid ?>" name="charge_interval_minutes" type="number" min="1" class="input" required value="<?= (int)$r['charge_interval_minutes'] ?>"></div>
                  <div><label class="pz-sr" for="we-amt-<?= $rid ?>">Charge per interval</label><input id="we-amt-<?= $rid ?>" name="charge_per_interval" type="number" step="0.01" min="0" class="input" required value="<?= e((string)$r['charge_per_interval']) ?>"></div>
                  <label class="pz-check"><input type="checkbox" name="active" value="1" <?= $on ? 'checked' : '' ?>> Active</label>
                </div>
                <div class="pz-edit-acts">
                  <button class="pz-save">Save changes</button>
                  <a class="pz-toggle" href="<?= url('admin/pricing.php?tab=waiting') ?>">Cancel</a>
                </div>
              </form>
            </td>
          </tr>
        <?php endif; ?>
        <tr class="<?= $on ? '' : 'is-off' ?>">
          <th scope="row"><?= e(ucfirst(str_replace('_', ' ', (string)$r['category']))) ?></th>
          <td class="pz-num" data-l="Free"><?= (int)$r['free_minutes'] ?> min</td>
          <td class="pz-num" data-l="Then charged">every <?= (int)$r['charge_interval_minutes'] ?> min</td>
          <td class="pz-num" data-l="Per interval">$<?= money($r['charge_per_interval']) ?></td>
          <td data-l="State"><span class="pz-state<?= $on ? ' is-on' : '' ?>"><?= $on ? 'On' : 'Off' ?></span></td>
          <td>
            <div class="pz-acts">
              <?php if (!$editing): ?><a class="pz-toggle" href="<?= url('admin/pricing.php?tab=waiting&edit=' . $rid) ?>" aria-label="Edit <?= e($r['category']) ?>">Edit</a><?php endif; ?>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="op" value="waiting_delete"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="tab" value="waiting">
                <button class="pz-toggle pz-toggle-bad" data-confirm="Delete the <?= e($r['category']) ?> waiting rule? Waiting on that trip type will fall back to 15 free minutes billed every 10." aria-label="Delete <?= e($r['category']) ?> rule">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" class="pz-add">
    <?= csrf_field() ?><input type="hidden" name="op" value="waiting"><input type="hidden" name="tab" value="waiting">
    <p class="pz-add-h">Add or replace a waiting rule</p>
    <div class="pz-inline">
      <label class="pz-sr" for="pz-wcat">Trip type</label>
      <input id="pz-wcat" name="category" class="input" list="pz-cats" placeholder="trip type, e.g. airport" required>
      <datalist id="pz-cats">
        <?php foreach (array_unique(array_map(static fn($r) => (string)$r['category'], $rules)) as $cat): ?><option value="<?= e($cat) ?>"><?= e(ucfirst(str_replace('_', ' ', $cat))) ?></option><?php endforeach; ?>
        <option value="point_to_point">Point to point</option>
        <option value="hourly">Hourly</option>
        <option value="charter">Charter</option>
      </datalist>
      <label class="pz-sr" for="pz-wfree">Free minutes</label>
      <input id="pz-wfree" name="free_minutes" type="number" min="0" class="input" placeholder="Free min" required>
      <label class="pz-sr" for="pz-wint">Charge interval minutes</label>
      <input id="pz-wint" name="charge_interval_minutes" type="number" min="1" class="input" placeholder="Interval" required>
      <label class="pz-sr" for="pz-wamt">Charge per interval</label>
      <input id="pz-wamt" name="charge_per_interval" type="number" step="0.01" min="0" class="input" placeholder="$ per interval" required>
      <button class="pz-save">Save rule</button>
    </div>
    <p class="pz-help">Typing a trip type that already exists replaces its rule.</p>
  </form>

  <section class="pz-sec">
    <h2 class="pz-h">Close a waiting session</h2>
    <p class="pz-lede">When a car is done waiting on a booking, close it here. The charge goes on as an unpaid invoice line — no card is charged automatically.</p>
    <?php if ($openWaiting > 0): ?><p class="pz-open"><?= $openWaiting ?> waiting <?= $openWaiting === 1 ? 'session is' : 'sessions are' ?> still open.</p><?php endif; ?>
    <form method="post" class="pz-inline">
      <?= csrf_field() ?><input type="hidden" name="op" value="waiting_close"><input type="hidden" name="tab" value="waiting">
      <label class="pz-sr" for="pz-wb">Booking number</label>
      <input id="pz-wb" name="booking_id" type="number" min="1" class="input" placeholder="Booking ID" required>
      <label class="pz-sr" for="pz-wcat2">Trip type</label>
      <select id="pz-wcat2" name="category" class="input">
        <?php foreach ($rules as $r): ?><option value="<?= e($r['category']) ?>"><?= e(ucfirst(str_replace('_', ' ', (string)$r['category']))) ?></option><?php endforeach; ?>
      </select>
      <label class="pz-sr" for="pz-wmin">Minutes waited</label>
      <input id="pz-wmin" name="waited_minutes" type="number" min="0" class="input" placeholder="Minutes waited" required>
      <button class="pz-save">Close and invoice</button>
    </form>
  </section>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
$pageTitle = 'Pricing | Admin';
$navActive = 'pricing.php';
require APP_ROOT . '/views/layouts/admin.php';