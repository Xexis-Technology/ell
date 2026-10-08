<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
$pdo = Database::pdo();
$posts = $pdo->query("SELECT slug, title, excerpt, cover, published_at FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC, id DESC")->fetchAll();
ob_start();
?>
<div class="max-w-7xl mx-auto px-4 py-10 md:py-14">
  <p class="eyebrow">Journal</p>
  <div class="flex flex-wrap items-end justify-between gap-4 mt-2">
    <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9]">All blogs</h1>
    <a href="<?= url('services/booking.php') ?>" class="btn-gold rounded-full text-sm px-6 py-2.5">Book your ride</a>
  </div>
  <?php if (!$posts): ?>
    <p class="text-sm text-[#AB8868] mt-8">No stories yet — check back soon.</p>
  <?php else: ?>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-8">
    <?php foreach ($posts as $p): ?>
    <a href="<?= url('services/blogs/read.php?slug=' . urlencode($p['slug'])) ?>" class="group flex flex-col rounded-2xl overflow-hidden border border-[#2a2a2b] hover:border-[#C8A96B]">
      <div class="h-48 bg-gradient-to-br from-[#0A0A0C] to-[#AB8868] overflow-hidden">
<?php $cu = cover_url($p['cover'], 800); if ($cu !== ''): ?>
   <img src="<?= e($cu) ?>" alt="<?= e($p['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" decoding="async" width="800" height="192" onerror="this.style.display='none'">
        <?php endif; ?>
      </div>
      <div class="p-5 flex flex-col flex-1">
        <p class="tabular text-[11px] tracking-widest text-[#AB8868]"><?= e(date('M j, Y', strtotime($p['published_at'] ?? $p['created_at'] ?? 'now'))) ?></p>
        <h2 class="font-display text-xl text-[#F9F9F9] mt-1"><?= e($p['title']) ?></h2>
        <?php if (!empty($p['excerpt'])): ?><p class="text-xs text-[#AB8868] mt-2 flex-1"><?= e($p['excerpt']) ?></p><?php endif; ?>
        <span class="mt-4 inline-block text-center btn-gold rounded-full text-xs font-semibold px-5 py-2.5">Read full story</span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Blog | Exotic Lane Limo';
$metaDesc = 'Chauffeur guides and stories: airport pickups, hourly charters, group transport.';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
