<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$keys = ['site_title','meta_description','meta_keywords','site_logo','favicon','og_image','gsc_verification','ga_id','fb_pixel','robots_rules','org_name','org_phone','org_email','org_address','social_facebook','social_instagram','social_x','pricing_mode','tax_percent','currency','pickup_cutoff_hours','payout_interval_days','maps_enabled','lead_time_hours'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $logoPath = null;
    if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? 4) === UPLOAD_ERR_OK) {
        [$p, $err] = secure_upload($_FILES['logo'], 'vehicles', ['jpg','jpeg','png'], 2097152);
        if (!$err) $logoPath = $p;
        else $msg = $err;
    }
    foreach ($keys as $k) {
        if ($k === 'site_logo' && $logoPath) {
            $val = $logoPath;
        } elseif (!array_key_exists($k, $_POST)) {
            continue;
        } else {
            $val = trim((string)$_POST[$k]);
        }
        if ($k === 'pricing_mode') $val = $val === 'hourly' ? 'hourly' : 'per_mile';
        if ($k === 'maps_enabled') $val = $val === '1' ? '1' : '0';
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute([$k, $val]);
    }
    audit($pdo, 'admin', (int)$admin['id'], 'settings.updated', null, null, null);
    if ($msg === '') $msg = 'Settings saved.';
    header('Location: ' . url('admin/settings.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$vals = [];
foreach ($keys as $k) $vals[$k] = (string)(setting($pdo, $k, ''));
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Settings</h1>
<form method="post" enctype="multipart/form-data" class="card p-4 mt-3 space-y-4"><?= csrf_field() ?>
  <h3 class="label">General / SEO / Organization</h3>
  <div class="grid md:grid-cols-2 gap-3">
  <?php foreach (['site_title','meta_description','meta_keywords','gsc_verification','ga_id','fb_pixel','org_name','org_phone','org_email','org_address','social_facebook','social_instagram','social_x','currency'] as $k): ?>
    <div><label class="label" for="s-<?= e($k) ?>"><?= e($k) ?></label><input id="s-<?= e($k) ?>" name="<?= e($k) ?>" class="input" value="<?= e($vals[$k]) ?>"></div>
  <?php endforeach; ?>
  </div>
  <div><label class="label" for="robots_rules">Robots rules</label><textarea id="robots_rules" name="robots_rules" class="input" rows="3"><?= e($vals['robots_rules']) ?></textarea></div>
  <div class="grid md:grid-cols-3 gap-3">
    <div><label class="label" for="site_logo">Logo URL/path</label><input id="site_logo" name="site_logo" class="input" value="<?= e($vals['site_logo']) ?>"></div>
    <div><label class="label" for="favicon">Favicon path</label><input id="favicon" name="favicon" class="input" value="<?= e($vals['favicon']) ?>"></div>
    <div><label class="label" for="og_image">Default OG image</label><input id="og_image" name="og_image" class="input" value="<?= e($vals['og_image']) ?>"></div>
  </div>
  <div><label class="label" for="logo">Upload logo (png/jpg ≤2MB)</label><input id="logo" type="file" name="logo" class="input" accept=".jpg,.jpeg,.png"></div>
  <h3 class="label">Booking / payment / maps</h3>
  <div class="grid md:grid-cols-4 gap-3">
    <div><label class="label" for="pricing_mode">Pricing mode</label><select id="pricing_mode" name="pricing_mode" class="input"><option value="per_mile" <?= $vals['pricing_mode'] === 'per_mile' ? 'selected' : '' ?>>per_mile</option><option value="hourly" <?= $vals['pricing_mode'] === 'hourly' ? 'selected' : '' ?>>hourly</option></select></div>
    <div><label class="label" for="tax_percent">Tax %</label><input id="tax_percent" name="tax_percent" type="number" step="0.01" class="input" value="<?= e($vals['tax_percent']) ?>"></div>
    <div><label class="label" for="pickup_cutoff_hours">Pickup cutoff (h)</label><input id="pickup_cutoff_hours" name="pickup_cutoff_hours" type="number" step="0.5" class="input" value="<?= e($vals['pickup_cutoff_hours']) ?>"></div>
    <div><label class="label" for="payout_interval_days">Payout interval (days)</label><input id="payout_interval_days" name="payout_interval_days" type="number" class="input" value="<?= e($vals['payout_interval_days']) ?>"></div>
    <div><label class="label" for="maps_enabled">Maps enabled</label><select id="maps_enabled" name="maps_enabled" class="input"><option value="0" <?= $vals['maps_enabled'] !== '1' ? 'selected' : '' ?>>disabled</option><option value="1" <?= $vals['maps_enabled'] === '1' ? 'selected' : '' ?>>enabled</option></select></div>
    <div><label class="label" for="lead_time_hours">Lead time (h)</label><input id="lead_time_hours" name="lead_time_hours" type="number" step="0.5" class="input" value="<?= e($vals['lead_time_hours']) ?>"></div>
  </div>
  <p class="text-xs">Stripe/SMTP/Maps keys live in <code>.env</code> only — never in the database.</p>
  <button class="btn-gold">Save settings</button></form>
<?php
$content = ob_get_clean();
$pageTitle = 'Settings | Admin';
$navActive = 'settings.php';
require APP_ROOT . '/views/layouts/admin.php';
