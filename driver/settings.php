<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
$id = (int)$driver['id'];

$msg = '';
$isErr = false;
$active = (string)($_POST['form'] ?? '');

/*
  Kept while the page renders, so a refused entry comes back with what was typed
  in it rather than blanking a form the driver has just filled in on the kerb.
*/
$typed = static function (string $key, mixed $stored = ''): string {
    $posted = trim((string)($_POST[$key] ?? ''));
    return $posted !== '' ? $posted : trim((string)$stored);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    /*
      Re-photographing a document.

      The old links sent the driver to the setup wizard by its old ?step=2 and
      ?step=3 query parameters. The wizard stopped reading those the day it moved
      to per-session handles, and a driver whose paperwork is already on file
      lands on the completion screen, so both links went nowhere at all. Taking a
      replacement is its own job now, on the page that shows what is already on
      file - the driver can see the old shot and the lens in the same view.
    */
    if ($active === 'replace_licence' || $active === 'replace_photo') {
        $isLicence = $active === 'replace_licence';
        $capture = (string)($_POST[$isLicence ? 'document_capture' : 'photo_capture'] ?? '');
        $label = $isLicence ? 'licence' : 'picture';

        if ($capture === '') {
            $msg = 'Take the photo first.';
            $isErr = true;
        } else {
            [$path, $err] = secure_capture($capture, 'drivers', ['jpg', 'jpeg', 'png', 'webp'],
                $isLicence ? 5242880 : 3145728,
                ['image/jpeg', 'image/png', 'image/webp']);
            if ($err) {
                $msg = ucfirst($label) . ': ' . $err;
                $isErr = true;
            } else {
                // Same rule as the wizard: a re-shoot replaces the pending document
                // rather than stacking a second one the office has to sift through.
                if ($isLicence) {
                    $pdo->prepare("DELETE FROM driver_documents WHERE driver_id = ? AND doc_type IN ('license','licence') AND status = 'pending'")->execute([$id]);
                    $pdo->prepare("INSERT INTO driver_documents (driver_id, doc_type, file_path, expiry_date, status) VALUES (?,?,?,?, 'pending')")
                        ->execute([$id, 'license', $path, DriverPortal::fresh($pdo, $id)['license_expiry'] ?? null]);
                } else {
                    $pdo->prepare("DELETE FROM driver_documents WHERE driver_id = ? AND doc_type = 'photo' AND status = 'pending'")->execute([$id]);
                    $pdo->prepare("INSERT INTO driver_documents (driver_id, doc_type, file_path, status) VALUES (?, 'photo', ?, 'pending')")
                        ->execute([$id, $path]);
                }
                audit($pdo, 'driver', $id, $isLicence ? 'driver.licence_replaced' : 'driver.picture_replaced', 'driver', $id, null);
                // Redirect so a refresh cannot post the same capture twice, and so
                // the card below is rendered from the file that was just stored.
                redirect('driver/settings.php?msg=' . urlencode($isLicence
                    ? 'New licence photo sent. The office will check it.'
                    : 'New picture sent. The office will check it.'));
            }
        }
    }

    if ($active === 'record') {
        $name = $typed('name');
        $phone = $typed('phone');
        $email = trim((string)($_POST['email'] ?? ''));
        $dob = $typed('date_of_birth');
        $licNo = strtoupper($typed('license_number'));
        $licExp = $typed('license_expiry');
        $med = $typed('medical_cert_expiry');
        $bg = $typed('background_check_date');
        $drug = $typed('drug_test_date');

        $day = static function (string $v): ?string {
            if ($v === '' || !strtotime($v)) return null;
            return date('Y-m-d', (int)strtotime($v));
        };

        if ($name === '') {
            $msg = 'Dispatch needs a name to call you by.'; $isErr = true;
        } elseif (!preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) {
            $msg = 'Enter a mobile number dispatch can reach you on.'; $isErr = true;
        } elseif (!validate_email($email)) {
            $msg = 'Enter the email address your rides are sent to.'; $isErr = true;
        } elseif ($dob !== '' && (!$day($dob) || strtotime($dob) >= strtotime('today'))) {
            $msg = 'Enter your date of birth, and check it is not in the future.'; $isErr = true;
        } elseif ($licExp !== '' && strtotime($licExp) < strtotime('today')) {
            $msg = 'That licence expiry has already passed. Check the date on your licence.'; $isErr = true;
        } else {
            $dupe = $pdo->prepare('SELECT id FROM drivers WHERE email = ? AND id <> ? LIMIT 1');
            $dupe->execute([$email, $id]);
            if ($dupe->fetch()) {
                $msg = 'That email already belongs to another driver on the crew.'; $isErr = true;
            } else {
                $before = DriverPortal::fresh($pdo, $id);
                $pdo->prepare('UPDATE drivers SET name = ?, phone = ?, email = ?, date_of_birth = ?,
                                license_number = ?, license_expiry = ?, medical_cert_expiry = ?,
                                background_check_date = ?, drug_test_date = ? WHERE id = ?')
                    ->execute([
                        $name, $phone, $email,
                        $day($dob) ?: ($before['date_of_birth'] ?: null),
                        $licNo ?: null, $day($licExp), $day($med), $day($bg), $day($drug),
                        $id,
                    ]);
                audit($pdo, 'driver', $id, 'driver.record_updated', 'driver', $id, null);
                $msg = 'Your details are saved.';
            }
        }
    }

    // Password stays its own action: it needs the current one checked first, and
    // folding it in with the rest of the record would ask for it on every save.
    if ($active === 'password') {
        $pw = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        $current = (string)($_POST['current_password'] ?? '');
        $fresh = DriverPortal::fresh($pdo, $id);
        if (!password_verify($current, (string)($fresh['password_hash'] ?? ''))) {
            $msg = 'That is not your current password.'; $isErr = true;
        } elseif ($pw === '') {
            $msg = 'Choose a new password.'; $isErr = true;
        } elseif ($pw !== $confirm) {
            $msg = 'The two new passwords do not match.'; $isErr = true;
        } elseif ($err = validate_password($pw)) {
            $msg = $err; $isErr = true;
        } elseif ($pw === $current) {
            $msg = 'That is already your password.'; $isErr = true;
        } else {
            $pdo->prepare('UPDATE drivers SET password_hash = ? WHERE id = ?')->execute([hash_password($pw), $id]);
            audit($pdo, 'driver', $id, 'driver.password_changed', 'driver', $id, null);
            $msg = 'Password changed.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $msg = (string)($_GET['msg'] ?? '');
}
$isErr = $msg !== '' && isset($_GET['err']);

$fresh = DriverPortal::fresh($pdo, $id);
$clear = DriverPortal::clearance($pdo, $fresh);

// Newest of each kind wins, the same rule the office's driver popup reads with,
// so what the driver sees here is genuinely what the desk will see.
$docStmt = $pdo->prepare("SELECT id, doc_type, file_path, status, created_at FROM driver_documents
                          WHERE driver_id = ? AND file_path IS NOT NULL AND file_path <> ''
                          ORDER BY id DESC");
$docStmt->execute([$id]);
$shots = [];
foreach ($docStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $kind = in_array($row['doc_type'], ['photo', 'selfie'], true) ? 'photo' : 'licence';
    if (!isset($shots[$kind])) $shots[$kind] = $row;
}
$licShot = $shots['licence'] ?? null;
$picShot = $shots['photo'] ?? null;

$initial = '';
foreach (preg_split('/\s+/', trim((string)$fresh['name'])) ?: [] as $w) {
    if ($w !== '') $initial .= mb_strtoupper(mb_substr($w, 0, 1));
}

/*
  The camera block, used twice.

  Identical in both dialogs apart from the lens and the field it posts into, so
  the two credentials behave the same way and only one of them has to be right.
*/
$camBlock = static function (string $facing, string $field, string $idle, string $label) {
    ?>
    <div class="cam" data-cam="<?= e($facing) ?>" role="group" aria-label="<?= e($label) ?>">
      <div class="cam-stage">
        <video class="cam-video" data-cam-video playsinline muted autoplay></video>
        <div class="cam-guide<?= $facing === 'user' ? ' is-face' : '' ?>" data-cam-guide aria-hidden="true"></div>
        <img class="cam-shot" data-cam-shot alt="" hidden>
      </div>
      <p class="cam-status" data-cam-status role="status" hidden></p>
      <div class="cam-acts">
        <button type="button" class="cam-shutter" data-cam-shutter disabled>
          <i class="fa-solid fa-camera" aria-hidden="true"></i> Take the photo
        </button>
        <button type="button" class="cam-retake" data-cam-retake hidden>
          <i class="fa-solid fa-rotate" aria-hidden="true"></i> Take it again
        </button>
        <button type="button" class="cam-retake" data-cam-again hidden>
          <i class="fa-solid fa-rotate" aria-hidden="true"></i> Try again
        </button>
      </div>
      <input type="hidden" name="<?= e($field) ?>" data-cam-value value="">
      <p class="drv-hint"><?= e($idle) ?></p>
    </div>
    <?php
};

ob_start();
?>
<div class="acct">

  <?php if ($msg !== ''): ?>
  <div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>" role="status"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php if (!$clear['cleared']): ?>
  <div class="alert alert-warn" role="status">
    You are not cleared to drive yet. <a href="<?= url('driver/setup.php') ?>">Finish setup</a> - <?= e($clear['reason']) ?>
  </div>
  <?php endif; ?>

  <?php /*
    The two credentials are the page, not a section of it.

    Everything else on this screen is filing that sits underneath these two
    objects, so they lead, at the size of the things they stand for. The licence
    is drawn with a perforated strip down its left edge because that is what one
    of these looks like in a wallet, and the countdown is stamped rather than
    typeset because it is the number the desk actually reads off the card.
  */ ?>
  <p class="acct-eyebrow">Your two documents</p>
  <h1 class="acct-h">Credentials</h1>

  <div class="crd-pair">

    <section class="crd crd-lic" aria-labelledby="crd-lic-h">
      <header class="crd-head">
        <span class="crd-k" id="crd-lic-h">Driving licence</span>
        <span class="crd-state <?= $licShot ? (($licShot['status'] === 'rejected') ? 'is-bad' : 'is-wait') : 'is-none' ?>">
          <?= $licShot ? e(ucfirst((string)$licShot['status'])) : 'not photographed' ?>
        </span>
      </header>

      <div class="crd-slot">
        <?php if ($licShot): ?>
          <img class="crd-img" src="<?= e(media_url((string)$licShot['file_path'])) ?>" alt="The licence photo on your file" decoding="async" onerror="this.remove()">
        <?php else: ?>
          <span class="crd-empty"><i class="fa-regular fa-image" aria-hidden="true"></i> Nothing on file</span>
        <?php endif; ?>
      </div>

      <?php /* The two numbers the desk actually reads off this document. A licence
               photographed at preview size is not a place to confirm a serial, so
               they are printed here instead of left to the desk's own zoom. */ ?>
      <div class="crd-facts">
        <p class="crd-fact">
          <span class="crd-fact-k">Licence number</span>
          <span class="crd-fact-v<?= empty($fresh['license_number']) ? ' is-none' : '' ?>"><?= e((string)($fresh['license_number'] ?: 'Not on file')) ?></span>
        </p>
        <p class="crd-fact">
          <span class="crd-fact-k">Expires</span>
          <span class="crd-fact-v<?= empty($fresh['license_expiry']) ? ' is-none' : '' ?>"><?= $fresh['license_expiry'] ? e(date('j M Y', strtotime((string)$fresh['license_expiry']))) : 'Not on file' ?></span>
        </p>
      </div>
    </section>

    <section class="crd crd-pic" aria-labelledby="crd-pic-h">
      <header class="crd-head">
        <span class="crd-k" id="crd-pic-h">Your picture</span>
        <span class="crd-state <?= $picShot ? (($picShot['status'] === 'rejected') ? 'is-bad' : 'is-wait') : 'is-none' ?>">
          <?= $picShot ? e(ucfirst((string)$picShot['status'])) : 'not taken' ?>
        </span>
      </header>

      <div class="crd-slot is-face">
        <span class="crd-initials" aria-hidden="true"><?= e($initial !== '' ? mb_substr($initial, 0, 2) : '—') ?></span>
        <?php if ($picShot): ?>
          <img class="crd-img" src="<?= e(media_url((string)$picShot['file_path'])) ?>" alt="The picture on your file" decoding="async" onerror="this.remove()">
        <?php endif; ?>
      </div>

      <?php /* This picture goes on the guest manifest, so the one thing worth
               confirming on screen is that it is the right person under the right
               name. */ ?>
      <div class="crd-facts">
        <p class="crd-fact">
          <span class="crd-fact-k">Name on file</span>
          <span class="crd-fact-v<?= empty($fresh['name']) ? ' is-none' : '' ?>"><?= e((string)($fresh['name'] ?: 'Not on file')) ?></span>
        </p>
        <p class="crd-fact">
          <span class="crd-fact-k">Crew reference</span>
          <span class="crd-fact-v<?= empty($fresh['reference']) ? ' is-none' : '' ?>"><?= e((string)($fresh['reference'] ?: 'Not assigned')) ?></span>
        </p>
      </div>
    </section>

  </div>

  <?php /*
    One form for the whole record.

    It used to be four separate cards with four save buttons, three of them for
    one field each, which meant four page loads to fix one typo and no way to see
    the record whole. What the office holds on file is a set of facts about one
    person, so it is one set of fields here, and one Save.
  */ ?>
  <p class="acct-eyebrow">Your record</p>
  <form method="post" class="acct-form" data-once>
    <?= csrf_field() ?><input type="hidden" name="form" value="record">

    <div class="acct-grid">
      <div class="drv-field">
        <label class="drv-label" for="s-name">Full name</label>
        <input id="s-name" name="name" class="drv-input" required autocomplete="name"
               value="<?= e($typed('name', $fresh['name'] ?? '')) ?>"
               placeholder="The name dispatch should call you by">
      </div>
      <div class="drv-field">
        <label class="drv-label" for="s-phone">Mobile number</label>
        <input id="s-phone" type="tel" name="phone" class="drv-input" required inputmode="tel" autocomplete="tel"
               value="<?= e($typed('phone', $fresh['phone'] ?? '')) ?>"
               placeholder="+1 555 010 2030">
        <p class="drv-hint">For urgent calls only.</p>
      </div>
      <div class="drv-field">
        <label class="drv-label" for="s-email">Sign-in email</label>
        <input id="s-email" type="email" name="email" class="drv-input" required inputmode="email" autocomplete="username"
               value="<?= e($typed('email', $fresh['email'] ?? '')) ?>"
               placeholder="you@example.com">
        <p class="drv-hint">Every ride assigned to you is sent here.</p>
      </div>
      <div class="drv-field">
        <label class="drv-label" for="s-dob">Date of birth</label>
        <input id="s-dob" type="date" name="date_of_birth" class="drv-input" max="<?= e(date('Y-m-d')) ?>"
               value="<?= e($typed('date_of_birth', $fresh['date_of_birth'] ?? '')) ?>"
               placeholder="Your date of birth">
      </div>
    </div>

    <fieldset class="acct-set">
      <legend class="acct-set-h">Clearance dates</legend>
      <p class="acct-set-note">What the office holds. Nothing to photograph here - just the dates.</p>
      <div class="acct-grid">
        <div class="drv-field">
          <label class="drv-label" for="s-licno">Licence number</label>
          <input id="s-licno" name="license_number" class="drv-input" autocapitalize="characters"
                 value="<?= e($typed('license_number', $fresh['license_number'] ?? '')) ?>"
                 placeholder="As printed on the licence">
        </div>
        <div class="drv-field">
          <label class="drv-label" for="s-licesp">Licence expires on</label>
          <input id="s-licesp" type="date" name="license_expiry" class="drv-input"
                 value="<?= e($typed('license_expiry', $fresh['license_expiry'] ?? '')) ?>"
                 placeholder="Expiry printed on the licence">
        </div>
        <div class="drv-field">
          <label class="drv-label" for="s-med">Medical certificate expires on</label>
          <input id="s-med" type="date" name="medical_cert_expiry" class="drv-input"
                 value="<?= e($typed('medical_cert_expiry', $fresh['medical_cert_expiry'] ?? '')) ?>"
                 placeholder="Expiry on your medical">
        </div>
        <div class="drv-field">
          <label class="drv-label" for="s-bg">Background check date</label>
          <input id="s-bg" type="date" name="background_check_date" class="drv-input" max="<?= e(date('Y-m-d')) ?>"
                 value="<?= e($typed('background_check_date', $fresh['background_check_date'] ?? '')) ?>"
                 placeholder="When your check was run">
        </div>
        <div class="drv-field">
          <label class="drv-label" for="s-drug">Drug test date</label>
          <input id="s-drug" type="date" name="drug_test_date" class="drv-input" max="<?= e(date('Y-m-d')) ?>"
                 value="<?= e($typed('drug_test_date', $fresh['drug_test_date'] ?? '')) ?>"
                 placeholder="When your test was taken">
        </div>
      </div>
    </fieldset>

    <button class="drv-btn drv-btn-gold"><i class="fa-solid fa-check" aria-hidden="true"></i> Save my details</button>
  </form>

  <p class="acct-eyebrow">Sign-in</p>
  <form method="post" class="acct-form" data-once>
    <?= csrf_field() ?><input type="hidden" name="form" value="password">
    <div class="acct-grid">
      <div class="drv-field">
        <label class="drv-label" for="s-cur">Current password</label>
        <input id="s-cur" type="password" name="current_password" class="drv-input" required autocomplete="current-password"
               placeholder="The password you sign in with now">
      </div>
      <div class="drv-field">
        <label class="drv-label" for="s-pw">New password</label>
        <input id="s-pw" type="password" name="password" class="drv-input" required minlength="8" autocomplete="new-password"
               placeholder="At least 8 characters">
      </div>
      <div class="drv-field">
        <label class="drv-label" for="s-pw2">New password again</label>
        <input id="s-pw2" type="password" name="password_confirm" class="drv-input" required autocomplete="new-password"
               placeholder="Type it the same way">
      </div>
    </div>
    <button class="drv-btn drv-btn-line">Change password</button>
  </form>

  <?php /* Not a third action on the form, so not a .drv-btn. It inherits width:100%
           and a 56px height from that family, which turns a link that only
           navigates into a full-width underlined slab. */ ?>
  <p class="acct-back">
    <a class="drv-back" href="<?= url('driver/dashboard.php') ?>">
      <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to my rides
    </a>
  </p>

  <?php /*
    Both cameras live at the end of the page rather than on the card they belong
    to. The previews above are the answer to "is what I sent still there?" and
    nothing else - no numbers, no dates, nothing to keep correct - and a button
    sitting under them invited the reader to treat the whole card as a form.
    These are the only two actions on the page that open something, so they get
    to be last and get the room.
  */ ?>
  <section class="acct-swap">
    <p class="acct-eyebrow">Something out of date?</p>
    <h2 class="acct-h2">Replace a document</h2>
    <div class="acct-swap-row">
      <button type="button" class="crd-shoot" data-open-cam="camdlg-lic">
        <span class="acct-shoot-i"><i class="fa-solid fa-id-card" aria-hidden="true"></i> Replace my licence</span>
        <small>Open the rear camera and photograph it again</small>
      </button>
      <button type="button" class="crd-shoot" data-open-cam="camdlg-pic">
        <span class="acct-shoot-i"><i class="fa-solid fa-camera" aria-hidden="true"></i> Replace my picture</span>
        <small>Open the front camera and take it again</small>
      </button>
    </div>
  </section>
</div>

<?php /* The lens. One dialog per credential; camera.js waits for the open event
        before asking for the camera, so loading this page does not. */ ?>
<dialog class="cam-dlg" id="camdlg-lic" aria-labelledby="camdlg-lic-t">
  <form method="post" class="cam-dlg-f">
    <?= csrf_field() ?><input type="hidden" name="form" value="replace_licence">
    <div class="md-head">
      <div>
        <h2 class="md-title" id="camdlg-lic-t">Photograph your licence</h2>
        <p class="md-sub">Lay it flat in good light. The number and expiry have to be readable.</p>
      </div>
      <button type="button" class="md-x" data-cam-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="md-body">
      <?= $camBlock('environment', 'document_capture',
          'This replaces the photo the office is holding. They will check the new one.', 'Photograph your driving licence') ?>
    </div>
    <div class="md-foot">
      <button type="button" class="drv-btn drv-btn-line" data-cam-close>Cancel</button>
      <button class="drv-btn drv-btn-gold" data-requires-capture disabled>Send this licence</button>
    </div>
  </form>
</dialog>

<dialog class="cam-dlg" id="camdlg-pic" aria-labelledby="camdlg-pic-t">
  <form method="post" class="cam-dlg-f">
    <?= csrf_field() ?><input type="hidden" name="form" value="replace_photo">
    <div class="md-head">
      <div>
        <h2 class="md-title" id="camdlg-pic-t">Take your picture</h2>
        <p class="md-sub">This goes on the guest manifest, so it has to be you.</p>
      </div>
      <button type="button" class="md-x" data-cam-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="md-body">
      <?= $camBlock('user', 'photo_capture',
          'Face filling most of the frame, plain background.', 'Take your picture') ?>
    </div>
    <div class="md-foot">
      <button type="button" class="drv-btn drv-btn-line" data-cam-close>Cancel</button>
      <button class="drv-btn drv-btn-gold" data-requires-capture disabled>Send this picture</button>
    </div>
  </form>
</dialog>
<?php
$content = ob_get_clean();
$pageTitle = 'Your account | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';