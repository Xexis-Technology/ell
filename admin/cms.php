<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pdo->prepare('UPDATE content SET title = ?, body = ?, meta_title = ?, meta_description = ?, updated_by = ? WHERE slug = ?')->execute([trim($_POST['title'] ?? ''), $_POST['body'] ?? '', trim($_POST['meta_title'] ?? ''), trim($_POST['meta_description'] ?? ''), (int)$admin['id'], $_POST['slug'] ?? '']);
    audit($pdo, 'admin', (int)$admin['id'], 'cms.updated', 'content', null, ['slug' => $_POST['slug'] ?? '']);
    $msg = 'Content saved.';
    header('Location: ' . url('admin/cms.php?msg=' . urlencode($msg) . '&slug=' . urlencode($_POST['slug'] ?? '')));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$pages = $pdo->query('SELECT slug, title FROM content ORDER BY slug')->fetchAll();
$slug = $_GET['slug'] ?? ($pages[0]['slug'] ?? 'terms');
$st = $pdo->prepare('SELECT * FROM content WHERE slug = ? LIMIT 1');
$st->execute([$slug]);
$page = $st->fetch();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">CMS</h1>
<p class="text-sm"><?php foreach ($pages as $p): ?><a class="underline" href="<?= url('admin/cms.php?slug=' . $p['slug']) ?>"><?= e($p['slug']) ?></a> · <?php endforeach; ?></p>
<?php if ($page): ?>
<form method="post" class="card p-4 mt-3 space-y-3"><?= csrf_field() ?><input type="hidden" name="slug" value="<?= e($page['slug']) ?>">
  <div><label class="label" for="title">Title</label><input id="title" name="title" class="input" value="<?= e((string)($page['title'] ?? '')) ?>"></div>
  <div><label class="label" for="body">Body (HTML allowed)</label><textarea id="body" name="body" class="input" rows="12"><?= e((string)($page['body'] ?? '')) ?></textarea></div>
  <div class="grid md:grid-cols-2 gap-3">
    <div><label class="label" for="meta_title">Meta title</label><input id="meta_title" name="meta_title" class="input" value="<?= e((string)($page['meta_title'] ?? '')) ?>"></div>
    <div><label class="label" for="meta_description">Meta description</label><input id="meta_description" name="meta_description" class="input" value="<?= e((string)($page['meta_description'] ?? '')) ?>"></div>
  </div>
  <button class="btn-gold">Save</button></form>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'CMS | Admin';
$navActive = 'cms.php';
require APP_ROOT . '/views/layouts/admin.php';
