<?php /** @var string $content */ $navActive = $navActive ?? ''; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Admin | Exotic Lane Limo') ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="<?= url('favicon.ico') ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
<link rel="stylesheet" href="<?= asset('css/app.css?v=20260924b') ?>">
<link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
<script src="<?= asset('js/admin.js') ?>" defer></script>
</head>
<body class="admin font-ui">
<div class="admin-shell">
<aside class="admin-side" aria-label="Admin navigation">
  <div class="font-display text-lg text-[#F3D4A6] px-2 mb-3">Admin</div>
  <?php $items = ['dashboard.php'=>'Dashboard','bookings.php'=>'Bookings','customers.php'=>'Customers','vehicles.php'=>'Vehicles','drivers.php'=>'Drivers','dispatch.php'=>'Dispatch','pricing.php'=>'Pricing','payments.php'=>'Payments','earnings.php'=>'Earnings','payouts.php'=>'Payouts','reports.php'=>'Reports','group-events.php'=>'Groups','notifications.php'=>'Notifications','cms.php'=>'CMS','settings.php'=>'Settings','audit.php'=>'Audit']; ?>
  <?php foreach ($items as $f => $label): ?>
    <a href="<?= url('admin/' . $f) ?>" class="<?= ($navActive === $f) ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  <a href="<?= url('admin/logout.php') ?>">Sign out</a>
  <a href="<?= url('index.php') ?>">← Site</a>
</aside>
<main class="admin-main"><?= $content ?></main>
</div>
</body>
</html>