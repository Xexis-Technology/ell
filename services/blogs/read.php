<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
$pdo = Database::pdo();
$slug = slugify(trim((string)($_GET['slug'] ?? '')));
$st = $pdo->prepare("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' LIMIT 1");
$st->execute([$slug]);
$post = $st->fetch();
if (!$post) {
    http_response_code(404);
    $GLOBALS['__site_url'] = SITE_URL;
    require APP_ROOT . '/views/errors/404.php';
    exit;
}
$others = $pdo->prepare("SELECT slug, title, cover, published_at FROM blog_posts WHERE status = 'published' AND id != ? ORDER BY published_at DESC, id DESC LIMIT 3");
$others->execute([(int)$post['id']]);
$related = $others->fetchAll();
ob_start();
?>
<div class="max-w-6xl mx-auto px-4 py-10">
  <p class="text-xs"><a class="underline text-[#AB8868]" href="<?= url('services/blogs/index.php') ?>">Blogs</a> <span class="text-[#AB8868]">/</span> <span class="text-[#F5F5F3]"><?= e($post['title']) ?></span></p>
  <div class="grid lg:grid-cols-[1fr_320px] gap-8 mt-4 items-start">
    <article class="min-w-0">
      <p class="eyebrow">Journal</p>
      <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9] mt-2"><?= e($post['title']) ?></h1>
      <p class="tabular text-xs tracking-widest text-[#AB8868] mt-3"><?= e(date('F j, Y', strtotime($post['published_at'] ?? $post['created_at']))) ?> · EXOTIC LANE LIMO</p>
      <?php if (!empty($post['cover'])): ?>
      <div class="rounded-2xl overflow-hidden mt-6 bg-gradient-to-br from-[#181819] to-[#AB8868]">
        <img src="https://images.unsplash.com/<?= e($post['cover']) ?>?auto=format&fit=crop&w=1200&q=60" alt="<?= e($post['title']) ?>" class="w-full h-64 md:h-96 object-cover" onerror="this.style.display='none'">
      </div>
      <?php endif; ?>
      <div class="mt-6 text-[15px] leading-relaxed text-[#E5E5E3] space-y-4 blog-body"><?= $post['body'] ?></div>
      <div class="mt-8">
        <a href="<?= url('services/booking.php') ?>" class="btn-gold rounded-full text-sm px-8 py-3">Book your ride</a>
      </div>
    </article>
    <?php if ($related): ?>
    <aside class="lg:sticky lg:top-20" aria-label="More blogs">
      <p class="tabular text-xs tracking-widest text-[#D9B978]">MORE BLOGS</p>
      <div class="mt-3 space-y-3">
        <?php foreach ($related as $r): ?>
        <a href="<?= url('services/blogs/read.php?slug=' . urlencode($r['slug'])) ?>" class="group flex items-center gap-3 rounded-2xl border border-[#2a2a2b] hover:border-[#C8A96B] p-3">
          <?php if (!empty($r['cover'])): ?>
          <span class="w-20 h-16 shrink-0 rounded-xl overflow-hidden bg-gradient-to-br from-[#0A0A0C] to-[#AB8868]">
            <img src="https://images.unsplash.com/<?= e($r['cover']) ?>?auto=format&fit=crop&w=400&q=60" alt="<?= e($r['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" onerror="this.style.display='none'">
          </span>
          <?php endif; ?>
          <span class="font-display text-base text-[#F3D4A6]"><?= e($r['title']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </aside>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = ($post['meta_title'] ?: $post['title'] . ' | Exotic Lane Limo');
$metaDesc = $post['meta_description'] ?: ($post['excerpt'] ?? '');
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
