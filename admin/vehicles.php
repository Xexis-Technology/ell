<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;
$back = 'admin/vehicles.php';


require_once __DIR__ . '/../app/richtext.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $returnTo = (int)($_POST['back_id'] ?? 0);
    $view = (string)($_POST['view'] ?? '');

    if ($op === 'save_vehicle') {
        $cat = trim((string)($_POST['category_id'] ?? ''));
        $make = trim((string)($_POST['make'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));
        $date = static function (string $k): ?string {
            $v = trim((string)($_POST[$k] ?? ''));
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            return ($v !== '' && $d && $d->format('Y-m-d') === $v) ? $v : null;
        };
        $fields = [
            'category_id' => $cat === '' ? null : (int)$cat,
            'make' => $make,
            'model' => $model,
            'year' => (int)($_POST['year'] ?? 0) ?: null,
            'plate' => strtoupper(trim((string)($_POST['plate'] ?? ''))),
            'passenger_capacity' => max(1, (int)($_POST['passenger_capacity'] ?? 3)),
            'luggage_capacity' => max(0, (int)($_POST['luggage_capacity'] ?? 2)),
            'status' => in_array($_POST['status'] ?? '', ['active', 'inactive', 'maintenance'], true) ? $_POST['status'] : 'active',
            'description' => clean_html($_POST['description'] ?? ''),
            'insurance_expiry' => $date('insurance_expiry'),
            'registration_expiry' => $date('registration_expiry'),
            'inspection_expiry' => $date('inspection_expiry'),
            'diamond_sticker_expiry' => $date('diamond_sticker_expiry'),
        ];
        if ($make === '' || $model === '') {
            $msg = 'A vehicle needs a make and a model.';
            $isErr = true;
        } else {
            $colList = ['category_id', 'make', 'model', 'slug', 'year', 'plate', 'passenger_capacity', 'luggage_capacity', 'status', 'description', 'insurance_expiry', 'registration_expiry', 'inspection_expiry', 'diamond_sticker_expiry'];
            $setList = implode(',', array_map(static fn(string $c): string => $c . '=?', $colList));
            $head = [$fields['category_id'], $make, $model];
            $tail = [$fields['year'], $fields['plate'], $fields['passenger_capacity'], $fields['luggage_capacity'], $fields['status'], $fields['description'], $fields['insurance_expiry'], $fields['registration_expiry'], $fields['inspection_expiry'], $fields['diamond_sticker_expiry']];
            if ($id) {
                $cur = $pdo->query('SELECT slug FROM vehicles WHERE id = ' . $id)->fetch();
                $slug = ($cur && $cur['slug']) ? $cur['slug'] : unique_vehicle_slug($pdo, $make . ' ' . $model, $id);
                $pdo->prepare('UPDATE vehicles SET ' . $setList . ' WHERE id=?')->execute(array_merge($head, [$slug], $tail, [$id]));
                audit($pdo, 'admin', (int)$admin['id'], 'vehicle.updated', 'vehicle', $id, null);
                $msg = $make . ' ' . $model . ' saved.';
                $returnTo = $id;
            } else {
                $slug = unique_vehicle_slug($pdo, $make . ' ' . $model);
                $pdo->prepare('INSERT INTO vehicles (' . implode(',', $colList) . ') VALUES (' . implode(',', array_fill(0, count($colList), '?')) . ')')
                    ->execute(array_merge($head, [$slug], $tail));
                $id = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO pricing_rates (vehicle_id, per_mile_rate, hourly_rate, active) VALUES (?,?,?,1)')->execute([$id, (float)($_POST['per_mile_rate'] ?? 0), (float)($_POST['hourly_rate'] ?? 0)]);
                audit($pdo, 'admin', (int)$admin['id'], 'vehicle.created', 'vehicle', $id, null);
                $msg = $make . ' ' . $model . ' added to the fleet.';
                $returnTo = $id;
            }
        }
    } elseif ($op === 'save_rate') {
        $vid = (int)($_POST['vehicle_id'] ?? 0);
        $mi = (float)($_POST['per_mile_rate'] ?? 0);
        $hr = (float)($_POST['hourly_rate'] ?? 0);
        $pdo->prepare('UPDATE pricing_rates SET active = 0 WHERE vehicle_id = ?')->execute([$vid]);
        $pdo->prepare('INSERT INTO pricing_rates (vehicle_id, per_mile_rate, hourly_rate, active) VALUES (?,?,?,1)')->execute([$vid, $mi, $hr]);
        audit($pdo, 'admin', (int)$admin['id'], 'pricing.rate_updated', 'vehicle', $vid, ['per_mile' => $mi, 'hourly' => $hr]);
        $v = $pdo->query('SELECT make, model FROM vehicles WHERE id = ' . $vid)->fetch();
        $msg = ($v ? $v['make'] . ' ' . $v['model'] : 'Vehicle') . ' rate is now $' . money($mi) . '/mi and $' . money($hr) . '/hr.';
        $returnTo = $vid;
    } elseif ($op === 'save_category') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $msg = 'Give the category a name.';
            $isErr = true;
        } else {
            // Kept as rich text: the category dialog has a Quill editor, and
            // plain_text() flattened it to "Cat richx" while still leaking the
            // contents of stripped tags.
            $pdo->prepare('INSERT INTO vehicle_categories (name, description, active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE description=VALUES(description)')
                ->execute([$name, clean_html($_POST['description'] ?? '')]);
            audit($pdo, 'admin', (int)$admin['id'], 'vehicle.category_saved', 'vehicle_category', 0, ['name' => $name]);
            $msg = $name . ' category saved.';
        }
    } elseif ($op === 'block') {
        $vid = (int)($_POST['vehicle_id'] ?? 0);
        $sd = DateTime::createFromFormat('!Y-m-d\TH:i', str_replace(' ', 'T', trim((string)($_POST['starts_at'] ?? ''))));
        $ed = DateTime::createFromFormat('!Y-m-d\TH:i', str_replace(' ', 'T', trim((string)($_POST['ends_at'] ?? ''))));
        if (!$sd || !$ed || $ed < $sd) {
            $msg = 'The block needs a start and an end, and the end has to be after the start.';
            $isErr = true;
        } else {
            $reason = trim((string)($_POST['reason'] ?? ''));
            $pdo->prepare('INSERT INTO vehicle_blocks (vehicle_id, starts_at, ends_at, reason, created_by) VALUES (?,?,?,?,?)')
                ->execute([$vid, $sd->format('Y-m-d H:i:s'), $ed->format('Y-m-d H:i:s'), $reason === '' ? 'Unavailable' : $reason, (int)$admin['id']]);
            audit($pdo, 'admin', (int)$admin['id'], 'vehicle.blocked', 'vehicle', $vid, null);
            $v = $pdo->query('SELECT make, model FROM vehicles WHERE id = ' . $vid)->fetch();
            $msg = ($v ? $v['make'] . ' ' . $v['model'] : 'Vehicle') . ' is blocked for that time.';
            $returnTo = $vid;
        }
    } elseif ($op === 'unblock') {
        $pdo->prepare('DELETE FROM vehicle_blocks WHERE id = ?')->execute([(int)($_POST['block_id'] ?? 0)]);
        audit($pdo, 'admin', (int)$admin['id'], 'vehicle.unblocked', 'vehicle', (int)($_POST['vehicle_id'] ?? 0), null);
        $msg = 'Block lifted. The car is bookable again.';
        $returnTo = (int)($_POST['vehicle_id'] ?? 0);
    } elseif ($op === 'doc' && isset($_FILES['doc'])) {
        $vid = (int)($_POST['vehicle_id'] ?? 0);
        [$path, $err] = secure_upload($_FILES['doc'], 'vehicles');
        if ($err) {
            $msg = $err;
            $isErr = true;
        } else {
            $pdo->prepare('INSERT INTO vehicle_documents (vehicle_id, doc_type, file_path, expiry_date, status) VALUES (?,?,?,?,"pending")')
                ->execute([$vid, trim((string)($_POST['doc_type'] ?? 'document')) ?: 'document', $path, $_POST['expiry_date'] ?: null]);
            audit($pdo, 'admin', (int)$admin['id'], 'vehicle.doc_uploaded', 'vehicle', $vid, null);
            $msg = 'Document uploaded. It is pending review.';
            $returnTo = $vid;
        }
    } elseif ($op === 'verify_doc') {
        $pdo->prepare('UPDATE vehicle_documents SET status = ? WHERE id = ?')
            ->execute([in_array($_POST['doc_status'] ?? '', ['verified', 'rejected', 'expired'], true) ? $_POST['doc_status'] : 'pending', (int)$_POST['doc_id']]);
        audit($pdo, 'admin', (int)$admin['id'], 'vehicle.doc_reviewed', 'vehicle_document', (int)$_POST['doc_id'], null);
        $msg = 'Document reviewed.';
        $returnTo = (int)($_POST['vehicle_id'] ?? 0);
    }
    header('Location: ' . url($back . '?' . http_build_query(array_filter(['id' => $returnTo ?: null, 'view' => $view, 'msg' => $msg] + ($isErr ? ['err' => 1] : [])))));
    exit;
}

