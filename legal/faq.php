<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/richtext.php';
$pdo = Database::pdo();
try { $body = $pdo->query('SELECT body FROM content WHERE slug = "faq" LIMIT 1')->fetch()['body'] ?? ''; } catch (Throwable) { $body = ''; }

/*
  The categories and questions the desk has edited live in faq_groups/faqs.
  The lists below are what this page has always shipped with, kept as the
  fallback: until someone saves a question the public page renders exactly what
  it rendered before, and if the tables ever go away the page does not go blank.
*/
$fallbackGroups = [
    'booking' => 'Booking',
    'pricing' => 'Pricing & Payment',
    'rides' => 'Rides',
    'account' => 'Account',
];
$fallbackFaqs = [
    ['g' => 'booking', 'icon' => 'pin', 'q' => 'How many stops can I add?', 'a' => 'Up to 6 additional stops on point-to-point rides. Add them while booking and each is priced transparently.'],
    ['g' => 'booking', 'icon' => 'clock', 'q' => 'What is the hourly minimum?', 'a' => '2 hours. Add more time in half-hour steps — never less than 2.'],
    ['g' => 'booking', 'icon' => 'edit', 'q' => 'Can I change my pickup time?', 'a' => 'Yes, until 2 hours before pickup — from your account or by contacting us. Changes inside the cutoff cannot be accepted.'],
    ['g' => 'booking', 'icon' => 'plane', 'q' => 'Do you need my flight number?', 'a' => 'No. Airport service is address-based: we drive airport to address or address to airport, with 60 minutes of free waiting.'],
    ['g' => 'pricing', 'icon' => 'tag', 'q' => 'How is my price calculated?', 'a' => 'Per-mile or hourly depending on the active pricing mode, plus any add-ons and tolls. The total is calculated on our servers and frozen before you pay — never from browser totals.'],
    ['g' => 'pricing', 'icon' => 'card', 'q' => 'How do I pay?', 'a' => 'Secure card payment through Stripe, a single-use secure payment link, or an arranged offline payment recorded by our team.'],
    ['g' => 'pricing', 'icon' => 'doc', 'q' => 'Do I get an invoice?', 'a' => 'Yes. Every booking produces an invoice you can view in your account and download as PDF.'],
    ['g' => 'pricing', 'icon' => 'coin', 'q' => 'What about waiting charges?', 'a' => 'Extra waiting is $15 per 10 minutes and is added to a pending invoice afterwards. We never silently auto-charge your card.'],
    ['g' => 'rides', 'icon' => 'time', 'q' => 'How much free waiting do I get?', 'a' => 'Airports 60 minutes, bus, train and cruise terminals 30 minutes, point-to-point 15 minutes.'],
    ['g' => 'rides', 'icon' => 'seat', 'q' => 'Do you provide child seats?', 'a' => 'Yes. Add a child seat or booster seat while booking and it will be in the car on arrival.'],
    ['g' => 'rides', 'icon' => 'car', 'q' => 'Will I get the exact car I booked?', 'a' => 'We confirm your vehicle class with dispatch. On rare occasions a substitution may occur — always like-for-like or better.'],
    ['g' => 'account', 'icon' => 'user', 'q' => 'Do I need an account to book?', 'a' => 'No. Guest checkout works for every service. An account lets you manage bookings, invoices and pickup changes in one place.'],
    ['g' => 'account', 'icon' => 'x', 'q' => 'How do cancellations work?', 'a' => 'Request cancellation from the cancellation page with your booking number and email. Paid bookings are refunded per our published policy.'],
    ['g' => 'account', 'icon' => 'chat', 'q' => 'How do I reach support?', 'a' => 'Use the contact page and our team will respond. For urgent ride-day issues, call the number in your confirmation email.'],
];
$icons = [
    'pin' => '<path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    'plane' => '<path d="M10 20l2-8 8-8-8 2-2 6-6 2 6 2 2 4z"/><circle cx="19" cy="5" r="1.5"/>',
    'tag' => '<path d="M20 12l-8 8-9-9V4h7z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
    'doc' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    'coin' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1 1.3-1.8 3-1.8s3 .8 3 1.8-1 1.6-3 2.2-3 1-3 2.2 1.3 1.9 3 1.9 3-.9 3-1.9"/>',
    'time' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'seat' => '<path d="M7 4v8h8l3 8"/><path d="M7 12h6"/><circle cx="17" cy="19" r="1.6"/><circle cx="9" cy="19" r="1.6"/>',
    'car' => '<path d="M5 12l1.5-4.5A2 2 0 0 1 8.4 6h7.2a2 2 0 0 1 1.9 1.5L19 12"/><rect x="3" y="12" width="18" height="6" rx="1.5"/><circle cx="8" cy="18.5" r="1.6"/><circle cx="16" cy="18.5" r="1.6"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"/>',
    'x' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
    'chat' => '<path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5z"/>',
];

