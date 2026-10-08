<?php
/** @var string $content */ /** @var string|null $pageTitle */ /** @var string|null $metaDesc */
$pdo = isset($pdo) ? $pdo : null;
try { $pdo = $pdo ?? Database::pdo(); } catch (Throwable) { $pdo = null; }
$siteTitle = 'Exotic Lane Limo';
$metaDescription = 'Premium chauffeur, airport and hourly limo service.';
$logo = '';
// An uploaded logo is stored as storage/uploads/... which is a server path,
// not a URL. media_url() is what turns either form into something a browser
// can load, and it is the same helper the admin preview uses.
$logo = '';
if ($pdo) {
    try {
        $siteTitle = (string)(setting($pdo, 'site_title', $siteTitle));
        $metaDescription = (string)(setting($pdo, 'meta_description', $metaDescription));
        $logo = media_url(setting($pdo, 'site_logo', ''));
    } catch (Throwable) {}
}
// Newsletter subscription (footer form on every public page). PRG with query preserved.
$newsletterMsg = null;
$newsletterOk = isset($_GET['subscribed']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'newsletter' && $pdo) {
    if (!verify_csrf()) {
        $newsletterMsg = 'Invalid security token. Please try again.';
    } else {
        $nEmail = strtolower(trim((string)($_POST['newsletter_email'] ?? '')));
        if (!validate_email($nEmail)) {
            $newsletterMsg = 'Enter a valid email address.';
        } else {
            try {
                $pdo->prepare('INSERT INTO newsletter_subscribers (email, status) VALUES (?, "active") ON DUPLICATE KEY UPDATE status = "active", unsubscribed_at = NULL')->execute([$nEmail]);
                audit($pdo, 'system', null, 'newsletter.subscribed', null, null, ['email' => $nEmail]);
                $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
                $parts = parse_url($uri);
                $q = [];
                if (!empty($parts['query'])) parse_str($parts['query'], $q);
                $q['subscribed'] = '1';
                $to = ($parts['path'] ?? '/') . '?' . http_build_query($q);
                header('Location: ' . $to, true, 303);
                exit;
            } catch (Throwable $ex) {
                log_error('newsletter subscribe failed: ' . $ex->getMessage());
                $newsletterMsg = 'Could not subscribe right now. Please try again.';
            }
        }
    }
}
?>
<?php
/* SEO resolution. A page may override anything; whatever it leaves blank
   falls back to the site default in Settings. Nothing here is stored twice:
   one place decides what the head says, so the CMS preview can show exactly
   the lines below rather than an approximation of them. */
$seo = is_array($seo ?? null) ? $seo : [];
$seoTitle = trim((string)($seo['title'] ?? $pageTitle ?? ''));
if ($seoTitle === '') $seoTitle = (string)$siteTitle;
$seoDesc = trim((string)($seo['description'] ?? $metaDesc ?? ''));
if ($seoDesc === '') $seoDesc = (string)$metaDescription;
$seoKeywords = trim((string)($seo['keywords'] ?? $metaKeywords ?? ''));
if ($seoKeywords === '') $seoKeywords = (string)setting($pdo, 'meta_keywords', '');
$seoOgTitle = trim((string)($seo['og_title'] ?? '')) ?: $seoTitle;
$seoOgDesc = trim((string)($seo['og_description'] ?? '')) ?: $seoDesc;
$seoOgImage = media_url($seo['og_image'] ?? '') ?: media_url(setting($pdo, 'og_image', ''));
$seoCanonical = trim((string)($seo['canonical'] ?? ''));
if ($seoCanonical === '') {
    // SITE_URL already carries the install path ("/ell"), so the script name
    // has that prefix removed before it is appended - otherwise the canonical
    // comes out as /ell/ell/legal/privacy.php.
    $basePath = rtrim((string)(parse_url(SITE_URL, PHP_URL_PATH) ?: ''), '/');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    if ($basePath !== '' && str_starts_with($script, $basePath)) $script = substr($script, strlen($basePath));
    $query = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?: '');
    $seoCanonical = SITE_URL . '/' . ltrim($script, '/') . ($query !== '' ? '?' . $query : '');
}
$seoNoindex = !empty($seo['noindex']);
$siteName = (string)setting($pdo, 'org_name', 'Exotic Lane Limo');
$favicon = '';
try { $favicon = media_url(setting($pdo, 'favicon', '')); } catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($seoTitle) ?></title>
<meta name="description" content="<?= e($seoDesc) ?>">
<?php if ($seoKeywords !== '') echo '<meta name="keywords" content="' . e($seoKeywords) . '">' . "\n"; ?>
<meta name="robots" content="<?= $seoNoindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large' ?>">
<link rel="canonical" href="<?= e($seoCanonical) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:title" content="<?= e($seoOgTitle) ?>">
<meta property="og:description" content="<?= e($seoOgDesc) ?>">
<meta property="og:url" content="<?= e($seoCanonical) ?>">
<meta property="og:locale" content="en_US">
<?php if ($seoOgImage !== ''): ?>
<meta property="og:image" content="<?= e($seoOgImage) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $seoOgImage !== '' ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($seoOgTitle) ?>">
<meta name="twitter:description" content="<?= e($seoOgDesc) ?>">
<?php if ($pdo) { try {
    $gsc = setting($pdo, 'gsc_verification', '');
    if ($gsc) echo '<meta name="google-site-verification" content="' . e($gsc) . '">' . "\n";
    $ga = setting($pdo, 'ga_id', '');
    if ($ga) echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga) . '"></script>' . "\n";
} catch (Throwable) {} } ?>
<link rel="icon" href="<?= e($favicon ?: url('favicon.ico')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios@1/dist/axios.min.js"></script>
<link rel="stylesheet" href="<?= asset('css/app.css?v=20260924g') ?>">
<script src="<?= asset('js/app.js') ?>" defer></script>
<script type="application/ld+json">
<?php
/**
 * Structured data, built by the same function the CMS preview calls, so the
 * preview on the admin page is the markup that ships rather than a
 * hand-typed approximation of it.
 *
 * A page can add its own nodes by setting $schemaNodes before requiring this
 * layout - the FAQ page adds FAQPage, a blog post adds BlogPosting.
 */
