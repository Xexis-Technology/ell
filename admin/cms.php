<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/richtext.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;

/**
 * Settings this page owns. Anything not listed here is left alone.
 *
 * This is the whole of how the site presents itself to a search engine:
 * the words it is found by, the picture it is shared with, the code that
 * proves ownership, and the structured data a local business is read by.
 * It all lived in admin/settings.php before, where it was mixed in with
 * fares and payout days and read as one flat list of fields.
 */
const SEO_SETTINGS = [
    'site_title' => 'Site title',
    'meta_description' => 'Default meta description',
    'meta_keywords' => 'Default meta keywords',
    'gsc_verification' => 'Google verification code',
    'ga_id' => 'Google Analytics ID',
    'fb_pixel' => 'Meta Pixel ID',
    'robots_rules' => 'Robots rules',
    'schema_type' => 'Business type',
    'org_name' => 'Business name',
    'org_phone' => 'Business phone',
    'org_email' => 'Business email',
    'org_street' => 'Street address',
    'org_city' => 'City',
    'org_region' => 'State or region',
    'org_postal' => 'Postal code',
    'org_country' => 'Country',
    'org_lat' => 'Latitude',
    'org_lng' => 'Longitude',
    'org_hours_247' => 'Open 24 hours',
    'price_range' => 'Price range',
    'service_area_cities' => 'Cities served',
    'service_area_radius' => 'Service radius',
    'social_facebook' => 'Facebook page',
    'social_instagram' => 'Instagram',
    'social_x' => 'X',
    'social_youtube' => 'YouTube',
    'social_tiktok' => 'TikTok',
    'social_linkedin' => 'LinkedIn',
];

/**
 * Opening hours are one field per day, so they cannot be added to the const
 * above at runtime. They are written explicitly by the save handler.
 */
const SEO_HOUR_KEYS = ['org_hours_mon', 'org_hours_tue', 'org_hours_wed', 'org_hours_thu', 'org_hours_fri', 'org_hours_sat', 'org_hours_sun'];

/**
 * Images this page owns. Uploaded, never typed as a path: a hand-written
 * `/assets/...` string is how a site ends up serving a logo that 404s, and
 * nothing on the page would have said so.
 */
const IMAGE_SETTINGS = [
    'site_logo' => ['Logo', ['png', 'webp'], 'Shown in the site header. A wide mark works best.'],
    'favicon' => ['Favicon', ['png', 'ico', 'webp'], 'The small icon in the browser tab.'],
    'og_image' => ['Share image', ['jpg', 'jpeg', 'png', 'webp'], 'Shown when the site is shared in a message. Landscape, about 1200&times;630.'],
];

/** File extension to the MIME types finfo may legitimately return for it. */
const IMAGE_MIME = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'ico' => 'image/vnd.microsoft.icon',
];

/**
 * Where each editable page actually lives. Not every content row sits in
 * /legal, so canonical URLs and "view page" links read this map instead of
 * assuming a folder.
 */
const CMS_PAGE_PATHS = [
    'terms' => 'legal/terms.php',
    'privacy' => 'legal/privacy.php',
    'faq' => 'legal/faq.php',
    'cancellation' => 'services/cancellation.php',
];

