<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$cards = [
    ['icon' => 'pin', 'name' => 'Point-to-Point', 'desc' => 'Pickup to destination with up to 6 stops, priced per mile and locked before you pay.', 'url' => 'services/point-to-point.php', 'book' => 'services/booking.php?service=point_to_point'],
    ['icon' => 'plane', 'name' => 'Airport Transportation', 'desc' => 'Airport to address and back, with 60 minutes of free waiting on every pickup.', 'url' => 'services/airport.php', 'book' => 'services/booking.php?service=airport'],
    ['icon' => 'clock', 'name' => 'Hourly Charter', 'desc' => 'A chauffeur on standby from a 2-hour minimum — add hours, never less.', 'url' => 'services/hourly.php', 'book' => 'services/booking.php?service=hourly'],
    ['icon' => 'users', 'name' => 'Group & Event', 'desc' => 'Multi-vehicle transportation for weddings and occasions, quoted by our team.', 'url' => 'services/group-event.php', 'book' => 'services/group-event.php'],
    ['icon' => 'case', 'name' => 'Direct Contract', 'desc' => 'Corporate accounts and recurring routes with direct billing.', 'url' => 'services/direct-contract.php', 'book' => 'services/direct-contract.php'],
];
ob_start();
?>
<!-- HERO PANEL -->
<section class="max-w-7xl mx-auto px-4 pt-6">
  <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#181819] via-[#212121] to-[#3a2f1d] p-6 md:p-12">
    <div class="grid md:grid-cols-2 gap-8 items-start">
      <div></div>
      <div class="text-sm text-[#E5E5E3] max-w-sm md:justify-self-end">
        <p>From point-to-point to multi-vehicle events, one team drives every Exotic Lane service — with the price locked before you pay.</p>
        <div class="flex flex-wrap gap-2 mt-4">
          <a href="<?= url('services/booking.php') ?>" class="btn-gold rounded-full text-sm px-6 py-2.5">Book now</a>
          <a href="#service-list" class="rounded-full text-sm px-6 py-2.5 border border-[#C8A96B] text-[#F3D4A6]">Learn more</a>
        </div>
      </div>
    </div>
    <div class="mt-8 md:mt-12 flex items-end justify-between gap-6">
      <h1 class="font-display text-4xl md:text-6xl text-[#F9F9F9] leading-tight">Chauffeured service<br>for every <em class="text-[#F3D4A6]">occasion</em> <span aria-hidden="true">→</span></h1>
      <a href="#service-list" aria-label="Scroll to services" class="hidden md:inline-flex w-16 h-16 shrink-0 rounded-full border border-[#C8A96B] items-center justify-center text-[#F3D4A6]">↓</a>
    </div>
    <div class="mt-6 flex flex-wrap gap-2">
      <span class="tabular text-[11px] tracking-widest text-[#0A0A0C] bg-[#D9B978] rounded-full px-4 py-1.5">FIXED PRICING</span>
      <span class="tabular text-[11px] tracking-widest text-[#F5F5F3] border border-[#3a3a3d] rounded-full px-4 py-1.5">60-MIN AIRPORT WAIT</span>
      <span class="tabular text-[11px] tracking-widest text-[#F5F5F3] border border-[#3a3a3d] rounded-full px-4 py-1.5">2-HR HOURLY MINIMUM</span>
    </div>
  </div>
</section>

<!-- ABOUT -->
<section class="max-w-7xl mx-auto px-4 py-10 md:py-14 grid lg:grid-cols-2 gap-10 items-center">
  <div>
    <p class="eyebrow">Why Exotic Lane</p>
    <h2 class="font-display text-3xl md:text-5xl text-[#F9F9F9] mt-2">Executive travel,<br>handled.</h2>
    <p class="text-sm text-[#AB8868] mt-4 max-w-md">Professional chauffeurs, immaculate vehicles and server-side pricing. You tell us the route — airport, hourly or multi-stop — and we handle dispatch, waiting and confirmation by email.</p>
    <a href="<?= url('services/booking.php') ?>" class="btn-gold rounded-full inline-block mt-6 text-sm px-6 py-2.5">Book now</a>
  </div>
  <div class="relative">
    <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
      <img src="https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=1000&q=60" alt="Black luxury sedan at night" class="w-full h-[300px] md:h-[400px] object-cover" loading="lazy" onerror="this.style.display='none'">
    </div>
    <div class="absolute -bottom-5 left-4 right-4 sm:left-6 sm:right-auto sm:w-72 bg-[#181819] border border-[#2a2a2b] rounded-2xl p-4 shadow-2xl">
      <p class="tabular text-[10px] tracking-widest text-[#AB8868]">EVERY RIDE INCLUDES</p>
      <p class="text-sm text-[#F9F9F9] mt-1 font-semibold">Invoice · confirmation email · free waiting</p>
    </div>
  </div>
