<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
ob_start();
?>
<!-- HERO PANEL -->
<section class="max-w-7xl mx-auto px-4 pt-6">
  <div class="relative overflow-hidden rounded-3xl bg-[#0F0F0E] border border-[#262628]">
    <img src="https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1600&q=60" alt="" aria-hidden="true" class="absolute inset-0 w-full h-full object-cover opacity-40" loading="lazy" decoding="async" onerror="this.style.display='none'">
    <div class="absolute inset-0 bg-gradient-to-r from-[#0A0A0C] via-[#0A0A0C]/70 to-transparent"></div>
    <div class="relative p-6 md:p-12">
      <h1 class="text-5xl md:text-7xl text-[#F9F9F9] font-semibold leading-tight">Airport runs,<br><em class="font-display font-medium text-[#F3D4A6]">without</em> the wait</h1>
      <a href="<?= url('services/booking.php?service=airport') ?>" class="btn-gold rounded-full inline-flex items-center gap-2 mt-8 text-sm px-7 py-3">Book airport transfer <span aria-hidden="true">→</span></a>
    </div>
    <div class="relative md:absolute md:right-8 md:bottom-8 md:w-64 bg-[#181819]/95 border border-[#2a2a2b] rounded-2xl p-5 m-6 md:m-0">
      <p class="tabular text-[10px] tracking-widest text-[#AB8868]">GOOD TO KNOW</p>
      <p class="font-display text-xl text-[#F9F9F9] mt-1">60 minutes on us</p>
      <p class="text-xs text-[#AB8868] mt-1">Free waiting on every airport pickup · meet &amp; greet available</p>
      <a href="<?= url('services/booking.php?service=airport') ?>" class="text-xs text-[#F3D4A6] underline mt-2 inline-block">Book now →</a>
    </div>
  </div>
</section>

<!-- EDITORIAL -->
<section class="max-w-7xl mx-auto px-4 py-12 md:py-16 overflow-hidden">
  <div class="grid lg:grid-cols-[auto_1fr] gap-8">
    <p class="tabular text-xs tracking-widest text-[#AB8868] whitespace-nowrap">02 · ABOUT THIS SERVICE</p>
    <div>
      <p class="text-xl md:text-2xl text-[#F9F9F9] leading-relaxed max-w-3xl">Airport ↔ customer address, in both directions — <em class="font-display text-[#8a8a8a]">no flight tracking, no required flight number.</em> Just tell us where and when, and your chauffeur handles the rest.</p>
      <div class="grid sm:grid-cols-2 gap-5 mt-8 max-w-3xl">
        <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
          <img src="https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?auto=format&fit=crop&w=800&q=60" alt="City skyline at night" class="w-full h-56 md:h-64 object-cover" loading="lazy" decoding="async" onerror="this.style.display='none'">
        </div>
        <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
          <img src="https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=format&fit=crop&w=800&q=60" alt="City streets at night" class="w-full h-56 md:h-64 object-cover" loading="lazy" decoding="async" onerror="this.style.display='none'">
        </div>
      </div>
    </div>
  </div>
  <p aria-hidden="true" class="font-display text-center leading-none text-transparent mt-4 select-none" style="font-size:clamp(2.5rem,10vw,8rem);-webkit-text-stroke:1px #2a2a2b">AIRPORT</p>
</section>

<!-- FACTS + CTA -->
<section class="max-w-7xl mx-auto px-4 pb-12">
  <div class="grid sm:grid-cols-3 gap-4">
    <div class="card rounded-2xl p-5"><p class="font-display text-xl text-[#F9F9F9]">Both directions</p><p class="text-xs text-[#AB8868] mt-1">To-airport and from-airport, same care.</p></div>
    <div class="card rounded-2xl p-5"><p class="font-display text-xl text-[#F9F9F9]">60 min waiting</p><p class="text-xs text-[#AB8868] mt-1">Free on every airport pickup.</p></div>
    <div class="card rounded-2xl p-5"><p class="font-display text-xl text-[#F9F9F9]">Locked total</p><p class="text-xs text-[#AB8868] mt-1">Approved by you before payment.</p></div>
  </div>
  <a href="<?= url('services/booking.php?service=airport') ?>" class="btn-gold rounded-full inline-block mt-6 text-sm px-8 py-3">Book airport transfer</a>
</section>
<?php
$content = ob_get_clean();
$pageTitle = 'Airport Transportation | Exotic Lane Limo';
$metaDesc = 'Airport to address and address to airport chauffeured service with 60 minutes free waiting.';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