/* Desk-edited rows win; the built-in lists stand in until there are any. */
$faqs = faq_questions($pdo);
if ($faqs === []) $faqs = $fallbackFaqs;

$groups = [];
foreach (faq_groups($pdo) as $g) {
    // A category with no live questions would render an empty tab, so it is
    // only shown when something is actually behind it.
    if (($g['n'] ?? 0) > 0 || !$faqs) $groups[$g['slug']] = $g['label'];
}
if ($groups === []) $groups = $fallbackGroups;

$copy = page_content($pdo, 'faq');
// page_content() hands back the field's default verbatim, and a link default is
// a label ("Contact us"), not a destination. Every other public page resolves it
// here too, without which href="Contact us" is not a link at all.
$linkUrl = url(PageContent::safe_link((string)($copy['faq_intro_link_url'] ?? ''), 'legal/contact.php'));
ob_start();
?>
<div class="max-w-3xl mx-auto px-4 py-12" x-data="{cat:<?= e(json_encode((string)array_key_first($groups), JSON_HEX_APOS | JSON_HEX_QUOT)) ?>}">
  <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9] text-center"><?= e((string)($copy['faq_heading'] ?? 'Frequently asked questions')) ?></h1>
  <p class="text-sm text-[#AB8868] mt-3 text-center"><?= nl2br(e((string)($copy['faq_intro'] ?? 'Everything about booking, pricing, rides and your account.'))) ?><br><?= e((string)($copy['faq_intro_link_lead'] ?? "Can't find what you're looking for?")) ?> <a class="underline text-[#F3D4A6]" href="<?= e($linkUrl) ?>"><?= e((string)($copy['faq_intro_link'] ?? 'Contact our team')) ?></a>.</p>
  <!-- Category pills -->
  <div class="flex flex-wrap justify-center gap-2 mt-6" role="tablist" aria-label="FAQ categories">
    <?php foreach ($groups as $gk => $gl): ?>
    <button type="button" role="tab" :aria-selected="cat === '<?= $gk ?>'" @click="cat = '<?= $gk ?>'"
      :class="cat === '<?= $gk ?>' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'"
      class="text-xs font-semibold px-5 py-2 rounded-full"><?= e($gl) ?></button>
    <?php endforeach; ?>
  </div>
  <!-- Questions -->
  <div class="mt-8">
    <?php $first = true; foreach ($faqs as $f): ?>
    <details class="hairline-b py-4 group" x-show="cat === '<?= $f['g'] ?>'" <?= $first ? 'open' : '' ?>>
      <summary class="flex items-center gap-4 cursor-pointer [&::-webkit-details-marker]:hidden">
        <span class="w-11 h-11 shrink-0 rounded-xl border border-[#3a3a3d] text-[#D9B978] inline-flex items-center justify-center" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$f['icon']] ?? $icons['chat'] ?></svg>
        </span>
        <span class="flex-1 text-sm md:text-base text-[#F9F9F9] font-semibold"><?= e($f['q']) ?></span>
        <span class="text-[#AB8868] group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">▾</span>
      </summary>
      <p class="text-sm text-[#AB8868] mt-2 pl-[60px] pr-6"><?= e($f['a']) ?></p>
    </details>
    <?php $first = false; endforeach; ?>
  </div>
  <?php if ($body): ?>
  <div class="card rounded-2xl p-6 mt-8 text-sm text-[#E5E5E3] space-y-2"><?= render_rich_text($body) ?></div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'FAQ | Exotic Lane Limo';
$seo = content_seo($pdo, 'faq');
$metaDesc = 'FAQs: booking, pricing and payment, rides, pickup changes, airport service, account help.';
$headerVariant = 'index';
// Every question on screen is real text, so the whole visible list is claimed
// as a FAQ block. A hidden row, or one whose icon we cannot draw, is still
// included - search engines are told what a rider can actually read on the
// page. Anything not in $faqs stays out rather than being invented.
$schemaNodes = array_values(array_filter([seo_faq_node($faqs)]));
require APP_ROOT . '/views/layouts/public.php';