function cms_page_path(string $slug): string
{
    return CMS_PAGE_PATHS[$slug] ?? ('legal/' . $slug . '.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = (string)($_POST['op'] ?? '');
    $tab = (string)($_POST['tab'] ?? 'site');

    // The Remove button is a submit inside the site form, so its name arrives
// alongside op=save_site. It is checked first: removing a logo and saving
// the text fields in one click should remove the logo, not save both.
if (isset($_POST['remove_image'])) {
    $key = (string)$_POST['remove_image'];
    if (isset(IMAGE_SETTINGS[$key])) {
        $old = (string)setting($pdo, $key, '');
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=NOW()')->execute([$key, '']);
        if ($old !== '' && str_starts_with($old, 'storage/uploads/')) @unlink(APP_ROOT . '/' . $old);
        audit($pdo, 'admin', (int)$admin['id'], 'brand.image_removed', 'setting', null, ['key' => $key]);
        $msg = IMAGE_SETTINGS[$key][0] . ' removed.';
    } else {
        $msg = 'Unknown image.';
        $isErr = true;
    }
    $tab = 'site';
    $back = ['tab' => 'site'];
    header('Location: ' . url('admin/cms.php?' . http_build_query($back + ['msg' => $msg, 'err' => $isErr ? 1 : null])));
    exit;
}

if ($op === 'save_site') {
        // Uploads are handled first so a failed upload can be reported instead
        // of being silently dropped while the text fields still save.
        $images = [];
        foreach (IMAGE_SETTINGS as $key => [$label, $exts, $why]) {
            $input = 'img_' . $key;
            if (!isset($_FILES[$input]) || ($_FILES[$input]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $mimes = [];
            foreach ($exts as $e) if (isset(IMAGE_MIME[$e])) $mimes[] = IMAGE_MIME[$e];
            [$stored, $err] = secure_upload($_FILES[$input], 'brand', $exts, 3145728, $mimes);
            if ($err !== null) {
                $msg = $label . ': ' . $err;
                $isErr = true;
                break;
            }
            $images[$key] = $stored;
        }

        if (!$isErr) {
            foreach ($images as $key => $val) {
                $old = (string)setting($pdo, $key, '');
                $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=NOW()')->execute([$key, $val]);
                // Replacing an upload should not leave the old file behind.
                if ($old !== '' && str_starts_with($old, 'storage/uploads/')) @unlink(APP_ROOT . '/' . $old);
            }
            $writes = [];
            foreach (array_keys(SEO_SETTINGS) as $key) {
                // Checkboxes are absent when unticked, so the 24/7 flag is set
                // explicitly rather than being skipped like an unticked box.
                if ($key === 'org_hours_247') continue;
                if (!array_key_exists($key, $_POST)) continue;
                $writes[$key] = trim((string)$_POST[$key]);
            }
            // Hours are not in SEO_SETTINGS, so they are seeded from the POST
            // on their own. An absent day means closed.
            foreach (SEO_HOUR_KEYS as $hk) $writes[$hk] = trim((string)($_POST[$hk] ?? ''));
            $writes['org_hours_247'] = isset($_POST['org_hours_247']) ? '1' : '0';
            if ($writes['org_hours_247'] === '1') {
                // Clear the per-day hours so un-ticking 24/7 later does not
                // bring back a schedule that was hidden behind the box.
                foreach (SEO_HOUR_KEYS as $hk) $writes[$hk] = '';
            }

            // A half-typed coordinate is a pin in the ocean. Refuse rather
            // than storing a claim the data cannot support.
            foreach (['org_lat' => 90, 'org_lng' => 180] as $key => $limit) {
                $v = $writes[$key] ?? '';
                if ($v !== '' && (!is_numeric($v) || abs((float)$v) > $limit)) {
                    $msg = ($key === 'org_lat' ? 'Latitude' : 'Longitude') . ' must be a number' . ($key === 'org_lat' ? ' between -90 and 90.' : ' between -180 and 180.');
                    $isErr = true;
                    break;
                }
            }
            if ($writes['org_hours_247'] === '0') {
                foreach (SEO_HOUR_KEYS as $hk) {
                    $val = $writes[$hk];
                    if ($val === '') continue;
                    $norm = str_replace(['–', '—', ' '], ['-', '-', ''], $val);
                    if (!preg_match('/^(\d{1,2}):?(\d{2})\s*-\s*(\d{1,2}):?(\d{2})$/', $norm)) {
                        $msg = 'Opening hours for ' . ucfirst(substr($hk, 10)) . ' should look like 09:00-17:00.';
                        $isErr = true;
                        break;
                    }
                }
            }
            // A type outside the known list would emit schema the site does not claim to
// be, so it falls back rather than being stored.
            if (!isset($writes['schema_type']) || !isset(SEO_BUSINESS_TYPES[$writes['schema_type']])) {
                $writes['schema_type'] = 'LimousineService';
            }

            if (!$isErr) {
                foreach ($writes as $key => $val) {
                    $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=NOW()')
                        ->execute([$key, $val]);
                }
                audit($pdo, 'admin', (int)$admin['id'], 'seo.settings', 'settings', null, array_keys($writes));
                $msg = 'Search settings saved. Every page picks these up unless it overrides them.';
            }
        }
        $tab = 'site';
    } elseif ($op === 'save_page') {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $st = $pdo->prepare('SELECT id, slug, og_image FROM content WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch();
        if (!$row) {
            $msg = 'That page is not on file.';
            $isErr = true;
        } else {
            $title = trim((string)($_POST['title'] ?? ''));
            // The page's share image is uploaded, not typed as a path, so the
            // existing value is carried over untouched unless a new file was
            // chosen or Remove was pressed.
            $ogImage = trim((string)($row['og_image'] ?? '')) ?: null;
            $newImg = null;
            if (isset($_FILES['img_og_image']) && ($_FILES['img_og_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                [$stored, $err] = secure_upload($_FILES['img_og_image'], 'brand', ['jpg', 'jpeg', 'png', 'webp'], 3145728, ['image/jpeg', 'image/png', 'image/webp']);
                if ($err !== null) {
                    $msg = 'Share image: ' . $err;
                    $isErr = true;
                } else {
                    $newImg = $stored;
                }
            }
            if (!$isErr && isset($_POST['remove_og_image'])) $ogImage = null;
            if ($newImg !== null) $ogImage = $newImg;

            if ($title === '') {
                $msg = 'The page still needs a visible title.';
                $isErr = true;
            } elseif (!$isErr) {
                $oldImg = trim((string)($row['og_image'] ?? ''));
                if ($oldImg !== '' && $oldImg !== (string)$ogImage && str_starts_with($oldImg, 'storage/uploads/')) {
                    @unlink(APP_ROOT . '/' . $oldImg);
                }
                $clean = static function (?string $v): string {
                    $v = trim((string)$v);
                    return $v === '' ? '' : mb_substr($v, 0, 255);
                };
                $pdo->prepare('UPDATE content SET title=?, body=?, meta_title=?, meta_description=?, meta_keywords=?,
                        og_title=?, og_description=?, og_image=?, canonical_url=?, noindex=?, updated_by=?
                      WHERE id=?')->execute([
                    $title, clean_html((string)($_POST['body'] ?? '')) ?: null,
                    trim((string)($_POST['meta_title'] ?? '')) ?: null,
                    $clean((string)($_POST['meta_description'] ?? '')) ?: null,
                    $clean((string)($_POST['meta_keywords'] ?? '')) ?: null,
                    trim((string)($_POST['og_title'] ?? '')) ?: null,
                    $clean((string)($_POST['og_description'] ?? '')) ?: null,
                    $ogImage,
                    trim((string)($_POST['canonical_url'] ?? '')) ?: null,
                    isset($_POST['noindex']) ? 1 : 0,
                    (int)$admin['id'], (int)$row['id'],
                ]);
                audit($pdo, 'admin', (int)$admin['id'], 'content.updated', 'content', (int)$row['id'], ['slug' => $slug]);
                $msg = 'Saved ' . $title . '.';
            }
        }
        $tab = 'pages';
    } else {
        $msg = 'Nothing to save.';
        $isErr = true;
    }

    $back = ['tab' => $tab === 'pages' ? 'pages' : 'site'];
    if (!empty($_POST['slug'])) $back['slug'] = (string)$_POST['slug'];
    header('Location: ' . url('admin/cms.php?' . http_build_query($back + ['msg' => $msg, 'err' => $isErr ? 1 : null])));
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
$isErr = isset($_GET['err']);

$tab = (string)($_GET['tab'] ?? 'site');
if (!in_array($tab, ['site', 'pages'], true)) $tab = 'site';

$st = $pdo->query('SELECT * FROM content ORDER BY slug');
$pages = $st->fetchAll();

$slug = (string)($_GET['slug'] ?? '');
$page = null;
foreach ($pages as $p) if ((string)$p['slug'] === $slug) { $page = $p; break; }

$site = [];
foreach (array_merge(array_keys(SEO_SETTINGS), array_keys(IMAGE_SETTINGS)) as $k) {
    $site[$k] = (string)setting($pdo, $k, '');
}

// The structured data and the readiness list are produced by the same
// functions views/layouts/public.php calls, so the preview on this page is
// what ships rather than a description of it.
$schemaPreview = (string)json_encode(
    seo_schema_graph($pdo, [
        'url' => SITE_URL . '/',
        'title' => $site['site_title'],
        'description' => $site['meta_description'],
    ]),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
$readiness = seo_readiness($pdo);

/**
 * What a search engine will actually be handed for a page, and where each
 * value came from. This is the same resolution order views/layouts/public.php
 * uses, so the lines shown here are the lines that get served.
 */
function seo_resolve(array $row, array $site, string $slug): array
{
    $siteTitle = trim((string)$site['site_title']) !== '' ? trim((string)$site['site_title']) : 'Exotic Lane Limo';
    $rowTitle = trim((string)($row['title'] ?? ''));
    $t = trim((string)($row['meta_title'] ?? ''));
    $d = trim((string)($row['meta_description'] ?? ''));
    $k = trim((string)($row['meta_keywords'] ?? ''));
    $ogT = trim((string)($row['og_title'] ?? ''));
    $ogD = trim((string)($row['og_description'] ?? ''));
    $ogI = trim((string)($row['og_image'] ?? ''));
    $canon = trim((string)($row['canonical_url'] ?? ''));
    return [
        'title' => [$t !== '' ? $t : ($rowTitle !== '' ? $rowTitle : $siteTitle), $t !== '' ? 'page meta title' : ($rowTitle !== '' ? 'page title' : 'site title')],
        'description' => [$d !== '' ? $d : $site['meta_description'], $d !== '' ? 'page' : 'site'],
        'keywords' => [$k !== '' ? $k : $site['meta_keywords'], $k !== '' ? 'page' : 'site'],
        'og_title' => [$ogT !== '' ? $ogT : ($t !== '' ? $t : ($rowTitle !== '' ? $rowTitle : $siteTitle)), $ogT !== '' ? 'page' : ($t !== '' ? 'page meta title' : ($rowTitle !== '' ? 'page title' : 'site title'))],
        'og_description' => [$ogD !== '' ? $ogD : ($d !== '' ? $d : $site['meta_description']), $ogD !== '' ? 'page' : ($d !== '' ? 'page description' : 'site')],
        'og_image' => [$ogI !== '' ? $ogI : $site['og_image'], $ogI !== '' ? 'page' : 'site'],
        'canonical' => [$canon !== '' ? $canon : ($slug !== '' ? url(cms_page_path($slug)) : SITE_URL . '/'), $canon !== '' ? 'page' : ($slug !== '' ? 'this page' : 'site root')],
        'noindex' => [(int)($row['noindex'] ?? 0) === 1, 'page'],
    ];
}

/** Short, honest notes on what a search engine makes of each value. */
function seo_notes(string $key, string $value): array
{
    $n = mb_strlen($value);
    if ($value === '') return ['missing', 'nothing set'];
    if ($key === 'title') return $n > 60 ? ['warn', "$n characters — search engines cut around 60"] : ['good', "$n characters"];
    if ($key === 'description') return ($n < 70 || $n > 165) ? ['warn', "$n characters — aim for 70 to 165"] : ['good', "$n characters"];
    return ['good', $n . ' characters'];
}

ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="cm-head">
  <div>
    <p class="eyebrow">Content</p>
    <h1 class="font-display">SEO &amp; content</h1>
    <p class="cm-sub">The words every page inherits, and the pages that override them. What is shown here is exactly what gets served.</p>
  </div>
  <p class="cm-figures">
    <span><b><?= count($pages) ?></b> editable <?= count($pages) === 1 ? 'page' : 'pages' ?></span>
    <?php
    $needDesc = 0; $needTitle = 0;
    foreach ($pages as $pp) {
        if (trim((string)$pp['meta_description']) === '') $needDesc++;
        if (trim((string)$pp['meta_title']) === '') $needTitle++;
    }
    ?>
    <span<?= $needDesc > 0 ? ' class="is-warn"' : '' ?>><b><?= $needDesc ?></b> need a description</span>
    <span<?= $needTitle > 0 ? ' class="is-warn"' : '' ?>><b><?= $needTitle ?></b> use the default title</span>
  </p>
</header>

<nav class="cm-tabs" aria-label="Sections">
  <a class="cm-tab<?= $tab === 'site' ? ' is-on' : '' ?>" href="<?= url('admin/cms.php?tab=site') ?>"<?= $tab === 'site' ? ' aria-current="page"' : '' ?>>
    <span class="cm-tab-l">Site defaults</span>
    <span class="cm-tab-s">What every page falls back to</span>
  </a>
  <a class="cm-tab<?= $tab === 'pages' ? ' is-on' : '' ?>" href="<?= url('admin/cms.php?tab=pages') ?>"<?= $tab === 'pages' ? ' aria-current="page"' : '' ?>>
    <span class="cm-tab-l">Pages</span>
    <span class="cm-tab-s">Edit content and override SEO per page</span>
  </a>
</nav>

<?php if ($tab === 'site'): ?>
<form method="post" enctype="multipart/form-data" class="cm-grid">
  <?= csrf_field() ?>
  <input type="hidden" name="op" value="save_site">
  <input type="hidden" name="tab" value="site">

  <div class="cm-fields">

    <fieldset class="cm-group">
      <legend class="cm-group-h">Meta setup</legend>
      <p class="cm-hint" style="margin:0 0 .2rem">The words a search engine reads to decide what a page is and whether to show it.</p>
      <div class="cm-field">
        <label class="cm-label" for="site_title">Site title</label>
        <input id="site_title" name="site_title" class="input" value="<?= e($site['site_title']) ?>" placeholder="Exotic Lane Limo | Luxury Chauffeur Service">
        <span class="cm-hint"><?= e(seo_notes('title', $site['site_title'])[1]) ?>. Used when a page sets no title of its own.</span>
      </div>
      <div class="cm-field">
        <label class="cm-label" for="meta_description">Default description</label>
        <textarea id="meta_description" name="meta_description" class="input cm-textarea" rows="3" placeholder="One or two sentences on what this company does."><?= e($site['meta_description']) ?></textarea>
        <span class="cm-hint"><?= e(seo_notes('description', $site['meta_description'])[1]) ?>. The fallback for every page without its own.</span>
      </div>
      <div class="cm-field">
        <label class="cm-label" for="meta_keywords">Default keywords</label>
        <input id="meta_keywords" name="meta_keywords" class="input" value="<?= e($site['meta_keywords']) ?>" placeholder="limo, chauffeur, airport car service">
        <span class="cm-hint">Comma separated. Most search engines ignore this; it costs nothing to keep accurate.</span>
      </div>
    </fieldset>

    <fieldset class="cm-group">
      <legend class="cm-group-h">Google</legend>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="gsc_verification">Search Console code</label>
          <input id="gsc_verification" name="gsc_verification" class="input" value="<?= e($site['gsc_verification']) ?>" placeholder="abc123…">
          <span class="cm-hint">The <code>content</code> value from the Search Console HTML tag. This is what proves the site is yours.</span>
        </div>
        <div class="cm-field">
          <label class="cm-label" for="ga_id">Analytics ID</label>
          <input id="ga_id" name="ga_id" class="input" value="<?= e($site['ga_id']) ?>" placeholder="G-XXXXXXX">
          <span class="cm-hint">Loads on every public page. Leave empty to send no analytics at all.</span>
        </div>
      </div>
      <div class="cm-field">
        <label class="cm-label" for="fb_pixel">Meta Pixel ID</label>
        <input id="fb_pixel" name="fb_pixel" class="input" value="<?= e($site['fb_pixel']) ?>" placeholder="1234567890">
        <span class="cm-hint">For ad tracking. Leave empty if you do not run ads on Facebook.</span>
      </div>
      <div class="cm-field">
        <label class="cm-label" for="robots_rules">Robots rules</label>
        <textarea id="robots_rules" name="robots_rules" class="input cm-textarea" rows="3"><?= e($site['robots_rules']) ?></textarea>
        <span class="cm-hint">Served at <code>/robots.txt</code>. Private areas are always blocked on top of whatever you write here.</span>
      </div>
    </fieldset>

    <fieldset class="cm-group">
      <legend class="cm-group-h">Local SEO</legend>
      <p class="cm-hint" style="margin:0 0 .2rem">What a local result is built from. Every field here should match the listing you already have, exactly.</p>
      <div class="cm-field">
        <label class="cm-label" for="schema_type">Business type</label>
        <select id="schema_type" name="schema_type" class="input">
          <?php $schemaTypeNow = isset(SEO_BUSINESS_TYPES[$site['schema_type']]) ? $site['schema_type'] : 'LimousineService'; ?>
          <?php foreach (SEO_BUSINESS_TYPES as $val => $label): ?>
          <option value="<?= e($val) ?>"<?= $schemaTypeNow === $val ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="cm-hint">The kind of business this is in the search engine's own vocabulary.</span>
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="org_street">Street address</label>
          <input id="org_street" name="org_street" class="input" value="<?= e($site['org_street']) ?>" placeholder="233 S Wacker Dr">
        </div>
        <div class="cm-field">
          <label class="cm-label" for="org_city">City</label>
          <input id="org_city" name="org_city" class="input" value="<?= e($site['org_city']) ?>" placeholder="Chicago">
        </div>
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="org_region">State or region</label>
          <input id="org_region" name="org_region" class="input" value="<?= e($site['org_region']) ?>" placeholder="IL">
        </div>
        <div class="cm-field">
          <label class="cm-label" for="org_postal">Postal code</label>
          <input id="org_postal" name="org_postal" class="input" value="<?= e($site['org_postal']) ?>" placeholder="60606">
        </div>
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="org_lat">Latitude</label>
          <input id="org_lat" name="org_lat" class="input" type="number" step="any" value="<?= e($site['org_lat']) ?>" placeholder="41.8781">
          <span class="cm-hint">Places the map pin on the door.</span>
        </div>
        <div class="cm-field">
          <label class="cm-label" for="org_lng">Longitude</label>
          <input id="org_lng" name="org_lng" class="input" type="number" step="any" value="<?= e($site['org_lng']) ?>" placeholder="-87.6298">
          <span class="cm-hint">Both halves are required; one alone is a pin in the ocean.</span>
        </div>
      </div>
      <div class="cm-check cm-check-inline">
        <input type="checkbox" name="org_hours_247" value="1" data-gates="cm-hours"<?= $site['org_hours_247'] === '1' ? ' checked' : '' ?>>
        <span>Open 24 hours a day</span>
        <small>Chauffeur work usually is. Leave this off to set hours for each day below.</small>
      </div>
      <div class="cm-hours" id="cm-hours"<?= $site['org_hours_247'] === '1' ? ' hidden' : '' ?>>
        <?php foreach (SEO_DAYS as $hk => $hname):
          $hk2 = $site['org_hours_247'] === '1' ? '' : seo_setting($pdo, 'org_hours_' . $hk); ?>
        <div class="cm-hour">
          <label class="cm-label" for="h_<?= e($hk) ?>"><?= e($hname) ?></label>
          <input id="h_<?= e($hk) ?>" name="org_hours_<?= e($hk) ?>" class="input" value="<?= e($hk2) ?>" placeholder="09:00-17:00">
        </div>
        <?php endforeach; ?>
        <p class="cm-hint" style="flex-basis:100%">Format <code>09:00-17:00</code>. Leave a day empty to close it.</p>
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="service_area_cities">Cities served</label>
          <input id="service_area_cities" name="service_area_cities" class="input" value="<?= e($site['service_area_cities']) ?>" placeholder="Chicago, Oak Park, Evanston">
          <span class="cm-hint">Comma separated. Each one becomes a place this business serves.</span>
        </div>
        <div class="cm-field">
          <label class="cm-label" for="service_area_radius">Service radius (km)</label>
          <input id="service_area_radius" name="service_area_radius" class="input" type="number" step="any" min="0" value="<?= e($site['service_area_radius']) ?>" placeholder="40">
          <span class="cm-hint">Used only when no cities are listed.</span>
        </div>
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="org_phone">Business phone</label>
          <input id="org_phone" name="org_phone" class="input" value="<?= e($site['org_phone']) ?>" placeholder="+1 312 555 0100">
          <span class="cm-hint">Must match the number on your Google listing, character for character.</span>
        </div>
        <div class="cm-field">
          <label class="cm-label" for="price_range">Price range</label>
          <input id="price_range" name="price_range" class="input" value="<?= e($site['price_range']) ?>" placeholder="$$$">
          <span class="cm-hint">Shown to customers as a hint of cost before they ask.</span>
        </div>
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="org_country">Country</label>
          <input id="org_country" name="org_country" class="input" value="<?= e($site['org_country']) ?>" placeholder="US">
          <span class="cm-hint">Two-letter code, as schema.org expects it.</span>
        </div>
        <div class="cm-field">
          <label class="cm-label" for="org_email">Business email</label>
          <input id="org_email" name="org_email" class="input" type="email" value="<?= e($site['org_email']) ?>" placeholder="bookings@exoticlanelimo.com">
        </div>
      </div>
      <div class="cm-field">
        <label class="cm-label" for="price_range">Price range</label>
        <input id="price_range" name="price_range" class="input" value="<?= e($site['price_range']) ?>" placeholder="$$$">
        <span class="cm-hint">Shown to customers as a hint of cost before they ask.</span>
      </div>
    </fieldset>

    <fieldset class="cm-group">
      <legend class="cm-group-h">Social SEO</legend>
      <p class="cm-hint" style="margin:0 0 .2rem">These tie the website to the profiles you already have, so a search engine can tell they are the same business.</p>
      <div class="cm-field">
        <label class="cm-label" for="org_name">Business name</label>
        <input id="org_name" name="org_name" class="input" value="<?= e($site['org_name']) ?>" placeholder="Exotic Lane Limo">
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="social_facebook">Facebook page</label>
          <input id="social_facebook" name="social_facebook" class="input" value="<?= e($site['social_facebook']) ?>" placeholder="https://facebook.com/…">
        </div>
        <div class="cm-field">
          <label class="cm-label" for="social_instagram">Instagram</label>
          <input id="social_instagram" name="social_instagram" class="input" value="<?= e($site['social_instagram']) ?>" placeholder="https://instagram.com/…">
        </div>
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="social_x">X</label>
          <input id="social_x" name="social_x" class="input" value="<?= e($site['social_x']) ?>" placeholder="https://x.com/…">
        </div>
        <div class="cm-field">
          <label class="cm-label" for="social_youtube">YouTube</label>
          <input id="social_youtube" name="social_youtube" class="input" value="<?= e($site['social_youtube']) ?>" placeholder="https://youtube.com/@…">
        </div>
      </div>
      <div class="cm-pair">
        <div class="cm-field">
          <label class="cm-label" for="social_tiktok">TikTok</label>
          <input id="social_tiktok" name="social_tiktok" class="input" value="<?= e($site['social_tiktok']) ?>" placeholder="https://tiktok.com/@…">
        </div>
        <div class="cm-field">
          <label class="cm-label" for="social_linkedin">LinkedIn</label>
          <input id="social_linkedin" name="social_linkedin" class="input" value="<?= e($site['social_linkedin']) ?>" placeholder="https://linkedin.com/company/…">
        </div>
      </div>
    </fieldset>

    <fieldset class="cm-group">
      <legend class="cm-group-h">Images</legend>
      <?php foreach (IMAGE_SETTINGS as $key => [$label, $exts, $why]):
        $have = trim((string)$site[$key]) !== '';
        $src = $have ? media_url((string)$site[$key]) : '';
        $id = 'img_' . $key; ?>
      <div class="cm-upload">
        <div class="cm-upload-head">
          <label class="cm-label" for="<?= e($id) ?>"><?= e($label) ?></label>
          <span class="cm-hint"><?= $why ?></span>
        </div>
        <?php if ($have): ?>
        <div class="cm-upload-now">
          <img src="<?= e($src) ?>" alt="<?= e($label) ?>" decoding="async" onerror="this.remove()">
          <span class="cm-upload-path"><?= e((string)$site[$key]) ?></span>
          <button type="submit" class="cm-upload-clear" name="remove_image" value="<?= e($key) ?>">
            <i class="fa-solid fa-trash" aria-hidden="true"></i> Remove
          </button>
        </div>
        <?php endif; ?>
        <input id="<?= e($id) ?>" type="file" name="<?= e($id) ?>" class="cm-upload-input"
               accept="<?= e(implode(',', array_unique(array_map(fn($x) => IMAGE_MIME[$x], $exts)))) ?>">
        <span class="cm-hint"><?= $have ? 'Choose a file to replace the current one. ' : 'No image set yet. ' ?><?= e(strtoupper(implode(', ', $exts))) ?>, up to 3MB.</span>
      </div>
      <?php endforeach; ?>
    </fieldset>

    <div class="cm-acts">
      <button class="cm-save">Save search settings</button>
      <span class="cm-save-why">Changes go live on the next page load.</span>
    </div>
  </div>

  <aside class="cm-dump cm-dump-wide" aria-label="What search engines will be told">
    <p class="cm-dump-h">Search result <span>how this site reads in a listing</span></p>
    <div class="cm-serp">
      <p class="cm-serp-url"><?= e(SITE_URL) ?>/</p>
      <p class="cm-serp-title"><?= e($site['site_title'] !== '' ? $site['site_title'] : 'No site title set') ?></p>
      <p class="cm-serp-desc"><?= e($site['meta_description'] !== '' ? $site['meta_description'] : 'No default description set, so search engines will pick a line of page text instead.') ?></p>
    </div>

    <p class="cm-dump-h">Structured data <span>the machine-readable claim</span></p>
    <pre class="cm-json"><?= e($schemaPreview) ?></pre>
    <p class="cm-dump-foot">This is built by the same function the site serves, so it is the real markup rather than a description of it.
      The FAQ page adds its questions and each blog post is marked up as an article.</p>

    <p class="cm-dump-h">Readiness <span>what is still missing</span></p>
    <?php foreach ($readiness as $f): ?>
    <div class="cm-check-row is-<?= e($f['tone']) ?>">
      <i class="fa-solid <?= $f['tone'] === 'ok' ? 'fa-check' : 'fa-arrow-right' ?>" aria-hidden="true"></i>
      <span><b><?= e($f['what']) ?></b><?= $f['tone'] === 'ok' ? '' : ' — ' . e($f['fix']) ?></span>
    </div>
    <?php endforeach; ?>
    <p class="cm-dump-foot">These are the signals a crawler can read. They make the site eligible to be shown correctly — a rich result, a local
      entry, a larger share preview. Position in a ranking is decided by the search engine across the whole web and is not something this
      page can promise.</p>
  </aside>
</form>

<?php else: ?>
<div class="cm-pages">
  <nav class="cm-index" aria-label="Pages">
    <ul class="cm-page-list">
      <?php foreach ($pages as $pp):
        $isOn = $page && (int)$page['id'] === (int)$pp['id'];
        $own = trim((string)$pp['meta_description']) !== ''; ?>
      <li>
        <a class="cm-page<?= $isOn ? ' is-on' : '' ?>" href="<?= url('admin/cms.php?tab=pages&slug=' . urlencode((string)$pp['slug'])) ?>">
          <span class="cm-page-slug"><?= e((string)$pp['slug']) ?></span>
          <span class="cm-page-title"><?= e((string)($pp['title'] ?: 'Untitled')) ?></span>
          <span class="cm-page-flags">
            <span class="cm-flag cm-flag-<?= $own ? 'own' : 'inherit' ?>"><?= $own ? 'own description' : 'default description' ?></span>
            <?php if ((int)$pp['noindex'] === 1): ?><span class="cm-flag cm-flag-warn">hidden from search</span><?php endif; ?>
          </span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <?php if (!$page): ?>
    <section class="cm-sheet cm-sheet-empty">
      <p class="cm-empty-t">Pick a page.</p>
      <p>Each one carries its own text and can override any SEO field. Anything left blank falls back to the site default.</p>
    </section>
  <?php else:
    $r = seo_resolve($page, $site, (string)$page['slug']);
    $sl = (string)$page['slug']; ?>
  <section class="cm-sheet">
    <header class="cm-sheet-head">
      <div>
        <p class="cm-sheet-slug">/<?= e(cms_page_path($sl)) ?></p>
        <h2 class="cm-sheet-title"><?= e((string)($page['title'] ?: 'Untitled')) ?></h2>
      </div>
      <a class="cm-view" href="<?= url(cms_page_path($sl)) ?>" target="_blank" rel="noopener">View page <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
    </header>

    <form method="post" enctype="multipart/form-data" class="cm-sheet-form">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="save_page">
      <input type="hidden" name="tab" value="pages">
      <input type="hidden" name="slug" value="<?= e($sl) ?>">

      <div class="cm-field">
        <label class="cm-label" for="title">Page title</label>
        <input id="title" name="title" class="input" value="<?= e((string)$page['title']) ?>" required>
        <span class="cm-hint">The heading people see on the page.</span>
      </div>

      <div class="cm-field">
        <span class="cm-label">Page content</span>
        <textarea class="ql-host cm-body" name="body" data-ql-preset="article" placeholder="Write the page."><?= e((string)($page['body'] ?? '')) ?></textarea>
      </div>

      <fieldset class="cm-group cm-group-plain">
        <legend class="cm-group-h">How this page appears in search</legend>
        <div class="cm-field">
          <label class="cm-label" for="meta_title">Meta title</label>
          <input id="meta_title" name="meta_title" class="input" maxlength="190" value="<?= e((string)$page['meta_title']) ?>" placeholder="defaults to the page title">
          <span class="cm-hint"><?= e(seo_notes('title', $r['title'][0])[1]) ?></span>
        </div>
        <div class="cm-field">
          <label class="cm-label" for="meta_description">Meta description</label>
          <textarea id="meta_description" name="meta_description" class="input cm-textarea" rows="2" maxlength="255" placeholder="defaults to the site description"><?= e((string)$page['meta_description']) ?></textarea>
          <span class="cm-hint"><?= e(seo_notes('description', $r['description'][0])[1]) ?></span>
        </div>
        <div class="cm-field">
          <label class="cm-label" for="meta_keywords">Meta keywords</label>
          <input id="meta_keywords" name="meta_keywords" class="input" maxlength="255" value="<?= e((string)$page['meta_keywords']) ?>" placeholder="defaults to the site keywords">
        </div>
        <div class="cm-pair">
          <div class="cm-field">
            <label class="cm-label" for="og_title">Share title</label>
            <input id="og_title" name="og_title" class="input" maxlength="190" value="<?= e((string)$page['og_title']) ?>" placeholder="defaults to the meta title">
          </div>
          <div class="cm-field">
            <label class="cm-label" for="og_description">Share description</label>
            <input id="og_description" name="og_description" class="input" maxlength="255" value="<?= e((string)$page['og_description']) ?>" placeholder="defaults to the meta description">
          </div>
        </div>
        <div class="cm-pair">
          <div class="cm-field">
            <span class="cm-label">Share image</span>
            <?php $pgImg = trim((string)($page['og_image'] ?? '')); ?>
            <?php if ($pgImg !== ''): ?>
            <div class="cm-upload-now">
              <img src="<?= e(media_url($pgImg)) ?>" alt="Share image" decoding="async" onerror="this.remove()">
              <label class="cm-upload-clear">
                <input type="checkbox" name="remove_og_image" value="1">
                <i class="fa-solid fa-trash" aria-hidden="true"></i> Remove
              </label>
            </div>
            <?php endif; ?>
            <input id="img_og_image" type="file" name="img_og_image" class="cm-upload-input"
                   accept="<?= e(implode(',', ['image/jpeg', 'image/png', 'image/webp'])) ?>">
            <span class="cm-hint">Shown when this page is shared. Landscape, about 1200&times;630. <?= $pgImg !== '' ? 'Choose a file to replace it.' : 'Falls back to the site share image until you set one.' ?></span>
          </div>
          <div class="cm-field">
            <label class="cm-label" for="canonical_url">Canonical URL</label>
            <input id="canonical_url" name="canonical_url" class="input" value="<?= e((string)$page['canonical_url']) ?>" placeholder="defaults to this page's address">
          </div>
        </div>
        <label class="cm-check">
          <input type="checkbox" name="noindex" value="1"<?= (int)$page['noindex'] === 1 ? ' checked' : '' ?>>
          <span>Hide this page from search engines</span>
          <small>Use for pages you do not want found, such as a draft or an internal notice.</small>
        </label>
      </fieldset>

      <div class="cm-acts">
        <button class="cm-save">Save <?= e((string)$page['title']) ?></button>
        <span class="cm-save-why">Changes go live as soon as you save.</span>
      </div>
    </form>

    <aside class="cm-dump cm-dump-page" aria-label="What will be served for this page">
      <p class="cm-dump-h">Served for this page <span>each line says where its value comes from</span></p>
      <?php
      $ogUrl = media_url($r['og_image'][0]);
      $dump = [
          ['<title>' . $r['title'][0] . '</title>', $r['title'][1]],
          ['<meta name="description" content="' . $r['description'][0] . '">', $r['description'][1]],
          ['<meta name="keywords" content="' . $r['keywords'][0] . '">', $r['keywords'][1]],
          ['<meta name="robots" content="' . ($r['noindex'][0] ? 'noindex, nofollow' : 'index, follow, max-image-preview:large') . '">', $r['noindex'][1]],
          ['<meta property="og:title" content="' . $r['og_title'][0] . '">', $r['og_title'][1]],
          ['<meta property="og:image" content="' . $ogUrl . '">', $r['og_image'][1]],
          ['<link rel="canonical" href="' . $r['canonical'][0] . '">', $r['canonical'][1]],
      ];
      // Escaped whole: these are tags to be *shown*, and a live <title> or
      // <meta> written into the page would be parsed, not displayed.
      foreach ($dump as [$line, $from]): ?>
      <div class="cm-dump-row">
        <code class="cm-dump-line is-inherit"><?= e($line) ?></code>
        <span class="cm-dump-note">from <?= e($from) ?></span>
      </div>
      <?php endforeach; ?>
    </aside>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'SEO & Content | Admin';
$navActive = 'cms.php';
require APP_ROOT . '/views/layouts/admin.php';
