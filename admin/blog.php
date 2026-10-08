<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/richtext.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$action = $_GET['action'] ?? 'list';
$msg = '';
$isErr = false;

// Slug collisions get a numeric suffix rather than silently overwriting.
function unique_blog_slug(PDO $pdo, string $base, int $excludeId): string
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

/** Plain-text word count from stored HTML, for the reading-time figure. */
function blog_words(?string $html): int
{
    $text = trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($text === '') return 0;
    return count(preg_split('/\s+/u', $text) ?: []);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($op === 'save') {
        $title = trim($_POST['title'] ?? '');
        $body = trim((string)($_POST['body'] ?? ''));
        if ($title === '') {
            $msg = 'Give the post a title before saving.';
            $isErr = true;
        } elseif ($id && !$pdo->query('SELECT 1 FROM blog_posts WHERE id = ' . $id)->fetchColumn()) {
            $msg = 'That post is no longer on file.';
            $isErr = true;
        } else {
            $rawSlug = trim($_POST['slug'] ?? '');
            $slug = slugify($rawSlug !== '' ? $rawSlug : $title);
            if ($slug === '') {
                $msg = 'That title and slug leave nothing readable. Add a word or two.';
                $isErr = true;
            } else {
                $chk = $pdo->prepare('SELECT id FROM blog_posts WHERE slug = ? AND id != ? LIMIT 1');
                $chk->execute([$slug, $id]);
                if ($chk->fetch()) $slug = unique_blog_slug($pdo, $slug, $id);

                $pubAt = trim($_POST['published_at'] ?? '');
                $pubAt = $pubAt !== '' ? date('Y-m-d H:i:s', strtotime($pubAt)) : null;
                $status = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
                // Publishing without a date means now, so the public index orders it correctly.
                if ($status === 'published' && $pubAt === null) $pubAt = date('Y-m-d H:i:s');

                // Cover: an uploaded file wins. An existing photo id is kept
                // unless a new file arrives, so editing a post never wipes it.
                $cover = trim($_POST['cover'] ?? '');
                $uploadErr = null;
                if (!empty($_FILES['cover_file']['name'])) {
                    [$stored, $uploadErr] = secure_upload($_FILES['cover_file'], 'blog', ['jpg', 'jpeg', 'png', 'webp'], 5242880);
                    if ($stored !== null) {
                        // Do not leave the previous upload behind.
                        if ($cover !== '' && preg_match('#^storage/uploads/blog/#', $cover)) @unlink(APP_ROOT . '/' . $cover);
                        $cover = $stored;
                    }
                }
                if ($uploadErr !== null) {
                    $msg = 'Cover not saved: ' . $uploadErr;
                    $isErr = true;
                }

                if (!$isErr) {
                    $data = [$slug, $title, trim($_POST['excerpt'] ?? '') ?: null, clean_html($body) ?: null,
                        $cover ?: null, trim($_POST['meta_title'] ?? '') ?: null,
                        trim($_POST['meta_description'] ?? '') ?: null,
                        trim($_POST['meta_keywords'] ?? '') ?: null, $status, $pubAt];

                    if ($id) {
                        $pdo->prepare('UPDATE blog_posts SET slug=?, title=?, excerpt=?, body=?, cover=?, meta_title=?, meta_description=?, meta_keywords=?, status=?, published_at=? WHERE id=?')
                            ->execute([...$data, $id]);
                        audit($pdo, 'admin', (int)$admin['id'], 'blog.updated', 'blog', $id, null);
                        $msg = 'Saved ' . $title . '.';
                    } else {
                        $pdo->prepare('INSERT INTO blog_posts (slug, title, excerpt, body, cover, meta_title, meta_description, meta_keywords, status, published_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                            ->execute($data);
                        $id = (int)$pdo->lastInsertId();
                        audit($pdo, 'admin', (int)$admin['id'], 'blog.created', 'blog', $id, null);
                        $msg = 'Created ' . $title . '.';
                    }
                }
            }
        }
    } elseif ($op === 'delete' && $id) {
        $st = $pdo->prepare('SELECT title, cover FROM blog_posts WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $gone = $st->fetch();
        if ($gone === false) {
            $msg = 'That post is no longer on file.';
            $isErr = true;
        } else {
            $pdo->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$id]);
            // An uploaded cover belongs to this post; a stock photo id does not.
            if (preg_match('#^storage/uploads/blog/#', (string)$gone['cover'])) @unlink(APP_ROOT . '/' . $gone['cover']);
            audit($pdo, 'admin', (int)$admin['id'], 'blog.deleted', 'blog', $id, ['title' => $gone['title']]);
            $msg = 'Deleted ' . $gone['title'] . '.';
            $action = 'list';
        }
    }

    $back = $action === 'edit' && $id
        ? ['action' => 'edit', 'id' => $id]
        : ($action === 'edit' ? ['action' => 'edit'] : []);
    header('Location: ' . url('admin/blog.php?' . http_build_query($back + ['msg' => $msg, 'err' => $isErr ? 1 : null])));
    exit;
}