if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$isErr = isset($_GET['err']);
$selId = (int)($_GET['id'] ?? 0);
$view = (string)($_GET['view'] ?? '');

$st = $pdo->query('SELECT v.*, c.name AS category, pr.per_mile_rate, pr.hourly_rate
  FROM vehicles v
  LEFT JOIN vehicle_categories c ON c.id = v.category_id
  LEFT JOIN pricing_rates pr ON pr.vehicle_id = v.id AND pr.active = 1
  ORDER BY v.make, v.model');
$vehicles = $st->fetchAll();
$cats = $pdo->query('SELECT * FROM vehicle_categories ORDER BY name')->fetchAll();

$vdocsBy = [];
foreach ($pdo->query('SELECT * FROM vehicle_documents ORDER BY id DESC') as $d) $vdocsBy[(int)$d['vehicle_id']][] = $d;
$blocksBy = [];
foreach ($pdo->query('SELECT * FROM vehicle_blocks ORDER BY starts_at') as $b) $blocksBy[(int)$b['vehicle_id']][] = $b;

$fmtDays = static function (?int $n): string {
    if ($n === null) return 'not recorded';
    if ($n < 0) return abs($n) . 'd ago';
    if ($n === 0) return 'today';
    if ($n < 45) return $n . 'd';
    if ($n < 365) return intdiv($n, 30) . 'mo';
    return number_format($n / 365, 1) . 'y';
};
$sev = static function (?int $n): array {
    if ($n === null) return ['is-none', 0];
    $pct = $n < 0 ? 3 : max(3, min(100, (int)round($n / 365 * 100)));
    if ($n <= 30) return ['is-bad', $pct];
    if ($n <= 90) return ['is-warn', $pct];
    return ['', $pct];
};

/**
 * The four dates a car cannot be booked without, and how long each has left.
 * Written out explicitly rather than driven off a key=>label map: the map's
 * keys were being read as labels, which silently turned every date into
 * "missing" and emptied the filters.
 */
function vehicle_docket(array $row): array
{
    $out = ['missing' => 0, 'worst' => null, 'worstLabel' => null, 'lapsedLabel' => null];
    foreach ([
        'Insurance' => $row['insurance_expiry'] ?? null,
        'Registration' => $row['registration_expiry'] ?? null,
        'Inspection' => $row['inspection_expiry'] ?? null,
        'Diamond sticker' => $row['diamond_sticker_expiry'] ?? null,
    ] as $label => $date) {
        $n = days_until($date);
        if ($n === null) { $out['missing']++; continue; }
        if ($out['lapsedLabel'] === null && $n < 0) $out['lapsedLabel'] = $label;
        if ($out['worst'] === null || $n < $out['worst']) {
            $out['worst'] = $n;
            $out['worstLabel'] = $label;
        }
    }
    $out['lapsed'] = $out['worst'] !== null && $out['worst'] < 0;
    return $out;
}
$now = date('Y-m-d H:i:s');
$paperwork = 0;
$lapsed = 0;
$inShop = 0;
$blockedNow = 0;
$ready = 0;
$risky = [];
// Index loop, not `foreach ($vehicles as &$v)`: the derived flags are read back
// from $vehicles later, and an index loop writes them without leaving $v as a
// dangling reference.
foreach ($vehicles as $i => $row) {
    $doc = vehicle_docket($row);
    $missing = $doc['missing'];
    $worst = $doc['worst'];

    $isBlocked = false;
    $live = null;
    foreach ($blocksBy[(int)$row['id']] ?? [] as $blk) {
        if ($blk['starts_at'] <= $now && $blk['ends_at'] >= $now) { $isBlocked = true; $live = $blk; break; }
    }
    $isLapsed = $doc['lapsed'];

    $vehicles[$i]['_worst'] = $worst;
    $vehicles[$i]['_worstLabel'] = $doc['worstLabel'];
    $vehicles[$i]['_lapsedLabel'] = $doc['lapsedLabel'];
    $vehicles[$i]['_missing'] = $missing;
    $vehicles[$i]['_lapsed'] = $isLapsed;
    $vehicles[$i]['_blocked'] = $isBlocked;
    $vehicles[$i]['_live'] = $live;

    if ($isLapsed) $lapsed++;
    if ($missing > 0) $paperwork++;
    if ($row['status'] === 'maintenance') $inShop++;
    if ($isBlocked) $blockedNow++;
    if (!$isLapsed && $missing === 0 && $row['status'] === 'active' && !$isBlocked) $ready++;
    if ($isLapsed || ($worst !== null && $worst <= 30)) $risky[] = trim($row['make'] . ' ' . $row['model']);
}

// Only offer filters that actually split the list. "All" is hidden when nothing
// is being filtered, so the row never sits there as decoration.
$buckets = [
    'paperwork' => static fn($v) => $v['_lapsed'] || $v['_missing'] > 0,
    'soon' => static fn($v) => !$v['_lapsed'] && $v['_missing'] === 0 && $v['_worst'] !== null && $v['_worst'] <= 30,
    'shop' => static fn($v) => $v['status'] === 'maintenance' || $v['_blocked'],
    'ready' => static fn($v) => !$v['_lapsed'] && $v['_missing'] === 0 && $v['status'] === 'active' && !$v['_blocked'],
];
$counts = [];
foreach ($buckets as $k => $fn) {
    $n = count(array_filter($vehicles, $fn));
    if ($n > 0 && $n < count($vehicles)) $counts[$k] = $n;
}
$view = isset($counts[$view]) || $view === '' ? $view : '';
$shown = $view === '' ? $vehicles : array_values(array_filter($vehicles, $buckets[$view]));

ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="fl-head">
  <div>
    <p class="eyebrow">Fleet</p>
    <h1 class="font-display">The cars</h1>
    <p class="cu-sub">Every car carries a docket: insurance, registration, inspection and the diamond sticker. Open a car to see its paperwork, rates and booking history.</p>
  </div>
  <div class="fl-head-acts">
    <button type="button" class="fl-btn fl-btn-go" data-open-modal="dlg-car"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add car</button>
    <button type="button" class="fl-btn fl-btn-go" data-open-modal="dlg-cat"><i class="fa-solid fa-tag" aria-hidden="true"></i> Add category</button>
  </div>
</header>

<p class="fl-figures">
  <span><b><?= count($vehicles) ?></b> cars</span>
  <span><b class="<?= $ready < count($vehicles) ? 'is-warn' : '' ?>"><?= $ready ?></b> ready to book</span>
  <?php if ($lapsed): ?><span><b class="is-bad"><?= $lapsed ?></b> out of paper</span><?php endif; ?>
  <?php if ($paperwork): ?><span><b class="is-warn"><?= $paperwork ?></b> missing dates</span><?php endif; ?>
  <?php if ($inShop): ?><span><b class="is-warn"><?= $inShop ?></b> in the shop</span><?php endif; ?>
  <?php if ($blockedNow): ?><span><b class="is-warn"><?= $blockedNow ?></b> blocked today</span><?php endif; ?>
</p>

<?php if ($risky): ?>
  <p class="fl-alert<?= $lapsed ? ' fl-alert-bad' : '' ?>"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= e(implode(', ', array_slice($risky, 0, 3))) ?><?= count($risky) > 3 ? ' and ' . (count($risky) - 3) . ' more' : '' ?> <?= count($risky) === 1 ? 'has' : 'have' ?> paperwork that has run out or is about to. <button type="button" class="fl-linkbtn" data-open-modal="dlg-risky">Show me</button></p>
<?php endif; ?>

<?php if ($counts): ?>
<nav class="fl-filters" aria-label="Filter the fleet">
  <a class="fl-filter<?= $view === '' ? ' is-on' : '' ?>" href="<?= url('admin/vehicles.php') ?>"<?= $view === '' ? ' aria-current="true"' : '' ?>>All cars</a>
  <?php foreach ($counts as $k => $n): ?>
    <a class="fl-filter<?= $view === $k ? ' is-on' : '' ?>" href="<?= url('admin/vehicles.php?view=' . $k) ?>"<?= $view === $k ? ' aria-current="true"' : '' ?>><?= e(ucfirst($k === 'soon' ? 'due within 30 days' : ($k === 'shop' ? 'in the shop' : $k))) ?> <b><?= $n ?></b></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if (!$shown): ?>
  <p class="fl-empty-t" style="margin-top:1.4rem"><?= $view === '' ? 'No cars in the fleet yet.' : 'Nothing in this filter.' ?></p>
  <p class="fl-empty-sub"><?= $view === '' ? 'Add the first car and Exotic Lane can start taking bookings against it.' : 'Try another filter, or add a car.' ?></p>
<?php else: ?>
<ul class="tiles">
<?php foreach ($shown as $v):
    $vid = (int)$v['id'];
    $edge = $v['_lapsed'] ? ' t-lapsed' : (($v['_worst'] !== null && $v['_worst'] <= 30) || $v['_missing'] > 0 ? ' t-warn' : '');
?>
  <li>
<button type="button" class="tile<?= $edge ?>" data-open-modal="dlg-car-<?= $vid ?>" aria-label="Open <?= e($v['make'] . ' ' . $v['model']) ?>">
  <img class="tile-img" src="<?= e(vehicle_photo_url((string)($v['category'] ?? ''), 400)) ?>" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
  <span class="tile-text">
  <span class="tile-name"><?= e($v['make'] . ' ' . $v['model']) ?></span>
  <span class="tile-rates"><span><b>$<?= money($v['per_mile_rate'] ?? 0) ?></b>/mi</span><span><b>$<?= money($v['hourly_rate'] ?? 0) ?></b>/hr</span></span>
  </span>
  </button>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php foreach ($shown as $v):
    $vid = (int)$v['id'];
    $slips = $vdocsBy[$vid] ?? [];
    $vblocks = $blocksBy[$vid] ?? [];
    $st2 = $pdo->prepare('SELECT COUNT(*) c, COALESCE(SUM(b.total),0) s FROM bookings b JOIN dispatches d ON d.booking_id = b.id WHERE d.vehicle_id = ? AND b.status <> "cancelled"');
    $st2->execute([$vid]);
    $usage = $st2->fetch();
?>
<dialog class="modal" id="dlg-car-<?= $vid ?>" aria-labelledby="dlg-car-t-<?= $vid ?>">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="dlg-car-t-<?= $vid ?>"><?= e($v['make'] . ' ' . $v['model']) ?></h2>
      <p class="md-sub"><?= e((string)($v['category'] ?: 'Uncategorised')) ?><?= $v['year'] ? ' &middot; ' . e((string)$v['year']) : '' ?> &middot; <?= e((string)($v['plate'] ?: 'no plate')) ?></p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>

  <div class="md-body">
    <form method="post" class="fl-form" id="car-form-<?= $vid ?>">
      <?= csrf_field() ?><input type="hidden" name="op" value="save_vehicle"><input type="hidden" name="id" value="<?= $vid ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
      <div class="det-hero">
        <img src="<?= e(vehicle_photo_url((string)($v['category'] ?? ''), 400)) ?>" alt="" onerror="this.style.visibility='hidden'">
        <div class="det-facts" style="border:0;padding:0;flex:1">
          <div class="det-f"><span class="det-k">Plate</span><span class="det-v"><?= e((string)($v['plate'] ?: '—')) ?></span></div>
          <div class="det-f"><span class="det-k">Seats</span><span class="det-v"><?= (int)$v['passenger_capacity'] ?> pax</span></div>
          <div class="det-f"><span class="det-k">Bags</span><span class="det-v"><?= (int)$v['luggage_capacity'] ?></span></div>
          <div class="det-f"><span class="det-k">Status</span><span class="det-v"><?= status_pill((string)$v['status']) ?></span></div>
          <div class="det-f"><span class="det-k">Booked</span><span class="det-v"><?= (int)$usage['c'] ?> trips &middot; $<?= money($usage['s']) ?></span></div>
          <div class="det-f"><span class="det-k">Public page</span><span class="det-v"><a href="<?= url('fleet/' . ($v['slug'] ?: '')) ?>">view</a></span></div>
        </div>
      </div>

      <div class="det-block">
        <span class="det-h">Rates</span>
        <div class="fl-row2">
          <div><label class="bk-k" for="cmi<?= $vid ?>">Per mile ($)</label><input id="cmi<?= $vid ?>" name="per_mile_rate" type="number" step="0.01" min="0" class="input" value="<?= e((string)($v['per_mile_rate'] ?? '0')) ?>"></div>
          <div><label class="bk-k" for="chr<?= $vid ?>">Per hour ($)</label><input id="chr<?= $vid ?>" name="hourly_rate" type="number" step="0.01" min="0" class="input" value="<?= e((string)($v['hourly_rate'] ?? '0')) ?>"></div>
        </div>
      </div>

      <div class="det-block">
        <span class="det-h">Paperwork</span>
        <div class="fl-row2">
          <div><label class="bk-k" for="ci<?= $vid ?>">Insurance expires</label><input id="ci<?= $vid ?>" name="insurance_expiry" type="date" class="input" value="<?= e((string)($v['insurance_expiry'] ?? '')) ?>"></div>
          <div><label class="bk-k" for="cr<?= $vid ?>">Registration expires</label><input id="cr<?= $vid ?>" name="registration_expiry" type="date" class="input" value="<?= e((string)($v['registration_expiry'] ?? '')) ?>"></div>
        </div>
        <div class="fl-row2">
          <div><label class="bk-k" for="cn<?= $vid ?>">Inspection expires</label><input id="cn<?= $vid ?>" name="inspection_expiry" type="date" class="input" value="<?= e((string)($v['inspection_expiry'] ?? '')) ?>"></div>
          <div><label class="bk-k" for="cd<?= $vid ?>">Diamond sticker expires</label><input id="cd<?= $vid ?>" name="diamond_sticker_expiry" type="date" class="input" value="<?= e((string)($v['diamond_sticker_expiry'] ?? '')) ?>"></div>
        </div>
        <div class="fl-clock" style="border:0;padding:.4rem 0 0;max-width:none">
          <?php
          $dl = ['Insurance', 'Registration', 'Inspection', 'Diamond sticker'];
          $cols = ['insurance_expiry', 'registration_expiry', 'inspection_expiry', 'diamond_sticker_expiry'];
          foreach ($dl as $li => $lbl):
            $n = days_until($v[$cols[$li]] ?? null);
            [$cls, $pct] = $sev($n); ?>
          <div class="fl-doc <?= $cls ?>">
            <span class="fl-doc-k"><?= e($lbl) ?></span>
            <span class="fl-doc-bar"><i class="fl-doc-fill" style="width:<?= $pct ?>%"></i></span>
            <span class="fl-doc-d"><?= $n === null ? 'missing' : e($fmtDays($n)) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="det-block">
        <span class="det-h">Car details</span>
        <div class="fl-row2">
          <div><label class="bk-k" for="dmk<?= $vid ?>">Make</label><input id="dmk<?= $vid ?>" name="make" class="input" required value="<?= e($v['make']) ?>"></div>
          <div><label class="bk-k" for="dmd<?= $vid ?>">Model</label><input id="dmd<?= $vid ?>" name="model" class="input" required value="<?= e($v['model']) ?>"></div>
        </div>
        <div class="fl-row2">
          <div><label class="bk-k" for="dct<?= $vid ?>">Category</label><select id="dct<?= $vid ?>" name="category_id" class="input"><option value="">Uncategorised</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($v['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
          <div><label class="bk-k" for="dst<?= $vid ?>">Status</label><select id="dst<?= $vid ?>" name="status" class="input"><?php foreach (['active' => 'Active', 'maintenance' => 'In the shop', 'inactive' => 'Retired'] as $sk => $sl): ?><option value="<?= $sk ?>" <?= $v['status'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="fl-row2">
          <div><label class="bk-k" for="dyr<?= $vid ?>">Year</label><input id="dyr<?= $vid ?>" name="year" type="number" min="1900" max="2100" class="input" value="<?= e((string)($v['year'] ?? '')) ?>"></div>
          <div><label class="bk-k" for="dpl<?= $vid ?>">Plate</label><input id="dpl<?= $vid ?>" name="plate" class="input" value="<?= e((string)$v['plate']) ?>"></div>
        </div>
        <div class="fl-row2">
          <div><label class="bk-k" for="dpx<?= $vid ?>">Passengers</label><input id="dpx<?= $vid ?>" name="passenger_capacity" type="number" min="1" class="input" value="<?= (int)$v['passenger_capacity'] ?>"></div>
          <div><label class="bk-k" for="dbg<?= $vid ?>">Bags</label><input id="dbg<?= $vid ?>" name="luggage_capacity" type="number" min="0" class="input" value="<?= (int)$v['luggage_capacity'] ?>"></div>
        </div>
      </div>

      <div class="det-block">
        <span class="det-h">Description <span style="letter-spacing:0;text-transform:none;color:#6a6a6a">&mdash; shown on the public fleet page</span></span>
        <textarea class="ql-host" name="description" placeholder="What sets this car apart? Seats, luggage, service notes."><?= e((string)($v['description'] ?? '')) ?></textarea>
      </div>
      <div><button type="submit" class="fl-btn fl-btn-go">Save <?= e($v['make'] . ' ' . $v['model']) ?></button></div>
    </form>

    <div class="det-block">
      <span class="det-h">Documents</span>
      <?php if ($slips): ?>
        <div class="table-wrap"><table class="fl-table">
          <thead><tr><th>Document</th><th>Expires</th><th>Status</th><th>File</th><th>Review</th></tr></thead>
          <tbody>
          <?php foreach ($slips as $d): ?>
            <tr>
              <td><?= e($d['doc_type']) ?></td>
              <td class="tabular"><?= $d['expiry_date'] ? e(date('j M Y', strtotime((string)$d['expiry_date']))) : '&mdash;' ?></td>
              <td><?= status_pill((string)$d['status']) ?></td>
              <td><a href="<?= url($d['file_path']) ?>">open</a></td>
              <td>
                <form method="post" class="fl-row2" style="gap:.3rem"><?= csrf_field() ?><input type="hidden" name="op" value="verify_doc"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= $vid ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
                  <select name="doc_status" class="input" style="padding:.35rem .5rem;font-size:.78rem" aria-label="Review <?= e($d['doc_type']) ?>">
                    <?php foreach (['verified' => 'Verified', 'pending' => 'Pending', 'rejected' => 'Rejected', 'expired' => 'Expired'] as $sk => $sl2): ?><option value="<?= $sk ?>" <?= $d['status'] === $sk ? 'selected' : '' ?>><?= e($sl2) ?></option><?php endforeach; ?>
                  </select>
                  <button class="fl-btn">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <p class="det-empty">No documents on file for this car.</p>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="fl-form">
        <?= csrf_field() ?><input type="hidden" name="op" value="doc"><input type="hidden" name="vehicle_id" value="<?= $vid ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="fl-row2">
          <div><label class="bk-k" for="ddt<?= $vid ?>">Document</label><input id="ddt<?= $vid ?>" name="doc_type" class="input" value="insurance" placeholder="insurance / registration / inspection / diamond"></div>
          <div><label class="bk-k" for="ddx<?= $vid ?>">Expires</label><input id="ddx<?= $vid ?>" name="expiry_date" type="date" class="input"></div>
        </div>
        <div><label class="bk-k" for="ddf<?= $vid ?>">File</label><input id="ddf<?= $vid ?>" type="file" name="doc" class="input" accept=".jpg,.jpeg,.png,.pdf"></div>
        <div><button class="fl-btn fl-btn-go">Upload document</button></div>
      </form>
    </div>

    <div class="det-block">
      <span class="det-h">Blocked time</span>
      <?php if ($v['_live']): ?>
        <p class="det-empty" style="color:#f3c1bd">Blocked now until <?= e(substr((string)$v['_live']['ends_at'], 11, 5)) ?> &middot; <?= e((string)$v['_live']['reason']) ?></p>
      <?php endif; ?>
      <?php if ($vblocks): ?>
        <div class="table-wrap"><table class="fl-table">
          <thead><tr><th>From</th><th>Until</th><th>Reason</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($vblocks as $b):
            $past = $b['ends_at'] < $now; ?>
            <tr style="<?= $past ? 'opacity:.5' : '' ?>">
              <td class="tabular"><?= e(date('j M, H:i', strtotime((string)$b['starts_at']))) ?></td>
              <td class="tabular"><?= e(date('j M, H:i', strtotime((string)$b['ends_at']))) ?></td>
              <td><?= e((string)$b['reason']) ?></td>
              <td>
                <?php if (!$past): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="op" value="unblock"><input type="hidden" name="block_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= $vid ?>"><input type="hidden" name="view" value="<?= e($view) ?>"><button class="fl-btn fl-btn-good">Lift</button></form>
                <?php else: ?><span class="det-empty">past</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
      <form method="post" class="fl-form">
        <?= csrf_field() ?><input type="hidden" name="op" value="block"><input type="hidden" name="vehicle_id" value="<?= $vid ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="fl-row2">
          <div><label class="bk-k" for="bs<?= $vid ?>">From</label><input id="bs<?= $vid ?>" name="starts_at" type="datetime-local" class="input" required></div>
          <div><label class="bk-k" for="be<?= $vid ?>">Until</label><input id="be<?= $vid ?>" name="ends_at" type="datetime-local" class="input" required></div>
        </div>
        <div><label class="bk-k" for="br<?= $vid ?>">Reason</label><input id="br<?= $vid ?>" name="reason" class="input" placeholder="Service, on loan, driver training"></div>
        <div><button class="fl-btn fl-btn-go">Block this car</button></div>
      </form>
    </div>
  </div>
</dialog>
<?php endforeach; ?>

<dialog class="modal" id="dlg-car" aria-labelledby="dlg-car-t">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="dlg-car-t">Add a car</h2>
      <p class="md-sub">It joins the fleet straight away. Paperwork dates can wait until you have the certificates.</p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <form method="post" class="md-form">
    <?= csrf_field() ?><input type="hidden" name="op" value="save_vehicle">
    <div class="md-body">
      <div class="fl-row2">
        <div><label class="bk-k" for="nmk">Make</label><input id="nmk" name="make" class="input" required placeholder="Cadillac"></div>
        <div><label class="bk-k" for="nmd">Model</label><input id="nmd" name="model" class="input" required placeholder="Escalade"></div>
      </div>
      <div class="fl-row2">
        <div><label class="bk-k" for="nct">Category</label><select id="nct" name="category_id" class="input"><option value="">Uncategorised</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="bk-k" for="npl">Plate</label><input id="npl" name="plate" class="input" placeholder="ELL-7001"></div>
      </div>
      <div class="fl-row2">
        <div><label class="bk-k" for="nyr">Year</label><input id="nyr" name="year" type="number" min="1900" max="2100" class="input" placeholder="2025"></div>
        <div><label class="bk-k" for="nst">Status</label><select id="nst" name="status" class="input"><option value="active">Active</option><option value="maintenance">In the shop</option><option value="inactive">Retired</option></select></div>
      </div>
      <div class="fl-row2">
        <div><label class="bk-k" for="npx">Passengers</label><input id="npx" name="passenger_capacity" type="number" min="1" class="input" value="3"></div>
        <div><label class="bk-k" for="nbg">Bags</label><input id="nbg" name="luggage_capacity" type="number" min="0" class="input" value="2"></div>
      </div>
      <div class="fl-row2">
        <div><label class="bk-k" for="nmi">Per mile ($)</label><input id="nmi" name="per_mile_rate" type="number" step="0.01" min="0" class="input" value="0.00"></div>
        <div><label class="bk-k" for="nhr">Per hour ($)</label><input id="nhr" name="hourly_rate" type="number" step="0.01" min="0" class="input" value="0.00"></div>
      </div>
      <div class="det-block">
        <span class="det-h">Description <span style="letter-spacing:0;text-transform:none;color:#6a6a6a">&mdash; shown on the public fleet page</span></span>
        <textarea class="ql-host" name="description" placeholder="What sets this car apart? Seats, luggage, service notes."></textarea>
      </div>
      <details class="det-block">
        <summary class="fl-btn" style="list-style:none">Paperwork dates</summary>
        <div class="fl-row2" style="margin-top:.6rem">
          <div><label class="bk-k" for="ni">Insurance expires</label><input id="ni" name="insurance_expiry" type="date" class="input"></div>
          <div><label class="bk-k" for="nr">Registration expires</label><input id="nr" name="registration_expiry" type="date" class="input"></div>
        </div>
        <div class="fl-row2" style="margin-top:.55rem">
          <div><label class="bk-k" for="nn">Inspection expires</label><input id="nn" name="inspection_expiry" type="date" class="input"></div>
          <div><label class="bk-k" for="nds">Diamond sticker expires</label><input id="nds" name="diamond_sticker_expiry" type="date" class="input"></div>
        </div>
      </details>
    </div>
    <div class="md-foot">
      <button type="submit" class="fl-btn fl-btn-go"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add the car</button>
      <button type="button" class="fl-btn" data-close-modal>Cancel</button>
      <span class="md-foot-note">Rates can be changed later.</span>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dlg-cat" aria-labelledby="dlg-cat-t">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="dlg-cat-t">Add a category</h2>
      <p class="md-sub">Categories group the fleet and drive the pricing rules. Adding one that already exists just updates its description.</p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="op" value="save_category">
    <div class="md-body">
      <div><label class="bk-k" for="cn">Category name</label><input id="cn" name="name" class="input" required placeholder="Classic Limousine"></div>
      <div class="det-block">
        <span class="det-h">Description</span>
        <textarea class="ql-host" name="description" placeholder="What belongs in this category?"></textarea>
      </div>
      <?php if ($cats): ?>
        <div class="det-block">
          <span class="det-h">Already defined</span>
          <div class="table-wrap"><table class="fl-table">
            <thead><tr><th>Name</th><th>Covers</th><th>Cars</th></tr></thead>
            <tbody>
            <?php foreach ($cats as $c):
              $used = 0;
              foreach ($vehicles as $v) if ((int)($v['category_id'] ?? 0) === (int)$c['id']) $used++; ?>
              <tr><td><?= e($c['name']) ?></td><td style="color:#8a8a8a"><?= e((string)$c['description']) ?></td><td class="tabular"><?= $used ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      <?php endif; ?>
    </div>
    <div class="md-foot">
      <button type="submit" class="fl-btn fl-btn-go"><i class="fa-solid fa-check" aria-hidden="true"></i> Save category</button>
      <button type="button" class="fl-btn" data-close-modal>Cancel</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dlg-risky" aria-labelledby="dlg-risky-t">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="dlg-risky-t">Paperwork to sort</h2>
      <p class="md-sub">These cars cannot be safely booked until their dates are current.</p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <div class="md-body">
    <?php
    $urgent = array_values(array_filter($vehicles, static fn($v) => $v['_lapsed'] || ($v['_worst'] !== null && $v['_worst'] <= 30)));
    if (!$urgent): ?>
      <p class="det-empty">Nothing outstanding. Every docket is in date.</p>
    <?php else: ?>
      <div class="det-facts" style="border:0;padding:0;grid-template-columns:1fr">
      <?php foreach ($urgent as $v): ?>
        <div class="det-f">
          <span class="det-k"><?= e((string)($v['plate'] ?: 'no plate')) ?></span>
          <span class="det-v">
            <button type="button" class="fl-linkbtn" data-open-modal="dlg-car-<?= (int)$v['id'] ?>"><?= e($v['make'] . ' ' . $v['model']) ?></button>
            &mdash; <?= $v['_lapsed']
                ? e(strtolower((string)($v['_lapsedLabel'] ?: 'paperwork'))) . ' ran out ' . e($fmtDays($v['_worst']))
                : e(strtolower((string)($v['_worstLabel'] ?: 'paperwork'))) . ' due in ' . e($fmtDays($v['_worst'])) ?>
          </span>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</dialog>
<?php
$content = ob_get_clean();
$pageTitle = 'Fleet | Admin';
$navActive = 'vehicles.php';
require APP_ROOT . '/views/layouts/admin.php';