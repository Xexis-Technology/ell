<?php /** @var string $content */ $navActive = $navActive ?? ''; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Admin | Exotic Lane Limo') ?></title>
<meta name="robots" content="noindex,nofollow">
<?php
// The favicon uploaded in SEO & content is the admin's favicon too, so the
// console is branded the same way the public site is.
$adminFavicon = '';
try {
    if (isset($pdo)) $adminFavicon = media_url(setting($pdo, 'favicon', ''));
} catch (Throwable) {}
?>
<link rel="icon" href="<?= e($adminFavicon ?: url('favicon.ico')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
<link rel="stylesheet" href="<?= asset('css/app.css?v=20261014f') ?>">
<link rel="stylesheet" href="<?= asset('css/admin.css?v=20261014b') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.core.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<script src="<?= asset('js/admin.js?v=20261012a') ?>" defer></script>
<script src="<?= asset('js/alerts.js?v=20261014b') ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" defer></script>
</head>
<body class="admin font-ui">
<div class="admin-shell">
<aside class="admin-side" aria-label="Admin navigation">
  <div class="admin-brand">
    <span class="mark" aria-hidden="true">E</span>
    <span><span class="name">Exotic Lane</span><br><span class="sub">ADMIN CONSOLE</span></span>
  </div>
<nav class="admin-nav">
    <?php
    /* Badges say "something here needs a person you have not looked at yet",
       not "how many rows exist". Anything with no actionable state - reports,
       pricing, settings, audit - deliberately shows nothing, because a number
       you cannot act on just teaches you to stop reading them.

       A badge is answered by opening the page, so it is not merely hidden on the
       page you are already on: opening it stamps your visit, and the count stays
       quiet until something NEWER turns up. Without that, two inquiries you
       opened the Groups page for would follow you around the console for the
       rest of the day, telling you to go look at what is already on screen.
       See admin_nav_state(). */
    $navMe      = current_user('admin');
    $navAdminId = is_array($navMe) && isset($navMe['id']) ? (int)$navMe['id'] : null;
    try { $navState = admin_nav_state(Database::pdo(), $navAdminId); } catch (Throwable) { $navState = []; }

    $navGroups = [
      'Operations' => ['dashboard.php' => ['Dashboard','fa-gauge-high'],'bookings.php' => ['Bookings','fa-calendar-check'],'dispatch.php' => ['Dispatch','fa-paper-plane'],'customers.php' => ['Customers','fa-users']],
      'Fleet' => ['vehicles.php' => ['Vehicles','fa-car'],'drivers.php' => ['Drivers','fa-id-card']],
      'Money' => ['pricing.php' => ['Pricing','fa-tag'],'payments.php' => ['Payments','fa-credit-card'],'earnings.php' => ['Earnings','fa-wallet'],'payouts.php' => ['Payouts','fa-money-bill-wave']],
      'Content' => ['reports.php' => ['Reports','fa-chart-line'],'group-events.php' => ['Groups','fa-calendar-days'],'user-page-design.php' => ['User Page Design','fa-pen-to-square'],'blog.php' => ['Blog','fa-newspaper'],'notifications.php' => ['Notifications','fa-bell'],'cms.php' => ['CMS','fa-file-lines']],
      'System' => ['settings.php' => ['Settings','fa-gear'],'audit.php' => ['Audit','fa-shield-halved']],
    ];
    foreach ($navGroups as $glabel => $items):
    ?>
    <p class="admin-group"><?= e($glabel) ?></p>
    <?php foreach ($items as $f => [$label, $icon]):
      /* Ask before rendering, so the badge is already settled by the time this
         page's own HTML reaches the browser - no flash of a count you just
         cleared by opening the page. */
      $n = admin_nav_shows($navState[$f] ?? [], $navActive === $f) ? (int)($navState[$f]['n'] ?? 0) : 0;
    ?>
      <a class="adm-link <?= ($navActive === $f) ? 'active' : '' ?><?= $n > 0 ? ' has-badge' : '' ?>" href="<?= url('admin/' . $f) ?>"><i class="fa-solid <?= e($icon) ?> adm-ico" aria-hidden="true"></i><span class="adm-label"><?= e($label) ?></span><?php if ($n > 0): ?><b class="adm-badge" title="<?= e($n) ?> item<?= $n === 1 ? '' : 's' ?> here need attention"><?= e(admin_badge($n)) ?></b><?php endif; ?></a>
    <?php endforeach; ?>
    <?php endforeach; ?>
    <?php /* Only after rendering, and only for a section that actually carries a
             badge, so opening the FAQ manager or Pricing does not silently
             bookmark anything. */ ?>
    <?php if (isset($navState[$navActive])) admin_nav_mark_seen(Database::pdo(), $navAdminId, $navActive); ?>
  </nav>
  <div class="admin-foot">
    <a class="adm-link" href="<?= url('admin/logout.php') ?>"><i class="fa-solid fa-right-from-bracket adm-ico" aria-hidden="true"></i>Sign out</a>
    <a class="adm-link" href="<?= url('index.php') ?>"><i class="fa-solid fa-globe adm-ico" aria-hidden="true"></i>View site</a>
  </div>
</aside>
<main class="admin-main"><?= $content ?></main>
</div>
</body>
</html>
























