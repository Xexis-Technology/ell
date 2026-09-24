<?php
/** @var string $content */ /** @var string|null $pageTitle */ /** @var string|null $metaDesc */
$pdo = isset($pdo) ? $pdo : null;
try { $pdo = $pdo ?? Database::pdo(); } catch (Throwable) { $pdo = null; }
$siteTitle = 'Exotic Lane Limo';
$metaDescription = 'Premium chauffeur, airport and hourly limo service.';
$logo = '';
if ($pdo) {
    try {
        $siteTitle = (string)(setting($pdo, 'site_title', $siteTitle));
        $metaDescription = (string)(setting($pdo, 'meta_description', $metaDescription));
        $logo = (string)(setting($pdo, 'site_logo', ''));
    } catch (Throwable) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? $siteTitle) ?></title>
<meta name="description" content="<?= e($metaDesc ?? $metaDescription) ?>">
<?php if ($pdo) { try {
    $kw = setting($pdo, 'meta_keywords', '');
    if ($kw) echo '<meta name="keywords" content="' . e($kw) . '">' . "\n";
    $gsc = setting($pdo, 'gsc_verification', '');
    if ($gsc) echo '<meta name="google-site-verification" content="' . e($gsc) . '">' . "\n";
    $ga = setting($pdo, 'ga_id', '');
    if ($ga) echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga) . '"></script>' . "\n";
} catch (Throwable) {} } ?>
<link rel="icon" href="<?= url('favicon.ico') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios@1/dist/axios.min.js"></script>
<link rel="stylesheet" href="<?= asset('css/app.css?v=20260924b') ?>">
<script src="<?= asset('js/app.js') ?>" defer></script>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"LimousineService","name":"Exotic Lane Limo","areaServed":"New York","priceRange":"$$$"}
</script>
</head>
<body class="font-ui no-overflow">
<?php if (empty($hideHeader)): ?>
<header class="border-b border-[#262628] bg-[#0F0F0E]">
  <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="<?= url('index.php') ?>" class="flex items-center gap-3">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="logo" class="h-9"><?php endif; ?>
      <span class="font-display text-2xl tracking-wide text-[#F3D4A6]">Exotic Lane Limo</span>
    </a>
    <nav class="hidden md:flex items-center gap-6 text-sm" aria-label="Primary">
      <a href="<?= url('services/index.php') ?>" class="hover:text-[#F3D4A6]">Services</a>
      <a href="<?= url('services/airport.php') ?>" class="hover:text-[#F3D4A6]">Airport</a>
      <a href="<?= url('services/hourly.php') ?>" class="hover:text-[#F3D4A6]">Hourly</a>
      <a href="<?= url('services/group-event.php') ?>" class="hover:text-[#F3D4A6]">Groups &amp; Events</a>
      <a href="<?= url('legal/faq.php') ?>" class="hover:text-[#F3D4A6]">FAQ</a>
      <a href="<?= url('legal/contact.php') ?>" class="hover:text-[#F3D4A6]">Contact</a>
    </nav>
    <div class="flex items-center gap-2">
      <?php if (current_user('customer')): ?>
        <a href="<?= url('account/index.php') ?>" class="btn-gold text-sm">My Account</a>
      <?php else: ?>
        <a href="<?= url('auth/login.php') ?>" class="text-sm hover:text-[#F3D4A6]">Sign in</a>
        <a href="<?= url('services/booking.php') ?>" class="btn-cta text-sm">Book Now</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php endif; ?>
<main><?= $content ?></main>
<footer class="bg-[#0F0F0E] border-t border-[#262628] mt-16">
  <div class="max-w-7xl mx-auto px-4 py-10 grid md:grid-cols-4 gap-8 text-sm">
    <div><h3 class="font-display text-xl text-[#F3D4A6] mb-3">Exotic Lane Limo</h3><p class="text-[#AB8868]">Luxury chauffeur service. Executive, premium, modern.</p></div>
    <div><h4 class="label">Services</h4><ul class="space-y-2"><li><a href="<?= url('services/point-to-point.php') ?>">Point-to-Point</a></li><li><a href="<?= url('services/airport.php') ?>">Airport</a></li><li><a href="<?= url('services/hourly.php') ?>">Hourly</a></li><li><a href="<?= url('services/group-event.php') ?>">Group &amp; Event</a></li></ul></div>
    <div><h4 class="label">Company</h4><ul class="space-y-2"><li><a href="<?= url('legal/faq.php') ?>">FAQ</a></li><li><a href="<?= url('legal/contact.php') ?>">Contact</a></li><li><a href="<?= url('legal/terms.php') ?>">Terms</a></li><li><a href="<?= url('legal/privacy.php') ?>">Privacy</a></li></ul></div>
    <div><h4 class="label">Account</h4><ul class="space-y-2"><li><a href="<?= url('auth/login.php') ?>">Sign in</a></li><li><a href="<?= url('auth/register.php') ?>">Register</a></li><li><a href="<?= url('driver/index.php') ?>">Drive with us</a></li><li><a href="<?= url('admin/index.php') ?>">Admin</a></li></ul></div>
  </div>
  <div class="border-t border-[#262628] py-4 text-center text-xs text-[#AB8868]">© <?= date('Y') ?> Exotic Lane Limo. All rights reserved.</div>
</footer>
</body>
</html>