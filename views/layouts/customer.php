<?php /** @var string $content */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'My Account | Exotic Lane Limo') ?></title>
<link rel="icon" href="<?= url('favicon.ico') ?>">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios@1/dist/axios.min.js"></script>
<link rel="stylesheet" href="<?= asset('css/app.css?v=20260924e') ?>">
<script src="<?= asset('js/account.js') ?>" defer></script>
</head>
<body class="font-ui">
<header class="bg-[#0F0F0E] border-b border-[#262628]"><div class="max-w-6xl mx-auto px-4 py-4 flex justify-between items-center">
<a href="<?= url('index.php') ?>" class="font-display text-xl text-[#F3D4A6]">Exotic Lane Limo</a>
<nav class="flex gap-4 text-sm"><a href="<?= url('account/index.php') ?>">Dashboard</a><a href="<?= url('account/bookings.php') ?>">Bookings</a><a href="<?= url('account/profile.php') ?>">Profile</a><a href="<?= url('auth/logout.php') ?>">Sign out</a></nav>
</div></header>
<main class="max-w-6xl mx-auto px-4 py-8"><?= $content ?></main>
</body>
</html>