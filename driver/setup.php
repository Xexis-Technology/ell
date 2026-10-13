<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');

/**
 * What a driver has to put on file before they can take a ride.
 *
 * Not the password. The office sets the first password when the driver is added
 * to the crew, so by the time a driver reaches this page they have already
 * signed in with email + password; they change that password to their own from
 * Account whenever they want.
 *
 * These three are numbered because the order is real: dispatch needs a name and
 * a number before there is a licence to check, and the licence is what clears
 * them, with the picture last because it only matters once the licence is good.
 */

$msg = '';
$isErr = false;

// Computed before the POST so the form can re-render on the right step when
// validation fails and nothing moves.
$steps = DriverPortal::steps($pdo, $driver);
$current = null;
foreach ($steps as $n => $s) if ($s['current']) { $current = $n; break; }
// The URL carries an opaque per-session handle, not ?step=2. An ordinal invites
// ?step=3 straight past the first two and advertises the flow; the handle can be
// neither guessed nor reused by another driver from a copied link.
$tokenStep = DriverPortal::step_from_token((string)($_GET['s'] ?? $_POST['s'] ?? ''));
$target = $tokenStep ?? ($current ?? 3);
$id = (int)$driver['id'];
// No redirect for a submitted driver. This page IS the clearance screen, so
// bouncing to itself was a loop; $allDone below already makes a ?s= handle
// unable to reopen a closed wizard.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $target = DriverPortal::step_from_token((string)($_POST['s'] ?? '')) ?? $target;

    if ($target === 1) {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $dob = trim((string)($_POST['date_of_birth'] ?? ''));
        if ($name === '') { $msg = 'Enter the name dispatch should call you by.'; $isErr = true; }
        elseif (!preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) {
            $msg = 'Enter a mobile number dispatch can reach you on.'; $isErr = true;
        } elseif (!validate_email($email)) {
            // Rides are assigned by email, so this is the address the work comes
            // to - not a contact detail. It has to be right and it has to be theirs.
            $msg = 'Enter the email address your rides are sent to.'; $isErr = true;
        } elseif ($dob === '' || !strtotime($dob)) {
            $msg = 'Enter your date of birth, as j M YYYY.'; $isErr = true;
        } elseif (strtotime($dob) >= strtotime('today')) {
            $msg = 'That date of birth is in the future.'; $isErr = true;
        } else {
            // Two chauffeurs sharing an address would make the sign-in lookup
            // ambiguous, so the address has to belong to this driver alone.
            $dupe = $pdo->prepare('SELECT id FROM drivers WHERE email = ? AND id <> ? LIMIT 1');
            $dupe->execute([$email, $id]);
            if ($dupe->fetch()) {
                $msg = 'That email already belongs to another driver on the crew.';
                $isErr = true;
            } else {
                $pdo->prepare('UPDATE drivers SET name = ?, phone = ?, email = ?, date_of_birth = ? WHERE id = ?')
                    ->execute([$name, $phone, $email, date('Y-m-d', (int)strtotime($dob)), $id]);
                audit($pdo, 'driver', $id, 'driver.profile_set', 'driver', $id, null);
                redirect('driver/setup.php?s=' . DriverPortal::step_token(2) . '&msg=' . urlencode('Profile saved. Now your licence.'));
            }
        }
    }

    if ($target === 2) {
        $licNo = strtoupper(trim((string)($_POST['license_number'] ?? '')));
        $exp = trim((string)($_POST['license_expiry'] ?? ''));
        $med = trim((string)($_POST['medical_cert_expiry'] ?? ''));
        $bg = trim((string)($_POST['background_check_date'] ?? ''));
        $drug = trim((string)($_POST['drug_test_date'] ?? ''));
        $capture = (string)($_POST['document_capture'] ?? '');

        if ($licNo === '') { $msg = 'Enter the number printed on your licence.'; $isErr = true; }
        elseif ($exp === '' || !strtotime($exp)) { $msg = 'Enter the expiry date on your licence.'; $isErr = true; }
        elseif (strtotime($exp) < strtotime('today')) { $msg = 'That licence expiry has already passed. Check the date on your licence.'; $isErr = true; }
        elseif ($med === '' || !strtotime($med)) { $msg = 'Enter the expiry date on your medical certificate.'; $isErr = true; }
        elseif (strtotime($med) < strtotime('today')) { $msg = 'That medical certificate has already expired.'; $isErr = true; }
        elseif ($bg === '' || !strtotime($bg)) { $msg = 'Enter the date of your background check.'; $isErr = true; }
        elseif ($drug === '' || !strtotime($drug)) { $msg = 'Enter the date of your drug test.'; $isErr = true; }
        elseif (strtotime($bg) > time() + 86400 || strtotime($drug) > time() + 86400) {
            $msg = 'The background check and drug test dates cannot be in the future.'; $isErr = true;
        }
        elseif ($capture === '') { $msg = 'Take the photo of your licence with the camera.'; $isErr = true; }
        else {
            [$path, $err] = secure_capture($capture, 'drivers', ['jpg', 'jpeg', 'png', 'webp'], 5242880,
                ['image/jpeg', 'image/png', 'image/webp']);
            if ($err) { $msg = 'Licence: ' . $err; $isErr = true; }
            else {
                $pdo->prepare('UPDATE drivers SET license_number = ?, license_expiry = ?, medical_cert_expiry = ?,
                                background_check_date = ?, drug_test_date = ? WHERE id = ?')
                    ->execute([$licNo, date('Y-m-d', (int)strtotime($exp)), date('Y-m-d', (int)strtotime($med)),
                               date('Y-m-d', (int)strtotime($bg)), date('Y-m-d', (int)strtotime($drug)), $id]);
                // Re-photographing replaces the previous pending licence rather
                // than stacking duplicates the office has to sift through.
                $pdo->prepare("DELETE FROM driver_documents WHERE driver_id = ? AND doc_type IN ('license','licence') AND status = 'pending'")->execute([$id]);
                $pdo->prepare("INSERT INTO driver_documents (driver_id, doc_type, file_path, expiry_date, status) VALUES (?,?,?,?, 'pending')")
                    ->execute([$id, 'license', $path, date('Y-m-d', (int)strtotime($exp))]);
                audit($pdo, 'driver', $id, 'driver.license_captured', 'driver', $id, ['license_number' => $licNo]);
                redirect('driver/setup.php?s=' . DriverPortal::step_token(3) . '&msg=' . urlencode('Licence photographed. Last step: your picture.'));
            }
        }
    }

    if ($target === 3) {
        $capture = (string)($_POST['photo_capture'] ?? '');
        if ($capture === '') {
            $msg = 'Take your picture with the camera.'; $isErr = true;
        } else {
            [$path, $err] = secure_capture($capture, 'drivers', ['jpg', 'jpeg', 'png', 'webp'], 3145728,
                ['image/jpeg', 'image/png', 'image/webp']);
            if ($err) { $msg = 'Photo: ' . $err; $isErr = true; }
            else {
                $pdo->prepare("DELETE FROM driver_documents WHERE driver_id = ? AND doc_type = 'photo' AND status = 'pending'")->execute([$id]);
                $pdo->prepare("INSERT INTO driver_documents (driver_id, doc_type, file_path, status) VALUES (?, 'photo', ?, 'pending')")
                    ->execute([$id, $path]);
                // Last step done: the wizard closes for good and the driver waits
                // on the office. Recorded as an event, not re-derived from the fields.
DriverPortal::mark_submitted($pdo, $id);
                audit($pdo, 'driver', $id, 'driver.photo_captured', 'driver', $id, null);
                // No step handle in the URL any more: the wizard is closed, and a
                // handle that could reopen it is exactly what we just removed.
                $clearedNow = DriverPortal::clearance($pdo, DriverPortal::fresh($pdo, $id))['cleared'];
                redirect('driver/setup.php?msg=' . urlencode($clearedNow
                    ? 'All done. You are cleared to drive.'
                    : 'Everything is on file. We will let you know when the office clears you.'));
            }
        }
    }
}

