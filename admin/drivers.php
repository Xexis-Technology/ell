<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;
$back = 'admin/drivers.php';

// Declared before the POST handler because the realtime field endpoint answers
// with the same countdown the docket bar shows, in the same wording.
$fmtDays = static function (?int $n): string {
    if ($n === null) return 'not recorded';
    if ($n < 0) return abs($n) . 'd ago';
    if ($n === 0) return 'today';
    if ($n < 45) return $n . 'd';
    if ($n < 365) return intdiv($n, 30) . 'mo';
    return number_format($n / 365, 1) . 'y';
};

require_once __DIR__ . '/../app/richtext.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $view = (string)($_POST['view'] ?? '');

    /*
      One field, saved the moment the desk stops typing.

      The office corrects a driver's record while they are on the phone about it,
      and making them hunt for a Save button first is how a wrong number stays
      wrong. Returns JSON so the caller can refresh the parts of the popup that
      are derived from the value rather than reloading the whole page and losing
      the driver's place.
    */
    if ($op === 'field_edit' && $id) {
        header('Content-Type: application/json');
        $EDITABLE = [
            'name', 'phone', 'email', 'reference', 'license_number', 'license_expiry',
            'payout_reference', 'date_of_birth', 'medical_cert_expiry',
            'background_check_date', 'drug_test_date',
        ];
        $field = (string)($_POST['field'] ?? '');
        $reply = static function (array $payload): never {
            echo json_encode($payload);
            exit;
        };
        if (!in_array($field, $EDITABLE, true)) {
            http_response_code(400);
            $reply(['ok' => false, 'error' => 'That detail cannot be changed from here.']);
        }

        $cur = $pdo->prepare('SELECT * FROM drivers WHERE id = ? LIMIT 1');
        $cur->execute([$id]);
        $row = $cur->fetch();
        if (!$row) {
            $reply(['ok' => false, 'error' => 'That chauffeur is not on file.']);
        }

        $raw = trim((string)($_POST['value'] ?? ''));
        $stored = null;

        if ($field === 'name' && $raw === '') {
            $reply(['ok' => false, 'error' => 'A chauffeur needs a name.']);
        }
        if ($field === 'email') {
            if ($raw === '' || !validate_email($raw)) {
                $reply(['ok' => false, 'error' => 'Enter a valid email address.']);
            }
            $dupe = $pdo->prepare('SELECT id FROM drivers WHERE email = ? AND id <> ? LIMIT 1');
            $dupe->execute([$raw, $id]);
            if ($dupe->fetch()) {
                $reply(['ok' => false, 'error' => 'Another chauffeur already uses that email.']);
            }
            $stored = $raw;
        }
        if (in_array($field, ['name', 'phone', 'reference', 'license_number', 'payout_reference'], true)) {
            // Every plain text field. `name` belongs in this list: it was missing
            // once, and a field with no branch never reaches $stored, so the
            // update below wrote NULL over the chauffeur's name.
            $stored = $raw === '' ? null : $raw;
            if ($field === 'reference' && $stored !== null) $stored = strtoupper($stored);
            if ($field === 'license_number' && $stored !== null) $stored = strtoupper($stored);
        }
        if ($field === 'license_number' && $stored !== null && mb_strlen($stored) > 40) {
            $reply(['ok' => false, 'error' => 'That licence number is too long to be one.']);
        }
        if (in_array($field, ['license_expiry', 'date_of_birth', 'medical_cert_expiry', 'background_check_date', 'drug_test_date'], true)) {
            if ($raw === '') {
                $stored = null;
            } else {
                $parsed = DateTime::createFromFormat('!Y-m-d', $raw);
                if (!$parsed || $parsed->format('Y-m-d') !== $raw) {
                    $reply(['ok' => false, 'error' => 'That is not a date the office can read.']);
                }
                $stored = $raw;
            }
        }

        // Nothing typed that differs from what is already stored. Repeated
        // autosaves while the desk is still typing an email would otherwise write
        // an audit row per keystroke.
        if ((string)($row[$field] ?? '') === (string)($stored ?? '')) {
            $reply(['ok' => true, 'changed' => false, 'value' => (string)($stored ?? '')]);
        }

        $pdo->prepare("UPDATE drivers SET {$field} = ? WHERE id = ?")->execute([$stored, $id]);
        audit($pdo, 'admin', (int)$admin['id'], 'driver.field_edited', 'driver', $id,
            ['field' => $field, 'from' => $row[$field] ?? null, 'to' => $stored]);

        $days = days_until($stored);
        // Mirrors the docket bar's own maths so the bar and the countdown beside
        // the expiry field move together instead of one lagging a page reload.
        $pct = 0;
        $sevCls = 'is-none';
        if ($field === 'license_expiry') {
            if ($days !== null) {
                $pct = $days < 0 ? 3 : max(3, min(100, (int)round($days / 365 * 100)));
                $sevCls = $days <= 30 ? 'is-bad' : ($days <= 90 ? 'is-warn' : '');
            }
        }
        $reply([
            'ok' => true,
            'changed' => true,
            'field' => $field,
            'value' => (string)($stored ?? ''),
            'shown' => $stored === null ? '' : ($field === 'name' || $field === 'email'
                ? (string)$stored
                : (in_array($field, ['license_expiry', 'date_of_birth', 'medical_cert_expiry', 'background_check_date', 'drug_test_date'], true)
                    ? date('j M Y', (int)strtotime((string)$stored))
                    : (string)$stored)),
            'days' => $field === 'license_expiry' ? $days : null,
            'days_shown' => $field === 'license_expiry'
                ? ($days === null ? 'missing' : $fmtDays($days))
                : null,
            'pct' => $pct,
            'sev' => $sevCls,
        ]);
    }

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
        /*
          The clearance dates the driver typed themselves. Keeping them editable
          at the desk matters more than it looks: these are the fields most often
          mistyped by a driver on a phone, and asking the chauffeur to sign in to
          fix a single wrong digit costs the office a phone call either way.
        */
        $asDay = static function (string $v) {
            $v = trim($v);
            if ($v === '') return null;
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            return ($d && $d->format('Y-m-d') === $v) ? $v : false;
        };
        $dob = $asDay((string)($_POST['date_of_birth'] ?? ''));
        $med = $asDay((string)($_POST['medical_cert_expiry'] ?? ''));
        $bg = $asDay((string)($_POST['background_check_date'] ?? ''));
        $drug = $asDay((string)($_POST['drug_test_date'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($name === '') {
            $msg = 'A chauffeur needs a name.';
            $isErr = true;
        } elseif ($le !== '' && (!$led || $led->format('Y-m-d') !== $le)) {
            $msg = 'That licence expiry is not a date we can read.';
            $isErr = true;
        } elseif (in_array(false, [$dob, $med, $bg, $drug], true)) {
            $msg = 'One of the clearance dates is not a date we can read.';
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
                // The desk can correct the sign-in address too. The driver can
                // still change it themselves; both paths check for a clash,
                // because two chauffeurs on one address makes the lookup ambiguous.
                if ($email !== '' && !validate_email($email)) {
                    $msg = 'That sign-in email is not an address we can read.';
                    $isErr = true;
                } elseif ($email !== '' && $pdo->query('SELECT id FROM drivers WHERE email = ' . $pdo->quote($email) . ' AND id <> ' . $id)->fetch()) {
                    $msg = $email . ' already has an account.';
                    $isErr = true;
                } else {
                    $pdo->prepare('UPDATE drivers SET name=?, phone=?, reference=?, license_number=?, license_expiry=?, description=?, payout_reference=?, date_of_birth=?, medical_cert_expiry=?, background_check_date=?, drug_test_date=?, email=? WHERE id=?')
                        ->execute(array_merge($vals, [$dob, $med, $bg, $drug, $email ?: null, $id]));
                    audit($pdo, 'admin', (int)$admin['id'], 'driver.updated', 'driver', $id, null);
                    $msg = $name . ' saved.';
                }
            } else {
                $pw = (string)($_POST['password'] ?? '');
                $pw2 = (string)($_POST['password_confirm'] ?? '');
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $msg = 'A new chauffeur needs a valid email address to sign in with.';
                    $isErr = true;
                } elseif ($pw === '' || $pw !== $pw2) {
                    // The driver signs in with email + password, and there is no
                    // self-registration, so if the office does not set one here
                    // the account can never be opened by anybody.
                    $msg = 'Set a password for this chauffeur, and type it the same way twice.';
                    $isErr = true;
                } elseif ($err = validate_password($pw)) {
                    $msg = $err;
                    $isErr = true;
                } elseif ($pdo->query('SELECT id FROM drivers WHERE email = ' . $pdo->quote($email))->fetch()) {
                    $msg = $email . ' already has an account.';
                    $isErr = true;
                } else {
                    // Placeholder order matches the column list: name, email, phone, password_hash,
// status (literal "pending"), reference, license_number, license_expiry,
// description, payout_reference, then the four clearance dates. $vals[0] is the
// name and is already passed separately, so the insert starts from $vals[1].
                    $pdo->prepare('INSERT INTO drivers (name, email, phone, password_hash, status, reference, license_number, license_expiry, description, payout_reference, date_of_birth, medical_cert_expiry, background_check_date, drug_test_date) VALUES (?,?,?,?,"pending",?,?,?,?,?,?,?,?,?)')
                        ->execute(array_merge(
                            [$name, $email, $vals[1]],
                            [hash_password($pw)],
                            [$vals[2], $vals[3], $vals[4], $vals[5], $vals[6]],
                            [$dob, $med, $bg, $drug]
                        ));
                    $id = (int)$pdo->lastInsertId();
                    audit($pdo, 'admin', (int)$admin['id'], 'driver.created', 'driver', $id, null);
                    $msg = $name . ' added. They sign in with that email and password, then upload their licence and picture before they can drive.';
                }
            }
        }
    }

    // Setting or resetting a password is its own action: it is how the office
    // gets a driver back in after they lose theirs, and it is deliberately
    // not part of the record edit above.
    if ($op === 'set_password' && $id) {
            $pw = (string)($_POST['password'] ?? '');
            $pw2 = (string)($_POST['password_confirm'] ?? '');
            $st = $pdo->prepare('SELECT name FROM drivers WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $who = $st->fetch();
            if (!$who) {
                $msg = 'That chauffeur is not on file.';
                $isErr = true;
            } elseif ($pw === '' || $pw !== $pw2) {
                $msg = 'Type the new password the same way twice.';
                $isErr = true;
            } elseif ($err = validate_password($pw)) {
                $msg = $err;
                $isErr = true;
            } else {
                $pdo->prepare('UPDATE drivers SET password_hash = ? WHERE id = ?')->execute([hash_password($pw), $id]);
                audit($pdo, 'admin', (int)$admin['id'], 'driver.password_reset', 'driver', $id, null);
                $msg = 'Password reset for ' . $who['name'] . '. Tell it to them directly.';
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

    // What the driver actually sent: their picture for the manifest and the
    // photograph of their licence. Read per dialog rather than in the list
    // query so the crew page does not carry a join nobody looks at.
    $docStmt = $pdo->prepare("SELECT doc_type, file_path, status, expiry_date, created_at
                              FROM driver_documents WHERE driver_id = ?
                              AND file_path IS NOT NULL AND file_path <> ''
                              ORDER BY id DESC");
    $docStmt->execute([$did]);
    $docs = [];
    foreach ($docStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $kind = in_array($row['doc_type'], ['photo', 'selfie'], true) ? 'photo' : 'licence';
        // The newest of each kind wins, so a re-shoot replaces the old one.
        if (!isset($docs[$kind])) $docs[$kind] = $row;
    }
    $photoUrl = isset($docs['photo']) ? media_url((string)$docs['photo']['file_path']) : '';
    $licUrl = isset($docs['licence']) ? media_url((string)$docs['licence']['file_path']) : '';
    $photoLoaded = $photoUrl !== '';
    $licLoaded = $licUrl !== '';
    $stepState = DriverPortal::steps($pdo, $d);
    $doneCount = count(array_filter($stepState, static fn($s) => $s['done']));
    $missing = array_values(array_filter($stepState, static fn($s) => !$s['done']));
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
        <?php /* The driver's own picture, because they took it at the kerb for the
                 guest manifest. Initials underneath so a missing or unreadable
                 file degrades to the old avatar instead of a broken frame. */ ?>
        <span class="det-ava">
          <span class="det-ava-i" aria-hidden="true"><?= e($ini !== '' ? $ini : '?') ?></span>
          <?php if ($photoLoaded): ?>
            <img src="<?= e($photoUrl) ?>" alt="<?= e($d['name']) ?>" loading="lazy" decoding="async" onerror="this.remove()">
          <?php endif; ?>
        </span>
        <div class="det-facts" style="border:0;padding:0;flex:1">
          <div class="det-f"><span class="det-k">Status</span><span class="det-v"><?= status_pill((string)$d['status']) ?></span></div>
          <div class="det-f"><span class="det-k">Completed</span><span class="det-v"><?= (int)$d['rides_done'] ?> trips</span></div>
          <div class="det-f"><span class="det-k">Open</span><span class="det-v"><?= (int)$d['rides_open'] ?> trips</span></div>
          <div class="det-f"><span class="det-k">Phone</span><span class="det-v"><?= $d['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', (string)$d['phone'])) . '">' . e((string)$d['phone']) . '</a>' : '&mdash;' ?></span></div>
        </div>
      </div>

      <?php /* The licence as the driver photographed it - the artefact the office
               actually checks, rather than trusting the typed number. */ ?>
      <div class="det-block">
        <span class="det-h">What the driver sent</span>
        <div class="drv-shots">
          <figure class="drv-shot">
            <?php /* Review status rides with the label: a caption on its own line made
                     this panel taller than the one beside it. */ ?>
            <span class="drv-shot-k">Driving licence<?php if ($licLoaded && isset($docs['licence']['status'])): ?><em><?= e(ucfirst((string)$docs['licence']['status'])) ?></em><?php endif; ?></span>
            <?php if ($licLoaded): ?>
              <a href="<?= e($licUrl) ?>" target="_blank" rel="noopener">
                <img src="<?= e($licUrl) ?>" alt="Photographed driving licence" loading="lazy" decoding="async" onerror="this.remove()">
              </a>
            <?php else: ?>
              <span class="drv-shot-none"><i class="fa-solid fa-camera-slash" aria-hidden="true"></i> Not photographed</span>
            <?php endif; ?>
          </figure>
          <figure class="drv-shot">
            <span class="drv-shot-k">Driver picture</span>
            <?php if ($photoLoaded): ?>
              <a href="<?= e($photoUrl) ?>" target="_blank" rel="noopener">
                <img src="<?= e($photoUrl) ?>" alt="Driver picture" loading="lazy" decoding="async" onerror="this.remove()">
              </a>
            <?php else: ?>
              <span class="drv-shot-none"><i class="fa-solid fa-user-slash" aria-hidden="true"></i> Not taken</span>
            <?php endif; ?>
          </figure>
        </div>
        <?php /* The admin decides who is cleared. Say plainly what is on file so
                 that decision is an informed one - but do not block it. */ ?>
        <?php if ($missing): ?>
          <p class="det-empty">Still to send: <?= e(implode(', ', array_map(static fn($s) => strtolower($s['title']), $missing))) ?>. You can clear them anyway.</p>
        <?php else: ?>
          <p class="det-empty">All three steps are on file<?= $d['clearance_submitted_at'] ? ', sent ' . e(date('j M Y', (int)strtotime((string)$d['clearance_submitted_at']))) : '' ?>.</p>
        <?php endif; ?>
      </div>

      <div class="det-block">
        <span class="det-h">Docket</span>
        <div class="fl-clock" style="border:0;padding:.3rem 0 0;max-width:none">
          <div class="fl-doc <?= $lcls ?>" id="dkdoc<?= $did ?>">
            <span class="fl-doc-k">Licence</span>
            <span class="fl-doc-bar"><i class="fl-doc-fill" id="dkfill<?= $did ?>" style="width:<?= $lpct ?>%"></i></span>
            <span class="fl-doc-d" id="dkdays<?= $did ?>"><?= $d['_lic'] === null ? 'missing' : e($fmtDays($d['_lic'])) ?></span>
          </div>

        </div>
      </div>

      <div class="det-block">
        <span class="det-h">Record</span>
        <?php /*
          Every field here saves itself the moment the desk stops typing - the
          data-live attribute is what the autosave picks up - so the Save button
          at the end is the note below, not the gate in front of these.
        */ ?>
        <div class="fl-row2">
          <div class="bk-cell"><label class="bk-k" for="dn<?= $did ?>">Name</label><input id="dn<?= $did ?>" name="name" class="input" required placeholder="Marisol Reyes" value="<?= e($d['name']) ?>" data-live="name"></div>
          <div class="bk-cell"><label class="bk-k" for="dp<?= $did ?>">Phone</label><input id="dp<?= $did ?>" name="phone" class="input" placeholder="718-555-0142" value="<?= e((string)$d['phone']) ?>" data-live="phone"></div>
        </div>
        <div class="bk-cell"><label class="bk-k" for="de<?= $did ?>">Sign-in email</label><input id="de<?= $did ?>" name="email" type="email" class="input" placeholder="driver@example.com" value="<?= e($d['email']) ?>" data-live="email"><span class="det-empty">They sign in with this. Either of you can change it, and it has to stay unique to them.</span></div>
        <div class="fl-row2">
          <div class="bk-cell"><label class="bk-k" for="dr<?= $did ?>">Reference</label><input id="dr<?= $did ?>" name="reference" class="input" value="<?= e((string)$d['reference']) ?>" placeholder="ELL-CH-0142" data-live="reference"></div>
          <div class="bk-cell"><label class="bk-k" for="dl<?= $did ?>">Licence number</label><input id="dl<?= $did ?>" name="license_number" class="input" value="<?= e((string)$d['license_number']) ?>" placeholder="NY-CD-88213" data-live="license_number"></div>
        </div>
        <div class="fl-row2">
          <div class="bk-cell"><label class="bk-k" for="dle<?= $did ?>">Licence expires</label><input id="dle<?= $did ?>" name="license_expiry" type="date" class="input" value="<?= e((string)($d['license_expiry'] ?? '')) ?>" data-live="license_expiry"></div>
          <div class="bk-cell"><label class="bk-k" for="dq<?= $did ?>">Payout reference</label><input id="dq<?= $did ?>" name="payout_reference" class="input" value="<?= e((string)$d['payout_reference']) ?>" placeholder="Chase account and last four digits" data-live="payout_reference"></div>
        </div>
        <p class="det-empty">A payout reference is a handle and last four digits only. Never a full card or account number.</p>

        <fieldset class="bk-set">
          <legend class="bk-set-h">Clearance record</legend>
          <p class="bk-set-note">The dates the office holds. A driver types these themselves, so they are the ones most often a digit out.</p>
          <div class="fl-row2">
            <div class="bk-cell"><label class="bk-k" for="ddob<?= $did ?>">Date of birth</label><input id="ddob<?= $did ?>" name="date_of_birth" type="date" class="input" value="<?= e((string)($d['date_of_birth'] ?? '')) ?>" data-live="date_of_birth"></div>
            <div class="bk-cell"><label class="bk-k" for="dmed<?= $did ?>">Medical expires</label><input id="dmed<?= $did ?>" name="medical_cert_expiry" type="date" class="input" value="<?= e((string)($d['medical_cert_expiry'] ?? '')) ?>" data-live="medical_cert_expiry"></div>
          </div>
          <div class="fl-row2">
            <div class="bk-cell"><label class="bk-k" for="dbg<?= $did ?>">Background check</label><input id="dbg<?= $did ?>" name="background_check_date" type="date" class="input" value="<?= e((string)($d['background_check_date'] ?? '')) ?>" data-live="background_check_date"></div>
            <div class="bk-cell"><label class="bk-k" for="ddrug<?= $did ?>">Drug test</label><input id="ddrug<?= $did ?>" name="drug_test_date" type="date" class="input" value="<?= e((string)($d['drug_test_date'] ?? '')) ?>" data-live="drug_test_date"></div>
          </div>
        </fieldset>
      </div>

      <div class="det-block">
        <span class="det-h">About this chauffeur</span>
        <textarea class="ql-host" name="description" placeholder="Languages spoken, usual routes, anything the desk should know."><?= e((string)($d['description'] ?? '')) ?></textarea>
      </div>

      <?php /*
        Only what the editable form above cannot show. Every clearance date now
        has an input directly above this, so repeating them here as read-only
        lines was the same fact twice on one screen - the inputs are the record.
      */ ?>
      <div class="det-block">
        <span class="det-h">What is on file</span>
        <dl class="drv-record">
          <div><dt>Paperwork sent</dt><dd><?= $d['clearance_submitted_at'] ? e(date('j M Y', (int)strtotime((string)$d['clearance_submitted_at']))) : '<span class="drv-record-note">not yet</span>' ?></dd></div>
          <div><dt>Licence photo</dt><dd><?= $licLoaded ? '<span class="drv-record-note">on file, ' . e(ucfirst((string)$docs['licence']['status'])) . '</span>' : '<span class="drv-record-note">not photographed</span>' ?></dd></div>
          <div><dt>Driver picture</dt><dd><?= $photoLoaded ? '<span class="drv-record-note">on file, ' . e(ucfirst((string)$docs['photo']['status'])) . '</span>' : '<span class="drv-record-note">not taken</span>' ?></dd></div>
          <div><dt>Last updated</dt><dd><?= $d['updated_at'] ? e(date('j M Y', (int)strtotime((string)$d['updated_at']))) : '<span class="drv-record-note">unknown</span>' ?></dd></div>
        </dl>
      </div>
      <div class="det-acts">
        <button type="submit" class="fl-btn fl-btn-go"><i class="fa-solid fa-check" aria-hidden="true"></i> Save <?= e($d['name']) ?></button>
      </div>
    </form>

    <!--
      Its own form, after the edit form closes. Nested forms are invalid HTML and
      the browser silently discards the inner one, which would leave this button
      submitting the record edit instead of the password reset.
    -->
    <div class="det-block">
      <span class="det-h">Reset their sign-in</span>
      <?php if (trim((string)($d['password_hash'] ?? '')) === ''): ?>
        <p class="det-empty">This chauffeur has <b>no password</b>, so they cannot sign in at all. Set one below.</p>
      <?php else: ?>
        <p class="det-empty">They have a password and can sign in. Use this only if they have lost it.</p>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="op" value="set_password"><input type="hidden" name="id" value="<?= $did ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="fl-row2">
          <div><label class="bk-k" for="np<?= $did ?>">New password</label><input id="np<?= $did ?>" name="password" type="text" class="input" required autocomplete="off" placeholder="Give them a first password"></div>
          <div><label class="bk-k" for="np2<?= $did ?>">Password again</label><input id="np2<?= $did ?>" name="password_confirm" type="text" class="input" required autocomplete="off" placeholder="Type it the same way"></div>
        </div>
        <div class="det-acts" style="border:0;padding:.6rem 0 0">
          <button type="submit" class="fl-btn"><i class="fa-solid fa-key" aria-hidden="true"></i> Set password</button>
        </div>
      </form>
    </div>

</div>

  <div class="md-foot">
    <form method="post" class="det-acts">
      <?= csrf_field() ?><input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= $did ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
<?php foreach ($NEXT[$d['status']] ?? [] as $to => [$label, $cls]): ?>
          <?php /* The target status has to ride on the button itself. Without it
                   nothing was posted for `status`, the handler fell back to
                   'pending', and "Clear to drive" on a pending driver reported
                   "already pending" while changing nothing. */ ?>
          <button type="submit" name="status" value="<?= e((string)$to) ?>" class="fl-btn <?= e($cls) ?>"><?= e($label) ?></button>
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
      <p class="md-sub">They sign in with the email and password you give them, then upload their licence and picture before they can drive.</p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="op" value="save_driver">
    <div class="md-body">
      <p class="det-empty">Drivers join by contract with the office - there is no public sign-up. The email and password you set here are how they sign in for the first time; they can change both afterwards.</p>
      <div class="fl-row2">
        <div><label class="bk-k" for="an">Name</label><input id="an" name="name" class="input" required placeholder="Marisol Reyes"></div>
        <div><label class="bk-k" for="ae">Sign-in email</label><input id="ae" name="email" type="email" class="input" required placeholder="name@example.com"></div>
      </div>
      <div class="fl-row2">
        <div><label class="bk-k" for="apw">Sign-in password</label><input id="apw" name="password" type="text" class="input" required autocomplete="off" placeholder="Give them a first password"></div>
        <div><label class="bk-k" for="apw2">Password again</label><input id="apw2" name="password_confirm" type="text" class="input" required autocomplete="off" placeholder="Type it the same way"></div>
      </div>
      <p class="det-empty">Shown in plain text so you can read it out or write it down for them. They replace it with their own once they are in.</p>
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