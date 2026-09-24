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
    if ($op === 'save_vehicle') {
        $id = (int)($_POST['id'] ?? 0);
        $fields = ['category_id' => $_POST['category_id'] ?: null, 'make' => trim($_POST['make'] ?? ''), 'model' => trim($_POST['model'] ?? ''), 'year' => (int)($_POST['year'] ?? 0) ?: null, 'plate' => trim($_POST['plate'] ?? ''), 'passenger_capacity' => (int)($_POST['passenger_capacity'] ?? 3), 'luggage_capacity' => (int)($_POST['luggage_capacity'] ?? 2), 'status' => in_array($_POST['status'] ?? '', ['active','inactive','maintenance'], true) ? $_POST['status'] : 'active'];
        if ($fields['make'] === '' || $fields['model'] === '') $msg = 'Make/model required.';
        elseif ($id) {
            $pdo->prepare('UPDATE vehicles SET category_id=?, make=?, model=?, year=?, plate=?, passenger_capacity=?, luggage_capacity=?, status=? WHERE id=?')->execute([...array_values($fields), $id]);
            audit($pdo, 'admin', (int)$admin['id'], 'vehicle.updated', 'vehicle', $id, null);
            $msg = 'Vehicle updated.';
        } else {
            $pdo->prepare('INSERT INTO vehicles (category_id, make, model, year, plate, passenger_capacity, luggage_capacity, status) VALUES (?,?,?,?,?,?,?,?)')->execute(array_values($fields));
            $nid = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO pricing_rates (vehicle_id, per_mile_rate, hourly_rate, active) VALUES (?,?,?,1)')->execute([$nid, (float)($_POST['per_mile_rate'] ?? 0), (float)($_POST['hourly_rate'] ?? 0)]);
            audit($pdo, 'admin', (int)$admin['id'], 'vehicle.created', 'vehicle', $nid, null);
            $msg = 'Vehicle created.';
        }
    } elseif ($op === 'save_rate') {
        $pdo->prepare('UPDATE pricing_rates SET active = 0 WHERE vehicle_id = ?')->execute([(int)$_POST['vehicle_id']]);
        $pdo->prepare('INSERT INTO pricing_rates (vehicle_id, per_mile_rate, hourly_rate, active) VALUES (?,?,?,1)')->execute([(int)$_POST['vehicle_id'], (float)$_POST['per_mile_rate'], (float)$_POST['hourly_rate']]);
        audit($pdo, 'admin', (int)$admin['id'], 'pricing.rate_updated', 'vehicle', (int)$_POST['vehicle_id'], null);
        $msg = 'Rate updated.';
    } elseif ($op === 'save_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->prepare('INSERT INTO vehicle_categories (name, description, active) VALUES (?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)')->execute([$name, trim($_POST['description'] ?? ''), isset($_POST['active']) ? 1 : 1]);
            $msg = 'Category saved.';
        }
    } elseif ($op === 'block') {
        $pdo->prepare('INSERT INTO vehicle_blocks (vehicle_id, starts_at, ends_at, reason, created_by) VALUES (?,?,?,?,?)')->execute([(int)$_POST['vehicle_id'], $_POST['starts_at'], $_POST['ends_at'], trim($_POST['reason'] ?? ''), (int)$admin['id']]);
        $msg = 'Vehicle blocked.';
    } elseif ($op === 'doc' && isset($_FILES['doc'])) {
        [$path, $err] = secure_upload($_FILES['doc'], 'vehicles');
        if ($err) $msg = $err;
        else {
            $pdo->prepare('INSERT INTO vehicle_documents (vehicle_id, doc_type, file_path, expiry_date, status) VALUES (?,?,?,?,?)')->execute([(int)$_POST['vehicle_id'], trim($_POST['doc_type'] ?? 'document'), $path, $_POST['expiry_date'] ?: null, 'pending']);
            $msg = 'Document uploaded.';
        }
    }
    header('Location: ' . url('admin/vehicles.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$cats = $pdo->query('SELECT * FROM vehicle_categories ORDER BY name')->fetchAll();
$vehicles = $pdo->query('SELECT v.*, c.name AS category, pr.per_mile_rate, pr.hourly_rate FROM vehicles v LEFT JOIN vehicle_categories c ON c.id = v.category_id LEFT JOIN pricing_rates pr ON pr.vehicle_id = v.id AND pr.active = 1 ORDER BY v.id DESC')->fetchAll();
$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT v.*, pr.per_mile_rate, pr.hourly_rate FROM vehicles v LEFT JOIN pricing_rates pr ON pr.vehicle_id = v.id AND pr.active = 1 WHERE v.id = ? LIMIT 1');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Vehicles</h1>
<div class="grid md:grid-cols-2 gap-4 mt-4">
<form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="save_vehicle"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <h3 class="label"><?= $edit ? 'Edit vehicle' : 'New vehicle' ?></h3>
  <select name="category_id" class="input" aria-label="Category"><option value="">No category</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= ($edit['category_id'] ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <div class="grid grid-cols-2 gap-2"><input name="make" class="input" placeholder="Make" required value="<?= e($edit['make'] ?? '') ?>"><input name="model" class="input" placeholder="Model" required value="<?= e($edit['model'] ?? '') ?>"></div>
  <div class="grid grid-cols-3 gap-2"><input name="year" type="number" class="input" placeholder="Year" value="<?= e((string)($edit['year'] ?? '')) ?>"><input name="plate" class="input" placeholder="Plate" value="<?= e($edit['plate'] ?? '') ?>">
  <select name="status" class="input"><option>active</option><option <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>inactive</option><option <?= ($edit['status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>maintenance</option></select></div>
  <div class="grid grid-cols-2 gap-2"><input name="passenger_capacity" type="number" min="1" class="input" placeholder="Pax" value="<?= e((string)($edit['passenger_capacity'] ?? 3)) ?>"><input name="luggage_capacity" type="number" min="0" class="input" placeholder="Bags" value="<?= e((string)($edit['luggage_capacity'] ?? 2)) ?>"></div>
  <?php if (!$edit): ?><div class="grid grid-cols-2 gap-2"><input name="per_mile_rate" type="number" step="0.01" class="input" placeholder="$/mi"><input name="hourly_rate" type="number" step="0.01" class="input" placeholder="$/hr"></div><?php endif; ?>
  <button class="btn-gold">Save vehicle</button></form>
<form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="save_category ?>">
  <h3 class="label">Categories</h3>
  <?php foreach ($cats as $c): ?><p class="text-sm"><?= e($c['name']) ?></p><?php endforeach; ?>
  <input name="name" class="input" placeholder="New category name"><input name="description" class="input" placeholder="Description"><button class="btn-gold">Save category</button></form>
</div>
<div class="table-wrap card mt-4"><table class="data"><thead><tr><th>ID</th><th>Vehicle</th><th>Cat</th><th>Pax</th><th>$/mi</th><th>$/hr</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($vehicles as $v): ?><tr><td><?= (int)$v['id'] ?></td><td><?= e($v['make'] . ' ' . $v['model']) ?></td><td><?= e((string)($v['category'] ?? '')) ?></td><td><?= (int)$v['passenger_capacity'] ?></td><td>$<?= money($v['per_mile_rate'] ?? 0) ?></td><td>$<?= money($v['hourly_rate'] ?? 0) ?></td><td><?= e($v['status']) ?></td>
<td class="whitespace-nowrap"><a class="underline" href="<?= url('admin/vehicles.php?action=edit&id=' . $v['id']) ?>">Edit</a>
<form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="op" value="save_rate"><input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>"><input name="per_mile_rate" type="number" step="0.01" class="input" style="width:80px;display:inline" value="<?= e((string)($v['per_mile_rate'] ?? '')) ?>" aria-label="Per mile"><input name="hourly_rate" type="number" step="0.01" class="input" style="width:80px;display:inline" value="<?= e((string)($v['hourly_rate'] ?? '')) ?>" aria-label="Hourly"><button class="btn-gold">Rate</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="grid md:grid-cols-2 gap-4 mt-4">
<form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="block ?>">
  <h3 class="label">Block vehicle (availability)</h3>
  <select name="vehicle_id" class="input"><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select>
  <div class="grid grid-cols-2 gap-2"><input name="starts_at" type="datetime-local" class="input" required><input name="ends_at" type="datetime-local" class="input" required></div>
  <input name="reason" class="input" placeholder="Reason"><button class="btn-gold">Block</button></form>
<form method="post" enctype="multipart/form-data" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="doc ?>">
  <h3 class="label">Vehicle document</h3>
  <select name="vehicle_id" class="input"><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select>
  <input name="doc_type" class="input" placeholder="insurance / registration / inspection / diamond">
  <input name="expiry_date" type="date" class="input"><input type="file" name="doc" class="input" accept=".jpg,.jpeg,.png,.pdf"><button class="btn-gold">Upload</button></form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Vehicles | Admin';
$navActive = 'vehicles.php';
require APP_ROOT . '/views/layouts/admin.php';
