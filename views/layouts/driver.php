<?php /** @var string $content */ ?>
<?php
$driverUser = current_user('driver');
$pdoRef = $GLOBALS['pdo'] ?? null;
$clearance = ($driverUser && $pdoRef) ? DriverPortal::clearance($pdoRef, $driverUser) : null;
$driverName = (string)($driverUser['name'] ?? '');
$here = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$nav = [
    'dashboard.php' => ['Home', 'fa-gauge-high'],
    'rides.php' => ['Rides', 'fa-road'],
    'earnings.php' => ['Earnings', 'fa-wallet'],
    'payout.php' => ['Payout', 'fa-money-bill'],
    // Third element marks it out of the thumb dock. The dock is for the places
    // a driver goes during a shift; notices is somewhere they are sent, and
    // six items with a badge squeezed in also stopped fitting a phone width.
    'notification.php' => ['Notices', 'fa-bell', 'no-dock'],
    'settings.php' => ['Account', 'fa-user-gear'],
];
// How much of the setup is done, so the sidebar can keep saying so after the
// clearance band is dismissed. Dismissing a banner must not quietly drop the
// one fact the driver needs to know.
$doneCount = 0;
if ($driverUser && $pdoRef) {
    $doneCount = count(array_filter(DriverPortal::steps($pdoRef, $driverUser), static fn($s) => $s['done']));
}
// Signed-out pages break out of the narrow reading column so the sign-in screen
// can use the full width for its two-panel layout.
$authPage = in_array($here, ['index.php', 'forgot-password.php', 'reset-password.php'], true);
$onSetup = $here === 'setup.php';
// Pages that are grids of figures rather than a column of reading. Held to the
// 44rem reading measure they left a hand's width of dead space either side of
// every card on a desktop screen, which is the one place the width is free.
$widePage = in_array($here, ['dashboard.php', 'notification.php', 'rides.php', 'earnings.php', 'payout.php'], true);
// Unread notices, for the header bell. Asked for once here rather than twice in
// the markup, and only when there is somebody signed in to ask about.
$unseen = ($driverUser && $pdoRef)
    ? DriverNotices::unseenBreakdown($pdoRef, (int)$driverUser['id'])
    : ['total' => 0, 'action' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($pageTitle ?? 'Driver | Exotic Lane Limo') ?></title>
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0A0A0C">
<link rel="icon" href="<?= url('favicon.ico') ?>">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="<?= asset('css/app.css?v=20261008b') ?>">
<link rel="stylesheet" href="<?= asset('css/driver.css?v=20261011c') ?>">
</head>
<body class="driver font-ui<?= $authPage ? ' drv-auth' : '' ?><?= $widePage ? ' is-wide' : '' ?>">
<?php if ($driverUser): ?>
<div class="drv-shell">

  <!--
    Desktop rail. Mobile keeps the top bar and the thumb dock instead - the
    driver is on a phone in a car, and a left rail is unreachable with a thumb.
  -->
  <aside class="drv-side">
    <a href="<?= url('driver/dashboard.php') ?>" class="drv-side-mark">
      <span class="drv-mark-a" aria-hidden="true">E</span>
      <span class="drv-mark-t">Exotic Lane<em>Driver</em></span>
    </a>

    <nav class="drv-side-nav" aria-label="Driver">
      <?php foreach ($nav as $file => $item):
        [$label, $icon] = $item;
        $on = $here === $file;
        $bell = $file === 'notification.php'; ?>
      <a href="<?= url('driver/' . $file) ?>"<?= $on ? ' aria-current="page"' : '' ?><?= $bell && $unseen['total'] > 0 ? ' class="has-new"' : '' ?>>
        <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
        <?php /* The count rides here too. The bell in the mobile header is the
                 phone's answer to this; a count that existed on one and not the
                 other would be half a feature. */ ?>
        <?php if ($bell && $unseen['total'] > 0): ?>
          <b class="drv-nav-n<?= $unseen['action'] > 0 ? ' is-urgent' : '' ?>"><?= e(DriverNotices::badgeLabel($unseen['total'])) ?></b>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($clearance): ?>
      <?php /* The band's dismissal must not erase the state, so it is restated
              here as a permanent line in the rail. */ ?>
      <a class="drv-side-status is-<?= e($clearance['tone']) ?>" href="<?= url('driver/setup.php') ?>">
        <span class="drv-side-dot" aria-hidden="true"></span>
        <span class="drv-side-status-t">
          <b><?= $clearance['cleared'] ? 'Cleared to drive' : $doneCount . ' of 3 on file' ?></b>
          <em><?= $clearance['cleared'] ? 'Accepting rides' : 'Finish to be cleared' ?></em>
        </span>
      </a>
    <?php endif; ?>

    <form method="post" action="<?= url('driver/logout.php') ?>" class="drv-side-out">
      <?= csrf_field() ?>
      <button type="submit"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sign out</button>
    </form>
  </aside>

  <div class="drv-col">
    <div class="drv-top">
    <?php /* The clearance band travels with the header, so "am I cleared?" is
             answerable at any scroll depth on any page - not just at the top. */ ?>
    <?php if ($clearance): ?>
      <?php $dismissable = !$clearance['cleared']; ?>
    <div class="clr-band is-<?= e($clearance['tone']) ?>" role="status"
         data-clr-band data-reason="<?= e($clearance['reason']) ?>">
      <i class="fa-solid <?= $clearance['tone'] === 'ok' ? 'fa-circle-check' : ($clearance['tone'] === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-exclamation') ?>" aria-hidden="true"></i>
      <span><?= e($clearance['reason']) ?></span>
      <?php /* On the setup page itself this call to action would link to the page
               the driver is already on, so it is suppressed there. */ ?>
      <?php if (!$clearance['cleared'] && !$onSetup): ?>
        <a href="<?= url('driver/setup.php') ?>">Finish setup</a>
      <?php endif; ?>
      <?php /* Only offered while something is outstanding. A green "you are
               cleared" banner is good news and needs no dismissing. */ ?>
      <?php if ($dismissable): ?>
        <button type="button" class="clr-x" data-clr-dismiss aria-label="Hide this message">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
        <?php /* Inline, on purpose. The close button is server-rendered, so seeing
                 it at all proves this HTML is fresh; wiring the handler from an
                 external file let a separately-cached script leave a live button
                 that did nothing. The behaviour is a few lines, so it lives with
                 the button it controls. */ ?>
        <script>
        (function () {
          var KEY = 'drv-band-dismissed';
          var band = document.currentScript.closest('.clr-band');
          var btn = band && band.querySelector('[data-clr-dismiss]');
          if (!band || !btn) return;
          var key = KEY + '::' + (band.dataset.reason || '');
          var seen = {};
          try { seen = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {}
          if (seen[key]) { band.remove(); return; }
          btn.addEventListener('click', function () {
            band.remove();
            try { seen[key] = 1; localStorage.setItem(KEY, JSON.stringify(seen)); } catch (e) {}
          });
        })();
        </script>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <header class="drv-head">
      <div class="drv-head-in">
        <a href="<?= url('driver/dashboard.php') ?>" class="drv-mark">
          <span class="drv-mark-a" aria-hidden="true">E</span>
          <span class="drv-mark-t">Exotic Lane<em>Driver</em></span>
        </a>
        <nav class="drv-nav" aria-label="Driver">
          <?php foreach ($nav as $file => [$label, $icon]):
            $on = $here === $file; ?>
          <a href="<?= url('driver/' . $file) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
            <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
          </a>
          <?php endforeach; ?>
        </nav>
        <form method="post" action="<?= url('driver/logout.php') ?>" class="drv-out">
          <?= csrf_field() ?>
          <button type="submit">Sign out</button>
        </form>
        <?php /* The bell lives in the mobile header, not the dock: it is the one
                 thing a driver checks without deciding to go somewhere, and a
                 badge in a thumb dock gets sat on top of. */ ?>
        <a class="drv-bell<?= $unseen['action'] > 0 ? ' is-urgent' : '' ?>" href="<?= url('driver/notification.php') ?>"
           aria-label="Notices<?= $unseen['total'] > 0 ? ', ' . $unseen['total'] . ' new' : '' ?>"
           <?= $here === 'notification.php' ? ' aria-current="page"' : '' ?>>
          <i class="fa-solid fa-bell" aria-hidden="true"></i>
          <?php if ($unseen['total'] > 0): ?>
            <span class="drv-bell-n"><?= e(DriverNotices::badgeLabel($unseen['total'])) ?></span>
          <?php endif; ?>
        </a>
      </div>
    </header>
    </div>

    <main class="drv-main"><?= $content ?></main>
  </div>
</div>
<?php else: ?>
<main class="drv-main drv-main-solo"><?= $content ?></main>
<?php endif; ?>

<?php if ($driverUser): ?>
<nav class="drv-dock" aria-label="Driver">
  <?php foreach ($nav as $file => $item):
    [$label, $icon] = $item;
    if (isset($item[2]) && $item[2] === 'no-dock') continue;
    $on = $here === $file; ?>
  <a href="<?= url('driver/' . $file) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
    <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i>
    <span><?= e($label) ?></span>
  </a>
  <?php endforeach; ?>
</nav>
<script src="<?= asset('js/driver.js?v=20261010c') ?>" defer></script>
<script src="<?= asset('js/camera.js?v=20261010a') ?>" defer></script>
<?php endif; ?>
</body>
</html>