<?php /** @var string $content */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Driver | Exotic Lane Limo') ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="<?= url('favicon.ico') ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
<link rel="stylesheet" href="<?= asset('css/app.css?v=20260924e') ?>">
<link rel="stylesheet" href="<?= asset('css/driver.css') ?>">
<script src="<?= asset('js/driver.js') ?>" defer></script>
</head>
<body class="driver font-ui">
<header class="bg-[#0F0F0E] border-b border-[#262628]"><div class="max-w-3xl mx-auto px-4 py-4 flex justify-between items-center">
<a href="<?= url('driver/dashboard.php') ?>" class="font-display text-xl text-[#F3D4A6]">Driver</a>
<nav class="flex gap-3 text-sm"><a href="<?= url('driver/dashboard.php') ?>">Home</a><a href="<?= url('driver/rides.php') ?>">Rides</a><a href="<?= url('driver/earnings.php') ?>">Earnings</a><a href="<?= url('driver/payout.php') ?>">Payout</a><a href="<?= url('driver/profile.php') ?>">Profile</a><a href="<?= url('driver/logout.php') ?>">Out</a></nav>
</div></header>
<main class="max-w-3xl mx-auto px-4 py-6"><?= $content ?></main>
</body>
</html>