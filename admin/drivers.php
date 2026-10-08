<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;
$back = 'admin/drivers.php';

require_once __DIR__ . '/../app/richtext.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $view = (string)($_POST['view'] ?? '');

    if ($op === 'status' && $id) {
        $ns = in_array($_POST['status'] ?? '', ['pending', 'active', 'inactive', 'suspended'], true) ? $_POST['status'] : 'pending';
        $st = $pdo->prepare('SELECT name, status FROM drivers WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $d = $st->fetch();
        if (!$d) {
            $msg = 'That chauffeur is not on file.';
            $isErr = true;
        } elseif ($d['status'] === $ns) {
            $msg = $d['name'] . ' is already ' . $ns . '.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE drivers SET status = ? WHERE id = ?')->execute([$ns, $id]);
            // Not written to driver_status_logs: that table is the driver's live
            // trip log and its booking_id is NOT NULL + FK to bookings, so a
            // clearance (pending -> active) has no ride to hang it on. The audit
            // log below is the right place for an admin-originated change.
            audit($pdo, 'admin', (int)$admin['id'], 'driver.status', 'driver', $id, ['from' => $d['status'], 'to' => $ns]);
            $msg = $d['name'] . ' is now ' . ($ns === 'active' ? 'cleared to drive' : $ns) . '.';
        }
    } elseif ($op === 'save_driver') {
        $name = trim((string)($_POST['name'] ?? ''));
        $lic = trim((string)($_POST['license_number'] ?? ''));
        $le = trim((string)($_POST['license_expiry'] ?? ''));
        $led = $le !== '' ? DateTime::createFromFormat('!Y-m-d', $le) : false;
        if ($name === '') {
            $msg = 'A chauffeur needs a name.';
            $isErr = true;
        } elseif ($le !== '' && (!$led || $led->format('Y-m-d') !== $le)) {
            $msg = 'That licence expiry is not a date we can read.';
            $isErr = true;
        } else {
            // name, phone, reference, licence number, licence expiry, description, payout
            $vals = [
                $name,
                trim((string)($_POST['phone'] ?? '')) ?: null,
                strtoupper(trim((string)($_POST['reference'] ?? ''))) ?: null,
                $lic ?: null,
                ($led ? $le : null),
                clean_html($_POST['description'] ?? ''),
                trim((string)($_POST['payout_reference'] ?? '')) ?: null,
            ];
            if ($id) {
                $pdo->prepare('UPDATE drivers SET name=?, phone=?, reference=?, license_number=?, license_expiry=?, description=?, payout_reference=? WHERE id=?')
                    ->execute(array_merge($vals, [$id]));
                audit($pdo, 'admin', (int)$admin['id'], 'driver.updated', 'driver', $id, null);
                $msg = $name . ' saved.';
            } else {
                $email = trim((string)($_POST['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $msg = 'A new chauffeur needs a valid email address to sign in with.';
                    $isErr = true;
                } elseif ($pdo->query('SELECT id FROM drivers WHERE email = ' . $pdo->quote($email))->fetch()) {
                    $msg = $email . ' already has an account.';
                    $isErr = true;
                } else {
                    $pdo->prepare('INSERT INTO drivers (name, email, phone, status, reference, license_number, license_expiry, description, payout_reference) VALUES (?,?,?,"pending",?,?,?,?,?)')
                        ->execute(array_merge([$name, $email], [$vals[1]], [$vals[2]], [$vals[3]], [$vals[4]], [$vals[5]], [$vals[6]]));
                    $id = (int)$pdo->lastInsertId();
                    audit($pdo, 'admin', (int)$admin['id'], 'driver.created', 'driver', $id, null);
                    $msg = $name . ' added. Clear their licence before they take a trip.';
                }
            }
        }
    }
    header('Location: ' . url($back . '?' . http_build_query(array_filter(['id' => $id ?: null, 'view' => $view, 'msg' => $msg] + ($isErr ? ['err' => 1] : [])))));
    exit;
}

if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$isErr = isset($_GET['err']);
$selId = (int)($_GET['id'] ?? 0);
$view = (string)($_GET['view'] ?? '');

$st = $pdo->query('SELECT d.*,
      (SELECT COUNT(*) FROM bookings b JOIN dispatches x ON x.booking_id = b.id WHERE x.driver_id = d.id AND b.status = "finish") AS rides_done,
      (SELECT COUNT(*) FROM bookings b JOIN dispatches x ON x.booking_id = b.id WHERE x.driver_id = d.id AND b.status NOT IN ("finish","cancelled","refunded")) AS rides_open
    FROM drivers d ORDER BY d.name');
$drivers = $st->fetchAll();

// driver_documents is deliberately not read: paperwork for a chauffeur is the
// licence date on this record, so the certificates table adds nothing.

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
$ready = 0;
$pending = 0;
$expiring = 0;
$onTrip = 0;
$risky = [];
// Index loop rather than `foreach ($drivers as &$d)` — the derived flags are read
// back from $drivers afterwards, and this leaves no dangling reference behind.
foreach ($drivers as $i => $row) {
    $lic = days_until($row['license_expiry'] ?? null);

    $drivers[$i]['_lic'] = $lic;
    $drivers[$i]['_lapsed'] = $lic !== null && $lic < 0;

    if ($row['status'] === 'active' && !($lic !== null && $lic < 0)) $ready++;
    if ($row['status'] === 'pending') $pending++;
    if ((int)$row['rides_open'] > 0) $onTrip++;
    if ($lic !== null && $lic <= 45) { $expiring++; $risky[] = $row['name']; }
    elseif ($lic !== null && $lic < 0) { $risky[] = $row['name']; }
}

$buckets = [
    'pending' => static fn($d) => $d['status'] === 'pending',
    'licence' => static fn($d) => $d['_lic'] !== null && $d['_lic'] <= 45,
    'away' => static fn($d) => in_array($d['status'], ['suspended', 'inactive'], true) || (int)$d['rides_open'] > 0,
    'ready' => static fn($d) => $d['status'] === 'active' && !$d['_lapsed'],
];
$counts = [];
foreach ($buckets as $k => $fn) {
    $n = count(array_filter($drivers, $fn));
    if ($n > 0 && $n < count($drivers)) $counts[$k] = $n;
}
$view = isset($counts[$view]) || $view === '' ? $view : '';
$shown = $view === '' ? $drivers : array_values(array_filter($drivers, $buckets[$view]));

$LABELS = ['pending' => 'Awaiting clearance', 'licence' => 'Licence due', 'away' => 'Unavailable or on a trip', 'ready' => 'Cleared to drive'];
$NEXT = [
    'pending' => ['active' => ['Clear to drive', 'fl-btn-go'], 'inactive' => ['Mark off the roster', '']],
    'active' => ['pending' => ['Send back for clearance', ''], 'suspended' => ['Suspend', 'fl-btn-danger']],
    'suspended' => ['active' => ['Clear to drive', 'fl-btn-go'], 'pending' => ['Send back for clearance', '']],
    'inactive' => ['active' => ['Clear to drive', 'fl-btn-go'], 'suspended' => ['Suspend', '']],
];

ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="fl-head">
  <div>
    <p class="eyebrow">Fleet</p>
    <h1 class="font-display">The crew</h1>
    <p class="cu-sub">A chauffeur can only take a trip once their licence is in date and their paperwork is verified. Open one to see the record.</p>
  </div>
  <div class="fl-head-acts">
    <button type="button" class="fl-btn fl-btn-go" data-open-modal="dlg-drv"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add driver</button>
  </div>
</header>

<p class="fl-figures">
  <span><b><?= count($drivers) ?></b> chauffeurs</span>
  <span><b class="<?= $ready < count($drivers) ? 'is-warn' : '' ?>"><?= $ready ?></b> cleared to drive</span>
  <?php if ($pending): ?><span><b class="is-warn"><?= $pending ?></b> awaiting clearance</span><?php endif; ?>
  <?php if ($expiring): ?><span><b class="is-warn"><?= $expiring ?></b> licence due</span><?php endif; ?>
  <?php if ($onTrip): ?><span><b class="is-warn"><?= $onTrip ?></b> on a trip</span><?php endif; ?>
</p>

<?php if ($risky): ?>
  <p class="fl-alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= e(implode(', ', array_slice($risky, 0, 3))) ?><?= count($risky) > 3 ? ' and ' . (count($risky) - 3) . ' more' : '' ?> <?= count($risky) === 1 ? 'has' : 'have' ?> a licence that has run out or is about to. <button type="button" class="fl-linkbtn" data-open-modal="dlg-risky">Show me</button></p>
<?php endif; ?>

<?php if ($counts): ?>
<nav class="fl-filters" aria-label="Filter the crew">
  <a class="fl-filter<?= $view === '' ? ' is-on' : '' ?>" href="<?= url('admin/drivers.php') ?>"<?= $view === '' ? ' aria-current="true"' : '' ?>>Everyone</a>
  <?php foreach ($counts as $k => $n): ?>
    <a class="fl-filter<?= $view === $k ? ' is-on' : '' ?>" href="<?= url('admin/drivers.php?view=' . $k) ?>"<?= $view === $k ? ' aria-current="true"' : '' ?>><?= e($LABELS[$k]) ?> <b><?= $n ?></b></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if (!$shown): ?>
  <p class="fl-empty-t" style="margin-top:1.4rem"><?= $view === '' ? 'No chauffeurs on the roster yet.' : 'Nobody in this filter.' ?></p>
  <p class="fl-empty-sub"><?= $view === '' ? 'Add the first chauffeur. They stay pending until you clear their licence.' : 'Try another filter, or add a chauffeur.' ?></p>
<?php else: ?>
<ul class="tiles">
<?php foreach ($shown as $d):
    $did = (int)$d['id'];
    $ini = '';
    foreach (preg_split('/\s+/', trim((string)$d['name'])) ?: [] as $w) if ($w !== '') $ini .= mb_strtoupper(mb_substr($w, 0, 1));
    $edge = $d['_lapsed'] ? ' t-lapsed' : (($d['_lic'] !== null && $d['_lic'] <= 45) ? ' t-warn' : '');
?>
  <li>
    <button type="button" class="tile<?= $edge ?>" data-open-modal="dlg-drv-<?= $did ?>" aria-label="Open <?= e($d['name']) ?>">
<span class="tile-ava"><?= e($ini !== '' ? $ini : '?') ?></span>
  <span class="tile-text">
  <span class="tile-name"><?= e($d['name']) ?></span>
  <span class="tile-rates"><span><b><?= (int)$d['rides_done'] ?></b> trips</span><?php if ((int)$d['rides_open'] > 0): ?><span><b><?= (int)$d['rides_open'] ?></b> open</span><?php endif; ?></span>
  </span>
  </button>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php foreach ($shown as $d):
    $did = (int)$d['id'];
    $ini = '';
    foreach (preg_split('/\s+/', trim((string)$d['name'])) ?: [] as $w) if ($w !== '') $ini .= mb_strtoupper(mb_substr($w, 0, 1));
    [$lcls, $lpct] = $sev($d['_lic']);
?>
<dialog class="modal is-wide" id="dlg-drv-<?= $did ?>" aria-labelledby="dlg-drv-t-<?= $did ?>">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="dlg-drv-t-<?= $did ?>"><?= e($d['name']) ?></h2>
      <p class="md-sub"><?= e((string)($d['reference'] ?: 'No reference')) ?><?= $d['license_number'] ? ' &middot; ' . e((string)$d['license_number']) : '' ?></p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>

  <div class="md-body">
    <form method="post" class="fl-form">
      <?= csrf_field() ?><input type="hidden" name="op" value="save_driver"><input type="hidden" name="id" value="<?= $did ?>"><input type="hidden" name="view" value="<?= e($view) ?>">

      <div class="det-hero">
        <span class="det-ava"><?= e($ini !== '' ? $ini : '?') ?></span>
        <div class="det-facts" style="border:0;padding:0;flex:1">
          <div class="det-f"><span class="det-k">Status</span><span class="det-v"><?= status_pill((string)$d['status']) ?></span></div>
          <div class="det-f"><span class="det-k">Completed</span><span class="det-v"><?= (int)$d['rides_done'] ?> trips</span></div>
          <div class="det-f"><span class="det-k">Open</span><span class="det-v"><?= (int)$d['rides_open'] ?> trips</span></div>
          <div class="det-f"><span class="det-k">Phone</span><span class="det-v"><?= $d['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', (string)$d['phone'])) . '">' . e((string)$d['phone']) . '</a>' : '&mdash;' ?></span></div>
        </div>
      </div>

      <div class="det-block">
        <span class="det-h">Docket</span>
        <div class="fl-clock" style="border:0;padding:.3rem 0 0;max-width:none">
          <div class="fl-doc <?= $lcls ?>">
            <span class="fl-doc-k">Licence</span>
            <span class="fl-doc-bar"><i class="fl-doc-fill" style="width:<?= $lpct ?>%"></i></span>
            <span class="fl-doc-d"><?= $d['_lic'] === null ? 'missing' : e($fmtDays($d['_lic'])) ?></span>
          </div>
          
        </div>
      </div>

      <div class="det-block">
        <span class="det-h">Record</span>
        <div class="fl-row2">
          <div><label class="bk-k" for="dn<?= $did ?>">Name</label><input id="dn<?= $did ?>" name="name" class="input" required placeholder="Marisol Reyes" value="<?= e($d['name']) ?>"></div>
          <div><label class="bk-k" for="dp<?= $did ?>">Phone</label><input id="dp<?= $did ?>" name="phone" class="input" placeholder="718-555-0142" value="<?= e((string)$d['phone']) ?>"></div>
        </div>
        <div><label class="bk-k" for="de<?= $did ?>">Email</label><input id="de<?= $did ?>" class="input" value="<?= e($d['email']) ?>" disabled><span class="det-empty">This is the address they sign in with, so it is not changed here.</span></div>
        <div class="fl-row2">
          <div><label class="bk-k" for="dr<?= $did ?>">Reference</label><input id="dr<?= $did ?>" name="reference" class="input" value="<?= e((string)$d['reference']) ?>" placeholder="ELL-CH-0142"></div>
          <div><label class="bk-k" for="dl<?= $did ?>">Licence number</label><input id="dl<?= $did ?>" name="license_number" class="input" value="<?= e((string)$d['license_number']) ?>" placeholder="NY-CD-88213"></div>
        </div>
        <div class="fl-row2">
          <div><label class="bk-k" for="dle<?= $did ?>">Licence expires</label><input id="dle<?= $did ?>" name="license_expiry" type="date" class="input" placeholder="mm/dd/yyyy" value="<?= e((string)($d['license_expiry'] ?? '')) ?>"></div>
          <div><label class="bk-k" for="dq<?= $did ?>">Payout reference</label><input id="dq<?= $did ?>" name="payout_reference" class="input" value="<?= e((string)$d['payout_reference']) ?>" placeholder="Chase account and last four digits"></div>
        </div>
        <p class="det-empty">A payout reference is a handle and last four digits only. Never a full card or account number.</p>
      </div>

      <div class="det-block">
        <span class="det-h">About this chauffeur</span>
        <textarea class="ql-host" name="description" placeholder="Languages spoken, usual routes, anything the desk should know."><?= e((string)($d['description'] ?? '')) ?></textarea>
      </div>
      <div class="det-acts">
        <button type="submit" class="fl-btn fl-btn-go"><i class="fa-solid fa-check" aria-hidden="true"></i> Save <?= e($d['name']) ?></button>
      </div>
    </form>

</div>

  <div class="md-foot">
    <form method="post" class="det-acts">
      <?= csrf_field() ?><input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= $did ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
      <?php foreach ($NEXT[$d['status']] ?? [] as $to => [$label, $cls]): ?>
        <button type="submit" class="fl-btn <?= e($cls) ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </form>
    <span class="md-foot-note">Currently <?= e($d['status']) ?>.</span>
  </div>
</dialog>
<?php endforeach; ?>

<dialog class="modal" id="dlg-drv" aria-labelledby="dlg-drv-t">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="dlg-drv-t">Add a driver</h2>
      <p class="md-sub">They join as pending and cannot take a trip until you clear their licence.</p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="op" value="save_driver">
    <div class="md-body">
      <div class="fl-row2">
        <div><label class="bk-k" for="an">Name</label><input id="an" name="name" class="input" required placeholder="Marisol Reyes"></div>
        <div><label class="bk-k" for="ae">Email</label><input id="ae" name="email" type="email" class="input" required placeholder="name@example.com"></div>
      </div>
      <div class="fl-row2">
        <div><label class="bk-k" for="ap">Phone</label><input id="ap" name="phone" class="input" placeholder="718-555-0142"></div>
        <div><label class="bk-k" for="ar">Reference</label><input id="ar" name="reference" class="input" placeholder="ELL-CH-0142"></div>
      </div>
      <div class="fl-row2">
        <div><label class="bk-k" for="al">Licence number</label><input id="al" name="license_number" class="input" placeholder="NY-CD-88213"></div>
        <div><label class="bk-k" for="ale">Licence expires</label><input id="ale" name="license_expiry" type="date" class="input" placeholder="mm/dd/yyyy"></div>
      </div>
      <div><label class="bk-k" for="aq">Payout reference</label><input id="aq" name="payout_reference" class="input" placeholder="Chase account and last four"></div>
      <div class="det-block">
        <span class="det-h">About this driver</span>
        <textarea class="ql-host" name="description" placeholder="Languages spoken, usual routes, anything the desk should know."></textarea>
      </div>
    </div>
    <div class="md-foot">
      <button type="submit" class="fl-btn fl-btn-go"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add to the crew</button>
      <button type="button" class="fl-btn" data-close-modal>Cancel</button>
      <span class="md-foot-note">Starts as pending.</span>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dlg-risky" aria-labelledby="dlg-risky-t">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="dlg-risky-t">Licences to sort</h2>
      <p class="md-sub">These chauffeurs cannot be dispatched until the licence is current.</p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <div class="md-body">
    <?php
    $urgent = array_values(array_filter($drivers, static fn($d) => $d['_lic'] !== null && $d['_lic'] <= 45));
    if (!$urgent): ?>
      <p class="det-empty">Nothing outstanding. Every licence is in date.</p>
    <?php else: ?>
      <div class="det-facts" style="border:0;padding:0;grid-template-columns:1fr">
      <?php foreach ($urgent as $d): ?>
        <div class="det-f">
          <span class="det-k"><?= e((string)($d['reference'] ?: 'no reference')) ?></span>
          <span class="det-v">
            <button type="button" class="fl-linkbtn" data-open-modal="dlg-drv-<?= (int)$d['id'] ?>"><?= e($d['name']) ?></button>
            &mdash; <?= $d['_lapsed'] ? 'expired ' . e($fmtDays($d['_lic'])) : 'due in ' . e($fmtDays($d['_lic'])) ?>
          </span>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</dialog>
<?php
$content = ob_get_clean();
$pageTitle = 'Crew | Admin';
$navActive = 'drivers.php';
require APP_ROOT . '/views/layouts/admin.php';