// On a POST the step handler has already set $msg - usually to why the entry was
// refused. Re-reading ?msg here overwrote that fresh error with whatever message
// the page happened to be opened with, so a rejected licence photo reported
// "Profile saved" under an error banner.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $msg = (string)($_GET['msg'] ?? '');
}
$steps = DriverPortal::steps($pdo, $driver);
$clear = DriverPortal::clearance($pdo, $driver);
$fresh = DriverPortal::fresh($pdo, $id);
$doneCount = count(array_filter($steps, static fn($s) => $s['done']));
// Submitted means submitted. Once the driver has sent their paperwork the wizard
// is shut - including any ?s= handle in the URL - and they wait on the clearance
// screen. Re-deriving "finished" from the fields let one unreadable value drop
// them back into a wizard they had already completed.
$submitted = DriverPortal::submitted($pdo, $driver);
$allDone = $submitted || $doneCount === 3;
$pct = (int)round($doneCount / 3 * 100);

// The driver's own picture, so the licence card can show a portrait once there
// is one. Falls back to nothing rather than a broken image.
$photoStmt = $pdo->prepare("SELECT file_path FROM driver_documents
                            WHERE driver_id = ? AND doc_type = 'photo' AND file_path IS NOT NULL
                            ORDER BY id DESC LIMIT 1");
$photoStmt->execute([$id]);
$photoUrl = media_url((string)$photoStmt->fetchColumn());

/*
  What the form shows back to the driver.
 *
  A refused entry re-renders this same page, and the database still holds nothing
  for a driver mid-setup - so falling back to the stored row alone wiped everything
  they had just typed. One wrong date made them retype the licence number, the
  expiry and all three clearance dates, and walk the licence out to photograph it
  again. What was typed wins over what is stored, because that is what they are
  correcting.
*/
$typed = static function (string $key, mixed $stored = ''): string {
    $p = trim((string)($_POST[$key] ?? ''));
    return $p !== '' ? $p : trim((string)$stored);
};

$driverName = $typed('name', $fresh['name'] ?? '');
$driverPhone = $typed('phone', $fresh['phone'] ?? '');
$driverEmail = $typed('email', $fresh['email'] ?? '');
$driverDob = $typed('date_of_birth', $fresh['date_of_birth'] ?? '');
$licNo = strtoupper($typed('license_number', $fresh['license_number'] ?? ''));
$licExp = $typed('license_expiry', $fresh['license_expiry'] ?? '');
$licExpText = $licExp !== '' && strtotime($licExp) ? date('j M Y', (int)strtotime($licExp)) : '';
// Clearance dates, formatted for the card. A stored date that is never shown is
// a field nobody maintains, so the card carries them next to the licence.
$fmtDay = static function (string $v): string {
    return $v !== '' && strtotime($v) ? date('j M Y', (int)strtotime($v)) : '';
};
// A refused entry re-renders this same form. Without handing the capture back,
// one wrong date would make the driver photograph the licence all over again.
$captureKept = (string)($_POST['document_capture'] ?? '');
$photoKept = (string)($_POST['photo_capture'] ?? '');

$clearanceDates = [
    'Medical' => $fmtDay($typed('medical_cert_expiry', $fresh['medical_cert_expiry'] ?? '')),
    'Background check' => $fmtDay($typed('background_check_date', $fresh['background_check_date'] ?? '')),
    'Drug test' => $fmtDay($typed('drug_test_date', $fresh['drug_test_date'] ?? '')),
];

/*
  The clearance card: a driving licence in miniature that fills in as the driver
  works. Portrait, name, number, expiry. Step three's photo lands in the portrait
  slot, which is the whole reason the card is shaped like a licence rather than a
  progress list - it is the artefact they will be handed at the kerb.
  Defined here rather than in DriverPortal so the logic class stays markup-free.
*/
$card = static function (string $name, string $licNo, string $licExp, string $photo, bool $filled, array $extra = []): string {
    $initial = '';
    foreach (preg_split('/\s+/', $name) ?: [] as $w) if ($w !== '') $initial .= mb_strtoupper(mb_substr($w, 0, 1));
    $short = static function (string $v, string $empty): string {
        return trim($v) !== '' ? e($v) : '<span class="lc-blank">' . e($empty) . '</span>';
    };
    ob_start();
    ?>
<div class="lc<?= $filled ? ' is-filled' : '' ?>">
      <div class="lc-top">
        <span class="lc-portrait">
          <?php /* Initials always render underneath. If the stored file has gone
                   missing the image is removed on error and the initials show
                   through, rather than leaving an empty frame or a broken icon. */ ?>
          <span class="lc-initials" aria-hidden="true"><?= e($initial !== '' ? mb_substr($initial, 0, 2) : '—') ?></span>
          <?php if ($photo !== ''): ?>
            <img src="<?= e($photo) ?>" alt="Your picture" decoding="async" onerror="this.remove()">
          <?php endif; ?>
        </span>
        <span class="lc-ident">
          <span class="lc-name"><?= $short($name, 'Your name') ?></span>
          <span class="lc-org">Exotic Lane Limo</span>
        </span>
      </div>
      <dl class="lc-rows">
        <div><dt>Licence</dt><dd class="lc-mono"><?= $short($licNo, 'Not added yet') ?></dd></div>
        <div><dt>Expires</dt><dd class="lc-mono"><?= $short($licExp, 'Not added yet') ?></dd></div>
        <?php foreach ($extra as $label => $value): ?>
        <div><dt><?= e($label) ?></dt><dd class="lc-mono"><?= $short($value, 'Not added yet') ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </div>
    <?php
    return (string)ob_get_clean();
};

ob_start();
?>
<div class="wiz">

<?php /* Shown on both screens. It used to sit only in the step branch, so the
         message confirming the last submission never appeared - the driver
         landed on the completion screen with no word about what happened. */ ?>
<?php if ($msg !== ''): ?>
  <div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>" role="status"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($allDone): ?>
  <?php /*
    Three steps finished is not the same as being allowed to drive. A driver the
    office added as pending is not cleared until someone at the desk activates
    them, and the dashboard sends anyone not cleared straight back here - so a
    "Go to my rides" button on this screen would bounce the driver straight back
    to this screen. The card below still shows, because their paperwork is done
    and they should be able to see it; what changes is the promise at the top.
  */ ?>
  <div class="wiz-bar is-full<?= $clear['cleared'] ? '' : ' is-held' ?>">
    <div class="wiz-track"><i style="width:100%"></i></div>
    <span class="wiz-count"><?= $clear['cleared'] ? 'All three done' : 'All three on file' ?></span>
  </div>

  <div class="wiz-done">
    <?php if ($clear['cleared']): ?>
      <p class="drv-k">Cleared to drive</p>
      <h1>That is everything.</h1>
      <p class="wiz-done-sub">Dispatch can give you rides now. If anything on this card is wrong, change it from Account.</p>

      <?= $card($driverName, $licNo, $licExpText, $photoUrl, true, $clearanceDates) ?>

      <a class="drv-btn drv-btn-gold" href="<?= url('driver/dashboard.php') ?>">Go to my rides</a>
      <a class="drv-btn drv-btn-quiet" href="<?= url('driver/settings.php') ?>">Check my details</a>

    <?php else: ?>
      <p class="drv-k">Everything on file</p>
      <h1>Your paperwork is done.</h1>
      <p class="wiz-done-sub">Everything on your card is complete. Rides open up once the office has cleared you — there is nothing else to do here.</p>

      <?= $card($driverName, $licNo, $licExpText, $photoUrl, true, $clearanceDates) ?>

      <?php /* No status pill here: the clearance band across the top of the page
               already says the specific reason, and saying it a third time reads
               as nagging. */ ?>
      <a class="drv-btn drv-btn-quiet" href="<?= url('driver/settings.php') ?>">Check my details</a>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="wiz-bar">
    <div class="wiz-track"><i style="width:<?= $pct ?>%"></i></div>
<span class="wiz-count"><?= $target ?> of 3</span>
  </div>

  <div class="wiz-grid">
    <div class="wiz-main">
      <h1><?= e($steps[$target]['title']) ?></h1>
      <p class="wiz-lead"><?= e($steps[$target]['blurb']) ?></p>
      <!-- No "what's missing" line here: the clearance band across the top of the
           page already says it, and saying it twice reads as a stutter. -->

      <?php if ($target === 1): ?>
      <form method="post" class="wiz-form">
        <?= csrf_field() ?><input type="hidden" name="s" value="<?= e(DriverPortal::step_token(1)) ?>">
        <div class="drv-field">
          <label class="drv-label" for="name">Full name</label>
          <input id="name" name="name" class="drv-input" required value="<?= e($driverName) ?>"
                 placeholder="The name dispatch should call you by">
        </div>
        <div class="drv-field">
          <label class="drv-label" for="phone">Mobile number</label>
          <input id="phone" type="tel" name="phone" class="drv-input" required inputmode="tel"
                 value="<?= e($driverPhone) ?>" placeholder="+1 555 010 2030">
          <p class="drv-hint">For urgent calls only — a ride that has changed, or a guest waiting at the kerb.</p>
        </div>
        <div class="drv-field">
          <label class="drv-label" for="email">Email</label>
          <input id="email" type="email" name="email" class="drv-input" required inputmode="email"
                 autocomplete="email" value="<?= e($driverEmail) ?>" placeholder="you@example.com">
          <p class="drv-hint">Every ride assigned to you is sent here, so it has to be an address you check.</p>
        </div>
        <div class="drv-field">
          <label class="drv-label" for="date_of_birth">Date of birth</label>
          <input id="date_of_birth" type="date" name="date_of_birth" class="drv-input" required
                 value="<?= e($driverDob) ?>" max="<?= e(date('Y-m-d')) ?>">
          <p class="drv-hint">The office checks this against the photo on your licence.</p>
        </div>
        <button class="drv-btn drv-btn-gold">Save profile</button>
      </form>

      <?php elseif ($target === 2): ?>
      <form method="post" class="wiz-form">
        <?= csrf_field() ?><input type="hidden" name="s" value="<?= e(DriverPortal::step_token(2)) ?>">
        <div class="drv-field">
          <label class="drv-label" for="license_number">Licence number</label>
          <input id="license_number" name="license_number" class="drv-input" required
                 value="<?= e($licNo) ?>" placeholder="As printed on the licence" autocapitalize="characters">
        </div>
        <div class="drv-field">
          <label class="drv-label" for="license_expiry">Licence expires on</label>
          <input id="license_expiry" type="date" name="license_expiry" class="drv-input" required
                 value="<?= e($licExp) ?>" min="<?= e(date('Y-m-d')) ?>">
        </div>
        <div class="drv-field">
          <span class="drv-label" id="cam-lic-label">Photograph your licence</span>
          <?php /* Camera, not a file picker. Picking a file would let a driver
                   hand over a photo of somebody else's licence and nothing on the
                   server could tell the difference. Only the licence is captured -
                   the three dates below are recorded, not photographed. */ ?>
          <div class="cam<?= $captureKept !== '' ? ' is-taken' : '' ?>" data-cam="environment" role="group" aria-labelledby="cam-lic-label">
            <div class="cam-stage">
              <video class="cam-video" data-cam-video playsinline muted autoplay></video>
              <div class="cam-guide" data-cam-guide aria-hidden="true"></div>
              <?php if ($captureKept !== ''): ?>
                <img class="cam-shot" data-cam-shot alt="The licence photo you took" src="<?= e($captureKept) ?>">
              <?php else: ?>
                <img class="cam-shot" data-cam-shot alt="" hidden>
              <?php endif; ?>
            </div>
            <p class="cam-status" data-cam-status role="status" hidden></p>
            <div class="cam-acts">
              <button type="button" class="cam-shutter" data-cam-shutter<?= $captureKept !== '' ? ' hidden' : ' disabled' ?>>
                <i class="fa-solid fa-camera" aria-hidden="true"></i> Take the photo
              </button>
              <button type="button" class="cam-retake" data-cam-retake<?= $captureKept !== '' ? '' : ' hidden' ?>>
                <i class="fa-solid fa-rotate" aria-hidden="true"></i> Take it again
              </button>
              <button type="button" class="cam-retake" data-cam-again hidden>
                <i class="fa-solid fa-rotate" aria-hidden="true"></i> Try again
              </button>
            </div>
            <input type="hidden" name="document_capture" data-cam-value value="<?= e($captureKept) ?>">
            <p class="drv-hint">The number and expiry on the licence have to be readable in the photo.</p>
          </div>
        </div>

        <fieldset class="wiz-group">
          <legend class="wiz-group-h">Clearance record</legend>
          <p class="wiz-group-note">Dates the office keeps on file. Nothing to photograph — just the dates.</p>
          <div class="drv-field">
            <label class="drv-label" for="medical_cert_expiry">Medical certificate expires on</label>
            <input id="medical_cert_expiry" type="date" name="medical_cert_expiry" class="drv-input" required
                   value="<?= e($typed('medical_cert_expiry', $fresh['medical_cert_expiry'] ?? '')) ?>" min="<?= e(date('Y-m-d')) ?>">
          </div>
          <div class="drv-field">
            <label class="drv-label" for="background_check_date">Background check date</label>
            <input id="background_check_date" type="date" name="background_check_date" class="drv-input" required
                   value="<?= e($typed('background_check_date', $fresh['background_check_date'] ?? '')) ?>" max="<?= e(date('Y-m-d')) ?>">
          </div>
          <div class="drv-field">
            <label class="drv-label" for="drug_test_date">Drug test date</label>
            <input id="drug_test_date" type="date" name="drug_test_date" class="drv-input" required
                   value="<?= e($typed('drug_test_date', $fresh['drug_test_date'] ?? '')) ?>" max="<?= e(date('Y-m-d')) ?>">
          </div>
        </fieldset>

        <button class="drv-btn drv-btn-gold" data-requires-capture disabled>Send licence details</button>
      </form>

      <?php else: ?>
      <form method="post" class="wiz-form">
        <?= csrf_field() ?><input type="hidden" name="s" value="<?= e(DriverPortal::step_token(3)) ?>">
        <div class="drv-field">
          <span class="drv-label" id="cam-face-label">Take your picture</span>
          <div class="cam<?= $photoKept !== '' ? ' is-taken' : '' ?>" data-cam="user" role="group" aria-labelledby="cam-face-label">
            <div class="cam-stage">
              <video class="cam-video" data-cam-video playsinline muted autoplay></video>
              <div class="cam-guide is-face" data-cam-guide aria-hidden="true"></div>
              <?php if ($photoKept !== ''): ?>
                <img class="cam-shot" data-cam-shot alt="The picture you took" src="<?= e($photoKept) ?>">
              <?php else: ?>
                <img class="cam-shot" data-cam-shot alt="" hidden>
              <?php endif; ?>
            </div>
            <p class="cam-status" data-cam-status role="status" hidden></p>
            <div class="cam-acts">
              <button type="button" class="cam-shutter" data-cam-shutter<?= $photoKept !== '' ? ' hidden' : ' disabled' ?>>
                <i class="fa-solid fa-camera" aria-hidden="true"></i> Take the photo
              </button>
              <button type="button" class="cam-retake" data-cam-retake<?= $photoKept !== '' ? '' : ' hidden' ?>>
                <i class="fa-solid fa-rotate" aria-hidden="true"></i> Take it again
              </button>
              <button type="button" class="cam-retake" data-cam-again hidden>
                <i class="fa-solid fa-rotate" aria-hidden="true"></i> Try again
              </button>
            </div>
            <input type="hidden" name="photo_capture" data-cam-value value="<?= e($photoKept) ?>">
            <p class="drv-hint">This goes on the guest manifest, so it has to be you. Face filling most of the frame, plain background.</p>
          </div>
        </div>
        <button class="drv-btn drv-btn-gold" data-requires-capture disabled>Send my picture</button>
      </form>
      <?php endif; ?>

      <nav class="wiz-nav" aria-label="Setup steps">
        <?php foreach ($steps as $n => $s): ?>
          <a href="<?= url('driver/setup.php?s=' . DriverPortal::step_token($n)) ?>"
             class="wiz-nav-item<?= $n === $target ? ' is-now' : '' ?><?= $s['done'] ? ' is-done' : '' ?>"
             <?= $n === $target ? 'aria-current="step"' : '' ?>>
            <span class="wiz-nav-n"><?= $n ?></span>
            <span class="wiz-nav-t"><?= e($s['title']) ?></span>
            <?php if ($s['done']): ?><i class="fa-solid fa-check" aria-hidden="true"></i><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <aside class="wiz-side">
      <?= $card($driverName, $licNo, $licExpText, $photoUrl, false, $clearanceDates) ?>
    </aside>
  </div>

<?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Set up your account | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';