if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$isErr = isset($_GET['err']);

$statusFilter = (string)($_GET['st'] ?? '');
if (!in_array($statusFilter, ['draft', 'published'], true)) $statusFilter = '';
$q = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT id, slug, title, excerpt, body, cover, meta_title, meta_description, status, published_at, created_at, updated_at FROM blog_posts';
$where = [];
$params = [];
if ($statusFilter !== '') { $where[] = 'status = ?'; $params[] = $statusFilter; }
if ($q !== '') { $where[] = '(title LIKE ? OR slug LIKE ? OR excerpt LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$st = $pdo->query('SELECT status, COUNT(*) c, COALESCE(SUM(CHAR_LENGTH(COALESCE(body,""))),0) chars FROM blog_posts GROUP BY status');
$byStatus = [];
$total = 0;
foreach ($st->fetchAll() as $s) {
    $byStatus[$s['status']] = (int)$s['c'];
    $total += (int)$s['c'];
}

$editing = null;
if ($action === 'edit') {
    $editId = (int)($_GET['id'] ?? 0);
    if ($editId > 0) {
        $st = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ? LIMIT 1');
        $st->execute([$editId]);
        $editing = $st->fetch() ?: null;
    }
}

ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="bl-head">
  <div>
    <p class="eyebrow">Content</p>
    <h1 class="font-display">Blog</h1>
    <p class="bl-sub">What the public reads. Write it, see it as they will, then publish.</p>
  </div>
  <p class="bl-figures">
    <span><b><?= $total ?></b> <?= $total === 1 ? 'post' : 'posts' ?></span>
    <?php if (($byStatus['published'] ?? 0) > 0): ?><span><b><?= $byStatus['published'] ?></b> live</span><?php endif; ?>
    <?php if (($byStatus['draft'] ?? 0) > 0): ?><span><b><?= $byStatus['draft'] ?></b> in draft</span><?php endif; ?>
  </p>
</header>

<nav class="bl-filters" aria-label="Filter posts">
  <form method="get" class="bl-search" action="<?= url('admin/blog.php') ?>">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Title, slug or excerpt" aria-label="Search posts">
    <?php if ($statusFilter): ?><input type="hidden" name="st" value="<?= e($statusFilter) ?>"><?php endif; ?>
    <button type="submit">Search</button>
  </form>
  <div class="bl-chips">
    <?php foreach (['' => 'All', 'published' => 'Live', 'draft' => 'Draft'] as $key => $label):
      $n = $key === '' ? $total : ($byStatus[$key] ?? 0); ?>
    <a class="bl-chip<?= $statusFilter === $key ? ' is-on' : '' ?>"
       href="<?= url('admin/blog.php?' . http_build_query(array_filter(['st' => $key, 'q' => $q]))) ?>"
       <?= $statusFilter === $key ? 'aria-current="true"' : '' ?>><?= e($label) ?><b><?= $n ?></b></a>
    <?php endforeach; ?>
    <a class="bl-new" href="<?= url('admin/blog.php?action=edit') ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> New post</a>
  </div>
</nav>

<?php if (!$rows): ?>
  <div class="bl-empty-wrap">
    <p class="bl-blank"><?= $q !== '' || $statusFilter !== '' ? 'No post matches that.' : 'No posts yet.' ?></p>
    <p class="bl-blank-sub">
      <?php if ($q !== ''): ?>
        Try part of a title or the words you remember.
      <?php elseif ($statusFilter !== ''): ?>
        Nothing in <?= $statusFilter === 'draft' ? 'draft' : 'live posts' ?> right now. <a href="<?= url('admin/blog.php') ?>">Show every post</a>.
      <?php else: ?>
        Write the first one. <a href="<?= url('admin/blog.php?action=edit') ?>">Start a post</a>.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <ul class="bl-cards">
    <?php foreach ($rows as $r):
      $words = blog_words($r['body']);
      $live = $r['status'] === 'published';
      $when = $r['published_at'] ?: $r['created_at'];
      $thumb = cover_url($r['cover'], 600); ?>
    <li>
      <a class="bl-card<?= $live ? ' is-live' : '' ?>" href="<?= url('admin/blog.php?action=edit&id=' . (int)$r['id']) ?>">
        <span class="bl-thumb<?= $thumb ? '' : ' is-empty' ?>">
          <?php if ($thumb): ?>
            <img src="<?= e($thumb) ?>" alt="" loading="lazy" decoding="async"
                 onerror="this.closest('.bl-thumb').classList.add('is-broken');this.remove()">
            <span class="bl-thumb-bad"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> cover did not load</span>
          <?php else: ?>
            <span class="bl-thumb-none"><i class="fa-solid fa-image" aria-hidden="true"></i> no cover</span>
          <?php endif; ?>
        </span>
        <span class="bl-card-body">
          <span class="bl-card-title"><?= e($r['title']) ?></span>
          <?php if ($r['excerpt']): ?><span class="bl-card-ex"><?= e($r['excerpt']) ?></span><?php endif; ?>
        </span>
        <span class="bl-card-foot">
          <span class="bl-stage bl-stage-<?= e((string)$r['status']) ?>"><?= $live ? 'Live' : 'Draft' ?></span>
          <span class="bl-count"><?= $words ?> <?= $words === 1 ? 'word' : 'words' ?></span>
          <span class="bl-date"><?= e(date('j M Y', strtotime((string)$when))) ?></span>
        </span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php
if ($action !== 'edit') {
    $content = ob_get_clean();
    $pageTitle = 'Blog | Admin';
    $navActive = 'blog.php';
    require APP_ROOT . '/views/layouts/admin.php';
    return;
}

$e = $editing ?? [];
$eId = (int)($e['id'] ?? 0);
$eWords = blog_words($e['body'] ?? null);
$eMinutes = $eWords > 0 ? max(1, (int)ceil($eWords / 200)) : 0;
$eCover = (string)($e['cover'] ?? '');
$eCoverFile = $eCover !== '' ? cover_url($eCover, 600) : '';
$eCoverLocal = $eCover !== '' && preg_match('#^storage/uploads/#i', $eCover);
$eSlug = (string)($e['slug'] ?? '');
$eLive = ($e['status'] ?? '') === 'published';
$publicUrl = $eSlug !== '' ? url('services/blogs/read.php?slug=' . urlencode($eSlug)) : '';
$siteHost = preg_replace('#^https?://#', '', (string)parse_url((string)($_SERVER['HTTP_HOST'] ?? 'exoticlane.example'), PHP_URL_HOST)) ?: 'exoticlane.example';
?>
<dialog class="modal is-wide bl-dlg" id="bl-dlg" data-autopen aria-labelledby="bl-dlg-t">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="bl-dlg-t"><?= $eId ? 'Edit post' : 'New post' ?></h2>
      <p class="md-sub"><?= $eId ? e($e['slug'] ?? '') : 'Nothing saved yet â€” it appears in the list once you save.' ?></p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>

  <div class="md-body bl-body">
    <form method="post" enctype="multipart/form-data" class="bl-write" id="bl-form">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= $eId ?>">

      <div class="bl-field">
        <label class="bl-label" for="bl-title">Title</label>
        <input id="bl-title" name="title" class="input bl-input-title" required placeholder="What this post is about"
               value="<?= e((string)($e['title'] ?? '')) ?>">
      </div>

      <div class="bl-pair">
        <div class="bl-field">
          <label class="bl-label" for="bl-slug">Slug</label>
          <input id="bl-slug" name="slug" class="input" placeholder="from the title" value="<?= e($eSlug) ?>">
        </div>
        <div class="bl-field">
          <label class="bl-label" for="bl-status">Status</label>
          <select id="bl-status" name="status" class="input">
            <option value="draft" <?= ($e['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= ($e['status'] ?? '') === 'published' ? 'selected' : '' ?>>Live on the site</option>
          </select>
        </div>
      </div>

      <div class="bl-field">
        <label class="bl-label" for="bl-excerpt">Excerpt <span class="bl-label-why">the one-line summary on the blog index</span></label>
        <input id="bl-excerpt" name="excerpt" class="input" maxlength="255" placeholder="What a reader gets before they click"
               value="<?= e((string)($e['excerpt'] ?? '')) ?>">
      </div>

      <div class="bl-field">
        <span class="bl-label">Body</span>
        <textarea class="ql-host bl-body-input" name="body" data-ql-preset="article" placeholder="Write the post. Use the toolbar for headings and lists."><?= e((string)($e['body'] ?? '')) ?></textarea>
      </div>

      <div class="bl-field">
        <span class="bl-label">Cover</span>
        <div class="bl-cover">
          <label class="bl-cover-drop" for="bl-cover-file">
            <i class="fa-solid fa-camera" aria-hidden="true"></i>
            <span><?= $eCoverFile ? 'Choose a different image' : 'Choose an image' ?></span>
            <small>JPG, PNG or WebP &middot; up to 5MB &middot; a wide image looks best</small>
          </label>
          <input id="bl-cover-file" class="bl-cover-input" type="file" name="cover_file" accept="image/jpeg,image/png,image/webp">
          <?php if ($eCoverFile): ?>
            <img class="bl-cover-now" src="<?= e($eCoverFile) ?>" alt="" onerror="this.remove()" decoding="async">
            <button type="button" class="bl-cover-clear" data-clear-cover><i class="fa-solid fa-trash" aria-hidden="true"></i> Remove image</button>
          <?php else: ?>
            <p class="bl-cover-none">No image on this post. The cover is the first thing a reader sees.</p>
          <?php endif; ?>
        </div>
        <?php /* Kept in the form so an existing cover survives an edit untouched. */ ?>
        <input type="hidden" name="cover" id="bl-cover-hidden" value="<?= e($eCover) ?>">
      </div>

      <div class="bl-field">
        <label class="bl-label" for="bl-meta-title">Meta title</label>
        <input id="bl-meta-title" name="meta_title" class="input" placeholder="defaults to the title"
               value="<?= e((string)($e['meta_title'] ?? '')) ?>">
      </div>

      <div class="bl-field">
        <label class="bl-label" for="bl-meta-desc">Meta description</label>
        <input id="bl-meta-desc" name="meta_description" class="input" maxlength="255"
               value="<?= e((string)($e['meta_description'] ?? '')) ?>">
      </div>

      <div class="bl-field">
        <label class="bl-label" for="bl-meta-kw">Meta keywords <span class="bl-label-why">comma separated, for this post only</span></label>
        <input id="bl-meta-kw" name="meta_keywords" class="input" maxlength="255" placeholder="airport transfer, ORD, luggage, waiting time"
               value="<?= e((string)($e['meta_keywords'] ?? '')) ?>">
      </div>

      <div class="bl-field">
        <label class="bl-label" for="bl-pub">Publish date <span class="bl-label-why">empty means now, when you set it live</span></label>
        <input id="bl-pub" name="published_at" type="datetime-local" class="input"
               value="<?= !empty($e['published_at']) ? e(date('Y-m-d\TH:i', strtotime((string)$e['published_at']))) : '' ?>">
      </div>

      <div class="bl-acts">
        <button class="bl-save">Save post</button>
        <span class="bl-reading"><?= $eWords ?> <?= $eWords === 1 ? 'word' : 'words' ?><?= $eMinutes ? ' Â· about ' . $eMinutes . ' min read' : '' ?></span>
      </div>
    </form>

    <aside class="bl-see" aria-label="How this will look">
      <p class="bl-see-k">As readers see it</p>

      <div class="bl-see-cover<?= $eCoverFile ? '' : ' is-empty' ?>">
        <?php if ($eCoverFile): ?>
          <img src="<?= e($eCoverFile) ?>" alt="" loading="lazy" decoding="async"
               onerror="this.closest('.bl-see-cover').classList.add('is-broken');this.remove()">
          <span class="bl-see-broken" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> That image does not load. The public page would show no image at all.</span>
        <?php else: ?>
          <span class="bl-see-cover-none">No cover set</span>
          <span class="bl-see-broken" hidden></span>
        <?php endif; ?>
      </div>

      <article class="bl-see-post">
        <p class="bl-see-date" id="bl-pv-date"><?= !empty($e['published_at']) ? e(date('F j, Y', strtotime((string)$e['published_at']))) : 'Not dated yet' ?></p>
        <h3 class="bl-see-title" id="bl-pv-title"><?= e((string)($e['title'] ?? '')) ?: 'Untitled post' ?></h3>
        <div class="bl-see-body blog-body" id="bl-pv-body"><?= render_rich_text($e['body'] ?? '') ?></div>
      </article>

      <div class="bl-serp">
        <p class="bl-see-k">Search result <?= $eLive && $publicUrl ? '<span class="bl-see-k-why">&mdash; click to open the live page</span>' : '' ?></p>
        <?php if ($eLive && $publicUrl): ?>
        <a class="bl-serp-card" id="bl-serp" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">
          <span class="bl-serp-url"><?= e($siteHost) ?> &rsaquo; blog</span>
          <span class="bl-serp-title" id="bl-pv-meta-title"><?= e((string)($e['meta_title'] ?? $e['title'] ?? '')) ?: 'Untitled post' ?></span>
          <span class="bl-serp-desc" id="bl-pv-meta-desc"><?= e((string)($e['meta_description'] ?? $e['excerpt'] ?? '')) ?: 'No meta description â€” search engines will pick their own words.' ?></span>
          <span class="bl-serp-kw" id="bl-pv-meta-kw"><?php if (!empty($e['meta_keywords'])): ?>
            <i class="fa-solid fa-tags" aria-hidden="true"></i><?= e((string)$e['meta_keywords']) ?>
          <?php else: ?>
            <i class="fa-solid fa-tag" aria-hidden="true"></i>No keywords on this post
          <?php endif; ?></span>
        </a>
        <?php else: ?>
        <div class="bl-serp-card" id="bl-serp">
          <span class="bl-serp-url"><?= e($siteHost) ?> &rsaquo; blog</span>
          <span class="bl-serp-title" id="bl-pv-meta-title"><?= e((string)($e['meta_title'] ?? $e['title'] ?? '')) ?: 'Untitled post' ?></span>
          <span class="bl-serp-desc" id="bl-pv-meta-desc"><?= e((string)($e['meta_description'] ?? $e['excerpt'] ?? '')) ?: 'No meta description â€” search engines will pick their own words.' ?></span>
          <span class="bl-serp-kw" id="bl-pv-meta-kw"><i class="fa-solid fa-tag" aria-hidden="true"></i>Not live, so nothing to open yet</span>
        </div>
        <?php endif; ?>
      </div>
    </aside>
  </div>

  <div class="md-foot bl-foot">
    <?php if ($eId): ?>
      <span class="md-foot-note">
        <?= ($e['status'] ?? '') === 'published'
            ? 'Live at <a href="' . url('services/blogs/read.php?slug=' . urlencode($eSlug)) . '" target="_blank" rel="noopener">exoticlane.com/blog/' . e($eSlug) . '</a>'
            : 'Not on the site yet.' ?>
      </span>
      <?php // Its own form: reusing the save form would submit op=save and op=delete together. ?>
      <form method="post" class="bl-del-form" data-confirm="Delete &ldquo;<?= e((string)($e['title'] ?? 'this post')) ?>&rdquo;? It comes off the site straight away.">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="delete">
        <input type="hidden" name="id" value="<?= $eId ?>">
        <button class="bl-del">Delete post</button>
      </form>
    <?php else: ?>
      <span class="md-foot-note">Save to add this post to the list.</span>
    <?php endif; ?>
  </div>
</dialog>
<noscript><style>#bl-dlg{display:block;position:static;max-width:none;margin:1rem auto}</style></noscript>
<script>
(function () {
  var d = document.getElementById('bl-dlg');
  if (!d) return;
  d.addEventListener('close', function () {
    var u = new URL(location.href);
    u.searchParams.delete('action'); u.searchParams.delete('id');
    history.replaceState(null, '', u.pathname + (u.search ? u.search : ''));
  });

  var f = document.getElementById('bl-form');
  if (!f) return;
  var q = function (id) { return document.getElementById(id); };

  // ---- cover: upload, not a link ----
  var fileInput = q('bl-cover-file');
  var coverHidden = q('bl-cover-hidden');
  var clearBtn = d.querySelector('[data-clear-cover]');
  var box = d.querySelector('.bl-see-cover');
  var brokenMsg = '<span class="bl-see-broken" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> That image does not load. The public page would show no image at all.</span>';

  function setPreview(src) {
    if (!src) {
      box.classList.add('is-empty'); box.classList.remove('is-broken');
      box.innerHTML = '<span class="bl-see-cover-none">No cover set</span>' + brokenMsg;
      return;
    }
    box.classList.remove('is-empty', 'is-broken');
    box.innerHTML = '<img alt="" decoding="async" src="' + src + '">' + brokenMsg;
    box.querySelector('img').addEventListener('error', function () {
      box.classList.add('is-broken');
      this.remove();
      var w = box.querySelector('.bl-see-broken');
      if (w) w.hidden = false;
    });
  }
  // Only re-read the file when one is actually chosen, so the preview does not
  // reset on every keystroke in another field.
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var f2 = fileInput.files && fileInput.files[0];
      if (!f2) return;
      setPreview(URL.createObjectURL(f2));
      // A new file replaces whatever was stored, including a stock photo id.
      if (coverHidden) coverHidden.value = '';
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (fileInput) fileInput.value = '';
      if (coverHidden) coverHidden.value = '';
      var now = d.querySelector('.bl-cover-now');
      if (now) now.remove();
      clearBtn.remove();
      var none = document.createElement('p');
      none.className = 'bl-cover-none';
      none.textContent = 'No image on this post. The cover is the first thing a reader sees.';
      d.querySelector('.bl-cover').appendChild(none);
      setPreview('');
    });
  }

  var pvTitle = q('bl-pv-title'), pvMetaT = q('bl-pv-meta-title'), pvMetaD = q('bl-pv-meta-desc');
  var pvMetaK = q('bl-pv-meta-kw'), pvBody = q('bl-pv-body'), metaTitle = q('bl-meta-title'), metaDesc = q('bl-meta-desc'), metaKw = q('bl-meta-kw');
  function syncText() {
    var t = f.title.value.trim(), ex = f.excerpt.value.trim(), kw = metaKw ? metaKw.value.trim() : '';
    pvTitle.textContent = t || 'Untitled post';
    pvMetaT.textContent = metaTitle.value.trim() || t || 'Untitled post';
    pvMetaD.textContent = metaDesc.value.trim() || ex || 'No meta description â€” search engines will pick their own words.';
    pvMetaK.innerHTML = (kw
      ? '<i class="fa-solid fa-tags" aria-hidden="true"></i>'
      : '<i class="fa-solid fa-tag" aria-hidden="true"></i>') +
      (kw || (f.status.value === 'published' ? 'No keywords on this post' : 'Not live, so nothing to open yet'));
  }
  ['title', 'excerpt', 'status'].forEach(function (n) { f[n].addEventListener('input', syncText); });
  [metaTitle, metaDesc, metaKw].forEach(function (el) { if (el) el.addEventListener('input', syncText); });
  syncText();

  f.addEventListener('ell:richtext', function (ev) { pvBody.innerHTML = ev.detail.html; });

  var words = d.querySelector('.bl-reading');
  var count = function () {
    var n = (pvBody.textContent || '').trim().split(/\s+/).filter(Boolean).length;
    var mins = n ? Math.max(1, Math.ceil(n / 200)) : 0;
    words.textContent = n + (n === 1 ? ' word' : ' words') + (mins ? ' Â· about ' + mins + ' min read' : '');
  };
  f.addEventListener('ell:richtext', count);
  f.addEventListener('input', count);
  count();

  // Delete asks first, naming the post, rather than the old bare "Delete this post?"
  d.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.dataset.confirm)) ev.preventDefault();
    });
  });
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Blog | Admin';
$navActive = 'blog.php';
require APP_ROOT . '/views/layouts/admin.php';
