<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
ob_start();
?>
<!-- HERO PANEL -->
<section class="max-w-7xl mx-auto px-4 pt-6">
  <div class="relative overflow-hidden rounded-3xl bg-[#0F0F0E] border border-[#262628]">
    <img src="https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=format&fit=crop&w=1600&q=60" alt="" aria-hidden="true" class="absolute inset-0 w-full h-full object-cover opacity-40" loading="lazy" onerror="this.style.display='none'">
    <div class="absolute inset-0 bg-gradient-to-r from-[#0A0A0C] via-[#0A0A0C]/70 to-transparent"></div>
    <div class="relative p-6 md:p-12">
      <h1 class="text-5xl md:text-7xl text-[#F9F9F9] font-semibold leading-tight">Time on<br><em class="font-display font-medium text-[#F3D4A6]">your</em> side</h1>
      <a href="<?= url('services/booking.php?service=hourly') ?>" class="btn-gold rounded-full inline-flex items-center gap-2 mt-8 text-sm px-7 py-3">Book hourly charter <span aria-hidden="true">→</span></a>
    </div>
    <div class="relative md:absolute md:right-8 md:bottom-8 md:w-64 bg-[#181819]/95 border border-[#2a2a2b] rounded-2xl p-5 m-6 md:m-0">
      <p class="tabular text-[10px] tracking-widest text-[#AB8868]">GOOD TO KNOW</p>
      <p class="font-display text-xl text-[#F9F9F9] mt-1">2 hours, then flexible</p>
      <p class="text-xs text-[#AB8868] mt-1">Additional hours priced per vehicle · business, evenings, weddings</p>
      <a href="<?= url('services/booking.php?service=hourly') ?>" class="text-xs text-[#F3D4A6] underline mt-2 inline-block">Book now →</a>
    </div>
  </div>
</section>

<!-- EDITORIAL -->
<section class="max-w-7xl mx-auto px-4 py-12 md:py-16 overflow-hidden">
  <div class="grid lg:grid-cols-[auto_1fr] gap-8">
    <p class="tabular text-xs tracking-widest text-[#AB8868] whitespace-nowrap">03 · ABOUT THIS SERVICE</p>
    <div>
      <p class="text-xl md:text-2xl text-[#F9F9F9] leading-relaxed max-w-3xl">Your chauffeur on standby — <em class="font-display text-[#8a8a8a]">business, evenings out, weddings.</em> Book the hours, direct the evening, leave the waiting to us.</p>
      <div class="grid sm:grid-cols-2 gap-5 mt-8 max-w-3xl">
        <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
          <img src="https://images.unsplash.com/photo-1502877338535-766e1452684a?auto=format&fit=crop&w=800&q=60" alt="Luxury car at night" class="w-full h-56 md:h-64 object-cover" loading="lazy" onerror="this.style.display='none'">
        </div>
        <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
          <img src="https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=800&q=60" alt="Chauffeur driving at night" class="w-full h-56 md:h-64 object-cover" loading="lazy" onerror="this.style.display='none'">
        </div>
      </div>
    </div>
  </div>
  <p aria-hidden="true" class="font-display text-center leading-none text-transparent mt-4 select-none" style="font-size:clamp(2.5rem,10vw,8rem);-webkit-text-stroke:1px #2a2a2b">HOURLY</p>
</section>

<!-- FACTS + CTA -->
<section class="max-w-7xl mx-auto px-4 pb-12">
  <div class="grid sm:grid-cols-3 gap-4">
    <div class="card rounded-2xl p-5"><p class="font-display text-xl text-[#F9F9F9]">2-hour minimum</p><p class="text-xs text-[#AB8868] mt-1">Add more in half-hour steps.</p></div>
    <div class="card rounded-2xl p-5"><p class="font-display text-xl text-[#F9F9F9]">Your vehicle</p><p class="text-xs text-[#AB8868] mt-1">Sedan to Sprinter, priced per vehicle.</p></div>
    <div class="card rounded-2xl p-5"><p class="font-display text-xl text-[#F9F9F9]">Locked total</p><p class="text-xs text-[#AB8868] mt-1">Approved by you before payment.</p></div>
  </div>
  <a href="<?= url('services/booking.php?service=hourly') ?>" class="btn-gold rounded-full inline-block mt-6 text-sm px-8 py-3">Book hourly charter</a>
</section>
<?php
$content = ob_get_clean();
$pageTitle = 'Hourly Charter | Exotic Lane Limo';
$metaDesc = 'Hourly chauffeur charter with 2-hour minimum and per-vehicle additional-hour pricing.';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