</section>

<!-- SERVICE CARDS -->
<section id="service-list" class="max-w-7xl mx-auto px-4 py-10 md:py-14">
  <p class="eyebrow">Services</p>
  <div class="flex flex-wrap items-end justify-between gap-4 mt-2">
    <h2 class="font-display text-3xl md:text-4xl text-[#F9F9F9]">Built around your route</h2>
    <a href="<?= url('services/booking.php') ?>" class="btn-gold rounded-full text-sm px-6 py-2.5">Book now</a>
  </div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-8">
    <?php
    $icons = [
      'pin' => '<path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
      'plane' => '<path d="M10 20l2-8 8-8-8 2-2 6-6 2 6 2 2 4z"/><circle cx="19" cy="5" r="1.5"/>',
      'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.6c2.8.4 5.5 2 5.5 5.4"/>',
      'case' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>',
    ];
    foreach ($cards as $card):
    ?>
    <article class="card rounded-2xl p-6 flex flex-col">
      <span class="w-11 h-11 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center" aria-hidden="true">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$card['icon']] ?></svg>
      </span>
      <h3 class="font-display text-2xl text-[#F9F9F9] mt-4"><?= e($card['name']) ?></h3>
      <p class="text-sm text-[#AB8868] mt-2 flex-1"><?= e($card['desc']) ?></p>
      <div class="grid grid-cols-2 gap-2 mt-4">
        <a href="<?= url($card['book']) ?>" class="btn-gold rounded-full text-center text-xs font-semibold px-4 py-2.5">Book</a>
        <a href="<?= url($card['url']) ?>" class="rounded-full text-center text-xs font-semibold px-4 py-2.5 border border-[#C8A96B] text-[#F3D4A6] hover:bg-[#D9B978]/10">Read more</a>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>

<!-- WHY US + FLEET SHOWCASE -->
<section class="max-w-7xl mx-auto px-4 py-10 md:py-14 hairline-t">
  <div class="grid lg:grid-cols-2 gap-10 items-start">
    <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
      <img src="https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=1000&q=60" alt="Chauffeur driving at night" class="w-full h-[280px] md:h-[360px] object-cover" loading="lazy" onerror="this.style.display='none'">
    </div>
    <div>
      <p class="eyebrow">Why choose us</p>
      <h2 class="font-display text-3xl md:text-4xl text-[#F9F9F9] mt-2">Built on trust,<br>driven by results</h2>
      <div class="mt-6">
        <details class="hairline-b py-4 group" open>
          <summary class="flex items-center justify-between gap-3 text-sm text-[#F9F9F9] font-semibold cursor-pointer [&::-webkit-details-marker]:hidden">Fixed, locked pricing<span class="text-[#C8A96B] group-open:rotate-180 transition-transform" aria-hidden="true">▾</span></summary>
          <p class="text-sm text-[#AB8868] mt-2">Every total is calculated on our servers and frozen before you pay. The price you approve is the price you pay.</p>
        </details>
        <details class="hairline-b py-4 group">
          <summary class="flex items-center justify-between gap-3 text-sm text-[#F9F9F9] font-semibold cursor-pointer [&::-webkit-details-marker]:hidden">Waiting without worry<span class="text-[#C8A96B] group-open:rotate-180 transition-transform" aria-hidden="true">▾</span></summary>
          <p class="text-sm text-[#AB8868] mt-2">60 free minutes at airports, 30 at bus, train and cruise terminals, 15 on point-to-point rides.</p>
        </details>
        <details class="hairline-b py-4 group">
          <summary class="flex items-center justify-between gap-3 text-sm text-[#F9F9F9] font-semibold cursor-pointer [&::-webkit-details-marker]:hidden">Secure payment &amp; invoices<span class="text-[#C8A96B] group-open:rotate-180 transition-transform" aria-hidden="true">▾</span></summary>
          <p class="text-sm text-[#AB8868] mt-2">Stripe checkout, secure payment links and a PDF invoice on every booking.</p>
        </details>
        <details class="py-4 group">
          <summary class="flex items-center justify-between gap-3 text-sm text-[#F9F9F9] font-semibold cursor-pointer [&::-webkit-details-marker]:hidden">Professional chauffeurs<span class="text-[#C8A96B] group-open:rotate-180 transition-transform" aria-hidden="true">▾</span></summary>
          <p class="text-sm text-[#AB8868] mt-2">Vetted drivers, dispatched by our team and confirmed to you by email.</p>
        </details>
      </div>
    </div>
  </div>
</section>
<?php
$content = ob_get_clean();
$pageTitle = 'Services | Exotic Lane Limo';
$metaDesc = 'Point-to-point, airport and hourly chauffeured services with transparent pricing.';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