$schemaGraph = seo_schema_graph($pdo, [
    'url' => $seoCanonical,
    'title' => $seoTitle,
    'description' => $seoDesc,
    'extra' => is_array($schemaNodes ?? null) ? $schemaNodes : [],
]);
$schemaJson = json_encode(
    $schemaGraph,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
if ($schemaJson === false) {
    // Malformed data must never take the page down; an invalid block would
    // simply be ignored by a crawler, but a fatal error would hide the site.
    log_error('structured data could not be encoded: ' . json_last_error_msg());
    $schemaJson = '{}';
}
echo str_replace('</', '<\/', $schemaJson);
?>
</script>
</head>
<body class="font-ui no-overflow">
<?php if (empty($hideHeader)): ?>
<?php if (($headerVariant ?? 'default') === 'index'): ?>
<header id="stickyHeader" class="fixed top-0 inset-x-0 z-50 bg-[#0F0F0E]/95 backdrop-blur border-b border-[#262628]" style="display:none">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
    <a href="<?= url('index.php') ?>" class="flex items-center gap-2.5"><?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="Exotic Lane Limo" class="h-8" decoding="async" onerror="this.remove()"><?php else: ?><span class="font-display text-xl text-[#F3D4A6]">Exotic Lane Limo</span><?php endif; ?></a>
    <nav class="hidden md:flex items-center gap-5 text-sm" aria-label="Sticky">
      <a href="<?= url('services/index.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/index.php') ?>">Services</a>
      <a href="<?= url('services/point-to-point.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/point-to-point.php') ?>">Point-to-Point</a>
      <a href="<?= url('services/airport.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/airport.php') ?>">Airport</a>
      <a href="<?= url('services/hourly.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/hourly.php') ?>">Hourly</a>
      <a href="<?= url('services/group-event.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/group-event.php') ?>">Groups</a>
      <a href="<?= url('legal/contact.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('legal/contact.php') ?>">Contact</a>
    </nav>
    <?php if (current_user('customer')): ?>
      <a href="<?= url('account/index.php') ?>" class="btn-gold text-sm rounded-full px-5 py-2">My Account</a>
    <?php else: ?>
      <a href="<?= url('services/booking.php') ?>" class="btn-cta text-sm rounded-full px-5 py-2">Book Now</a>
    <?php endif; ?>
  </div>
</header>
<script>
(function () {
  var bar = document.getElementById('stickyHeader');
  if (!bar) return;
  function onScroll() {
    const y = window.scrollY || document.documentElement.scrollTop;
    bar.style.display = y > 160 ? 'block' : 'none';
  }
  window.addEventListener('scroll', onScroll, {passive: true});
  onScroll();
})();
</script>
<header class="bg-[#0A0A0C]" x-data="{menu:false}">
  <div class="max-w-7xl mx-auto px-4 flex items-center justify-between py-5">
    <a href="<?= url('index.php') ?>" class="flex items-center gap-3"><?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="Exotic Lane Limo" class="h-8 md:h-9" decoding="async" onerror="this.remove()"><?php else: ?><span class="font-display text-2xl md:text-3xl tracking-wide text-[#F9F9F9]">Exotic Lane Limo</span><?php endif; ?></a>
    <nav class="hidden md:flex items-center gap-7 text-sm text-[#F5F5F3]" aria-label="Primary">
      <a href="<?= url('services/booking.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/booking.php') ?>">Book a Ride</a>
      <a href="<?= url('services/index.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/index.php') ?>">Services</a>
      <a href="<?= url('services/airport.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/airport.php') ?>">Airport Transfers</a>
      <a href="<?= url('services/blogs/index.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active(['services/blogs/index.php', 'services/blogs/read.php']) ?>">Blog</a>
      <a href="<?= url('legal/contact.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active(['legal/contact.php', 'legal/faq.php']) ?>">Support</a>
    </nav>
    <div class="flex items-center gap-3">
      <?php if (current_user('customer')): ?>
        <a href="<?= url('account/index.php') ?>" class="hidden md:inline-block text-sm hover:text-[#F3D4A6]">My Account</a>
      <?php else: ?>
        <a href="<?= url('auth/login.php') ?>" class="hidden md:inline-block text-sm hover:text-[#F3D4A6]">Log in</a>
      <?php endif; ?>
      <a href="<?= url('services/booking.php') ?>" class="hidden md:inline-flex items-center gap-1 bg-[#F9F9F9] text-[#0A0A0C] text-sm font-semibold px-5 py-2 rounded-full">Book Now <span aria-hidden="true">&rarr;</span></a>
      <button class="md:hidden text-[#F9F9F9] p-2" @click="menu = !menu" aria-label="Open menu" aria-expanded="false">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="7" x2="21" y2="7"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="17" x2="21" y2="17"/></svg>
      </button>
    </div>
  </div>
  <div class="md:hidden px-4 pb-4" x-show="menu" x-cloak>
    <nav class="bg-[#181819] border border-[#2a2a2b] rounded-2xl p-4 space-y-1 text-[#F5F5F3] text-sm font-medium" aria-label="Mobile">
      <a href="<?= url('services/booking.php') ?>" class="block px-3 py-2 <?= nav_active('services/booking.php', false) ?>">Book a Ride</a>
      <a href="<?= url('services/index.php') ?>" class="block px-3 py-2 <?= nav_active('services/index.php', false) ?>">Services</a>
      <a href="<?= url('services/point-to-point.php') ?>" class="block px-3 py-2 <?= nav_active('services/point-to-point.php', false) ?>">Point-to-Point</a>
      <a href="<?= url('services/airport.php') ?>" class="block px-3 py-2 <?= nav_active('services/airport.php', false) ?>">Airport Transfers</a>
      <a href="<?= url('services/hourly.php') ?>" class="block px-3 py-2 <?= nav_active('services/hourly.php', false) ?>">Hourly</a>
      <a href="<?= url('services/group-event.php') ?>" class="block px-3 py-2 <?= nav_active('services/group-event.php', false) ?>">Groups &amp; Events</a>
      <a href="<?= url('services/blogs/index.php') ?>" class="block px-3 py-2 <?= nav_active(['services/blogs/index.php', 'services/blogs/read.php'], false) ?>">Blog</a>
      <a href="<?= url('legal/faq.php') ?>" class="block px-3 py-2 <?= nav_active('legal/faq.php', false) ?>">FAQ</a>
      <a href="<?= url('legal/contact.php') ?>" class="block px-3 py-2 <?= nav_active(['legal/contact.php', 'legal/faq.php'], false) ?>">Support</a>
      <a href="<?= url('services/booking.php') ?>" class="btn-gold block text-center rounded-full mt-2">Book Now</a>
    </nav>
  </div>
</header>
<?php else: ?>
<header class="border-b border-[#262628] bg-[#0F0F0E]" x-data="{menu:false}">
  <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="<?= url('index.php') ?>" class="flex items-center gap-3">
      <?php if (!empty($logo)): ?><img src="<?= e($logo) ?>" alt="Exotic Lane Limo logo" class="h-9" decoding="async"><?php endif; ?>
      <span class="font-display text-2xl tracking-wide text-[#F3D4A6]">Exotic Lane Limo</span>
    </a>
    <nav class="hidden md:flex items-center gap-6 text-sm" aria-label="Primary">
      <a href="<?= url('services/index.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active(['services/index.php', 'services/booking.php', 'services/booking-confirmation.php', 'services/payment.php']) ?>">Services</a>
      <a href="<?= url('services/airport.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/airport.php') ?>">Airport</a>
      <a href="<?= url('services/hourly.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/hourly.php') ?>">Hourly</a>
      <a href="<?= url('services/group-event.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('services/group-event.php') ?>">Groups &amp; Events</a>
      <a href="<?= url('services/blogs/index.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active(['services/blogs/index.php', 'services/blogs/read.php']) ?>">Blog</a>
      <a href="<?= url('legal/faq.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('legal/faq.php') ?>">FAQ</a>
      <a href="<?= url('legal/contact.php') ?>" class="hover:text-[#F3D4A6] <?= nav_active('legal/contact.php') ?>">Contact</a>
    </nav>
    <div class="flex items-center gap-2">
      <?php if (current_user('customer')): ?>
        <a href="<?= url('account/index.php') ?>" class="btn-gold text-sm">My Account</a>
      <?php else: ?>
        <a href="<?= url('auth/login.php') ?>" class="hidden md:inline text-sm hover:text-[#F3D4A6]">Sign in</a>
        <a href="<?= url('services/booking.php') ?>" class="btn-cta text-sm hidden md:inline-block">Book Now</a>
      <?php endif; ?>
      <button class="md:hidden text-[#F9F9F9] p-2" @click="menu = !menu" aria-label="Open menu" aria-expanded="false">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="7" x2="21" y2="7"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="17" x2="21" y2="17"/></svg>
      </button>
    </div>
  </div>
  <div class="md:hidden px-4 pb-4" x-show="menu" x-cloak>
    <nav class="bg-[#181819] border border-[#2a2a2b] rounded-2xl p-4 space-y-1 text-[#F5F5F3] text-sm font-medium" aria-label="Mobile">
      <a href="<?= url('services/index.php') ?>" class="block px-3 py-2 <?= nav_active(['services/index.php', 'services/booking.php', 'services/booking-confirmation.php', 'services/payment.php'], false) ?>">Services</a>
      <a href="<?= url('services/airport.php') ?>" class="block px-3 py-2 <?= nav_active('services/airport.php', false) ?>">Airport</a>
      <a href="<?= url('services/hourly.php') ?>" class="block px-3 py-2 <?= nav_active('services/hourly.php', false) ?>">Hourly</a>
      <a href="<?= url('services/group-event.php') ?>" class="block px-3 py-2 <?= nav_active('services/group-event.php', false) ?>">Groups &amp; Events</a>
      <a href="<?= url('services/blogs/index.php') ?>" class="block px-3 py-2 <?= nav_active(['services/blogs/index.php', 'services/blogs/read.php'], false) ?>">Blog</a>
      <a href="<?= url('legal/faq.php') ?>" class="block px-3 py-2 <?= nav_active('legal/faq.php', false) ?>">FAQ</a>
      <a href="<?= url('legal/contact.php') ?>" class="block px-3 py-2 <?= nav_active('legal/contact.php', false) ?>">Contact</a>
      <?php if (current_user('customer')): ?>
        <a href="<?= url('account/index.php') ?>" class="btn-gold block text-center rounded-full mt-2">My Account</a>
      <?php else: ?>
        <a href="<?= url('auth/login.php') ?>" class="block px-3 py-2">Sign in</a>
        <a href="<?= url('services/booking.php') ?>" class="btn-cta block text-center rounded-full mt-2">Book Now</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php endif; ?>
<?php endif; ?>
<main><?= $content ?></main>
<?php
$orgPhone = $orgEmail = $orgAddress = '';
$socials = [];
if ($pdo) {
    try {
        $orgPhone = (string)(setting($pdo, 'org_phone', ''));
        $orgEmail = (string)(setting($pdo, 'org_email', ''));
        // Joined from the address parts so the footer and the structured data
        // are guaranteed to say the same thing.
        $orgAddress = seo_address_line($pdo);
        foreach (['facebook' => 'social_facebook', 'instagram' => 'social_instagram', 'x' => 'social_x'] as $label => $k) {
            $u = (string)(setting($pdo, $k, ''));
            if ($u !== '') $socials[$label] = $u;
        }
    } catch (Throwable) {}
}
?>
<div class="max-w-3xl mx-auto px-4 mt-16 text-center">
  <p class="eyebrow">Newsletter</p>
  <h2 class="font-display text-3xl md:text-4xl text-[#F9F9F9] mt-2">Fare updates and seasonal offers</h2>
  <p class="text-sm text-[#AB8868] mt-2">One email at a time, never spam. Unsubscribe anytime.</p>
  <?php if ($newsletterOk): ?><div class="alert alert-ok mt-4">You're on the list &mdash; welcome aboard.</div>
  <?php elseif ($newsletterMsg): ?><div class="alert alert-err mt-4"><?= e($newsletterMsg) ?></div><?php endif; ?>
  <form method="post" class="mt-4 flex flex-col sm:flex-row gap-2 max-w-md mx-auto"><?= csrf_field() ?>
    <input type="hidden" name="form" value="newsletter">
    <input type="email" name="newsletter_email" required placeholder="you@example.com" aria-label="Email for newsletter" class="input rounded-full flex-1">
    <button class="btn-gold rounded-full text-sm font-semibold px-6 py-3 shrink-0">Subscribe</button>
  </form>
</div>
<footer class="max-w-7xl mx-auto px-4 mt-10">
  <div class="bg-[#0F0F0E] border border-[#262628] rounded-3xl p-6 md:p-10 grid gap-8 md:grid-cols-2 lg:grid-cols-[1.3fr_1fr_1fr_1fr_1.3fr]">
    <div>
      <p class="font-display text-2xl text-[#F3D4A6]">Exotic Lane Limo</p>
      <p class="text-sm text-[#AB8868] mt-2">Luxury chauffeur service. Executive, premium, modern.</p>
      <div class="text-sm text-[#E5E5E3] mt-4 space-y-1">
        <?php if ($orgPhone): ?><p><?= e($orgPhone) ?></p><?php endif; ?>
        <?php if ($orgEmail): ?><p><?= e($orgEmail) ?></p><?php endif; ?>
        <?php if ($orgAddress): ?><p><?= e($orgAddress) ?></p><?php endif; ?>
      </div>
      <?php if ($socials): ?><p class="mt-3 text-sm space-x-3"><?php foreach ($socials as $label => $u): ?><a class="underline text-[#C8A96B]" href="<?= e($u) ?>"><?= e(ucfirst($label)) ?></a><?php endforeach; ?></p><?php endif; ?>
    </div>
    <nav aria-label="Services">
      <h3 class="label">Services</h3>
      <ul class="space-y-2 text-sm"><li><a href="<?= url('services/point-to-point.php') ?>">Point-to-Point</a></li><li><a href="<?= url('services/airport.php') ?>">Airport</a></li><li><a href="<?= url('services/hourly.php') ?>">Hourly</a></li><li><a href="<?= url('services/group-event.php') ?>">Group &amp; Event</a></li></ul>
    </nav>
    <nav aria-label="Company">
      <h3 class="label">Company</h3>
      <ul class="space-y-2 text-sm"><li><a href="<?= url('legal/faq.php') ?>">FAQ</a></li><li><a href="<?= url('services/blogs/index.php') ?>">Blog</a></li><li><a href="<?= url('legal/contact.php') ?>">Contact</a></li><li><a href="<?= url('legal/terms.php') ?>">Terms</a></li><li><a href="<?= url('legal/privacy.php') ?>">Privacy</a></li></ul>
    </nav>
    <nav aria-label="Account">
      <h3 class="label">Account</h3>
      <ul class="space-y-2 text-sm"><li><a href="<?= url('auth/login.php') ?>">Sign in</a></li><li><a href="<?= url('auth/register.php') ?>">Register</a></li><li><a href="<?= url('driver/index.php') ?>">Drive with us</a></li><li><a href="<?= url('admin/index.php') ?>">Admin</a></li></ul>
    </nav>
    <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868] min-h-[180px] relative">
      <img src="https://images.unsplash.com/photo-1502877338535-766e1452684a?auto=format&fit=crop&w=800&q=60" alt="Luxury car at night" class="absolute inset-0 w-full h-full object-cover" loading="lazy" decoding="async" onerror="this.style.display='none'">
    </div>
  </div>
  <div class="py-6 text-center text-xs text-[#AB8868]">&copy; <?= date('Y') ?> Exotic Lane Limo. All rights reserved.</div>
</footer>
</body>
</html>
