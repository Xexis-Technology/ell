<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$action = $_GET['action'] ?? 'list';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($op === 'save') {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            $msg = 'Title is required.';
        } else {
            $rawSlug = trim($_POST['slug'] ?? '');
            $slug = $rawSlug !== '' ? slugify($rawSlug) : slugify($title);
            // keep stable unique slug
            $exists = $pdo->prepare('SELECT id FROM blog_posts WHERE slug = ? AND id != ? LIMIT 1');
            $exists->execute([$slug, $id]);
            if ($exists->fetch()) $slug = unique_vehicle_slug_fallback($pdo, $slug, $id);
            $pubAt = trim($_POST['published_at'] ?? '');
            $pubAt = $pubAt !== '' ? str_replace('T', ' ', $pubAt) . ':00' : null;
            $data = [$slug, $title, trim($_POST['excerpt'] ?? '') ?: null, $_POST['body'] ?? null, trim($_POST['cover'] ?? '') ?: null, trim($_POST['meta_title'] ?? '') ?: null, trim($_POST['meta_description'] ?? '') ?: null, in_array($_POST['status'] ?? '', ['draft','published'], true) ? $_POST['status'] : 'draft', $pubAt];
            if ($id) {
                $pdo->prepare('UPDATE blog_posts SET slug=?, title=?, excerpt=?, body=?, cover=?, meta_title=?, meta_description=?, status=?, published_at=? WHERE id=?')->execute([...$data, $id]);
                audit($pdo, 'admin', (int)$admin['id'], 'blog.updated', 'blog', $id, null);
                $msg = 'Post updated.';
            } else {
                $pdo->prepare('INSERT INTO blog_posts (slug, title, excerpt, body, cover, meta_title, meta_description, status, published_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute($data);
                $nid = (int)$pdo->lastInsertId();
                audit($pdo, 'admin', (int)$admin['id'], 'blog.created', 'blog', $nid, null);
                $msg = 'Post created.';
            }
        }
    } elseif ($op === 'delete' && $id) {
        $pdo->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$id]);
        audit($pdo, 'admin', (int)$admin['id'], 'blog.deleted', 'blog', $id, null);
        $msg = 'Post deleted.';
    }
    header('Location: ' . url('admin/blog.php?msg=' . urlencode($msg) . ($action === 'edit' ? '&action=edit&id=' . $id : '')));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];

// slug uniqueness helper for blogs (mirrors vehicle logic)
function unique_vehicle_slug_fallback(PDO $pdo, string $base, int $excludeId): string
{
    $slug = $base;
    $i = 2;
    while (true) {
        $st = $pdo->prepare('SELECT 1 FROM blog_posts WHERE slug = ? AND id != ? LIMIT 1');
        $st->execute([$slug, $excludeId]);
        if (!$st->fetch()) return $slug;
        $slug = $base . '-' . ($i++);
    }
}

ob_start();
if ($action === 'edit') {
    $edit = null;
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ? LIMIT 1');
        $st->execute([(int)$_GET['id']]);
        $edit = $st->fetch();
    }
    ?>
    <?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
    <h1 class="font-display text-3xl"><?= $edit ? 'Edit post' : 'New post' ?></h1>
    <form method="post" class="card p-4 mt-3 space-y-3"><?= csrf_field() ?>
      <input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div><label class="label" for="title">Title</label><input id="title" name="title" class="input" required value="<?= e((string)($edit['title'] ?? '')) ?>"></div>
      <div class="grid md:grid-cols-2 gap-3">
        <div><label class="label" for="slug">Slug (auto from title if empty)</label><input id="slug" name="slug" class="input" placeholder="my-post" value="<?= e((string)($edit['slug'] ?? '')) ?>"></div>
        <div><label class="label" for="status">Status</label><select id="status" name="status" class="input"><option value="draft" <?= ($edit['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>draft</option><option value="published" <?= ($edit['status'] ?? '') === 'published' ? 'selected' : '' ?>>published</option></select></div>
      </div>
      <div><label class="label" for="excerpt">Excerpt</label><input id="excerpt" name="excerpt" class="input" placeholder="One-line summary" value="<?= e((string)($edit['excerpt'] ?? '')) ?>"></div>
      <div><label class="label" for="body">Body (HTML allowed)</label><textarea id="body" name="body" class="input" rows="12" placeholder="<p>Story…</p>"><?= e((string)($edit['body'] ?? '')) ?></textarea></div>
      <div><label class="label" for="cover">Cover (Unsplash photo id)</label><input id="cover" name="cover" class="input" placeholder="photo-… " value="<?= e((string)($edit['cover'] ?? '')) ?>"></div>
      <div class="grid md:grid-cols-2 gap-3">
        <div><label class="label" for="meta_title">Meta title</label><input id="meta_title" name="meta_title" class="input" value="<?= e((string)($edit['meta_title'] ?? '')) ?>"></div>
        <div><label class="label" for="meta_description">Meta description</label><input id="meta_description" name="meta_description" class="input" value="<?= e((string)($edit['meta_description'] ?? '')) ?>"></div>
      </div>
      <div><label class="label" for="published_at">Publish date (empty = now on publish)</label><input id="published_at" name="published_at" type="datetime-local" class="input" value="<?= e(isset($edit['published_at']) && $edit['published_at'] ? date('Y-m-d\TH:i', strtotime($edit['published_at'])) : '') ?>"></div>
      <button class="btn-gold">Save post</button></form>
    <?php
} else {
    $rows = $pdo->query('SELECT * FROM blog_posts ORDER BY id DESC LIMIT 200')->fetchAll();
    ?>
    <?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
    <h1 class="font-display text-3xl">Blog <a href="<?= url('admin/blog.php?action=edit') ?>" class="btn-gold text-base align-middle">+ New post</a></h1>
    <div class="table-wrap card mt-3"><table class="data"><thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Published</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= e($r['title']) ?></td><td class="text-xs"><?= e($r['slug']) ?></td><td><?= status_pill($r['status']) ?></td><td><?= e((string)($r['published_at'] ?? '')) ?></td>
    <td class="whitespace-nowrap"><a class="underline" href="<?= url('admin/blog.php?action=edit&id=' . (int)$r['id']) ?>">Edit</a>
    <?php if ($r['status'] === 'published'): ?><a class="underline" href="<?= url('services/blogs/read.php?slug=' . urlencode($r['slug'])) ?>">View</a><?php endif; ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this post?')"><?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-danger-outline">Delete</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php
}
$content = ob_get_clean();
$pageTitle = 'Blog | Admin';
$navActive = 'blog.php';
require APP_ROOT . '/views/layouts/admin.php';
