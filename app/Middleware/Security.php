<?php
declare(strict_types=1);

function validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_required(array $data, array $fields): array
{
    $errors = [];
    foreach ($fields as $f) {
        if (!isset($data[$f]) || trim((string)$data[$f]) === '') {
            $errors[$f] = 'This field is required.';
        }
    }
    return $errors;
}

function validate_password(string $pw): ?string
{
    $cfg = require APP_ROOT . '/config/auth.php';
    if (strlen($pw) < $cfg['min_password_length']) {
        return 'Password must be at least ' . $cfg['min_password_length'] . ' characters.';
    }
    return null;
}

/** Secure file upload for driver/vehicle documents. Returns stored path or null+error. */
function secure_upload(array $file, string $subdir, array $allowed = ['jpg','jpeg','png','pdf'], int $maxBytes = 5242880): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'No file uploaded.'];
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed.'];
    }
    if ($file['size'] > $maxBytes) {
        return [null, 'File too large (max 5MB).'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return [null, 'File type not allowed.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMime = ['image/jpeg','image/png','application/pdf'];
    if (!in_array($mime, $allowedMime, true)) {
        return [null, 'Invalid file content.'];
    }
    $dir = APP_ROOT . '/storage/uploads/' . trim($subdir, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [null, 'Could not store file.'];
    }
    return ['storage/uploads/' . trim($subdir, '/') . '/' . $name, null];
}

/** Issue a password-reset token (stores only hash, 30-min TTL, single-use). Returns raw token. */
function issue_reset_token(PDO $pdo, string $role, int $userId): string
{
    $cfg = require APP_ROOT . '/config/auth.php';
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $pdo->prepare('DELETE FROM password_resets WHERE role = ? AND user_id = ?')->execute([$role, $userId]);
    $st = $pdo->prepare('INSERT INTO password_resets (role, user_id, token_hash, expires_at) VALUES (?,?,?,?)');
    $st->execute([$role, $userId, $hash, date('Y-m-d H:i:s', time() + $cfg['reset_token_ttl'])]);
    return $raw;
}

function consume_reset_token(PDO $pdo, string $role, string $raw): ?array
{
    $hash = hash('sha256', $raw);
    $st = $pdo->prepare('SELECT * FROM password_resets WHERE role = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $st->execute([$role, $hash]);
    $row = $st->fetch();
    if (!$row) return null;
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    return $row;
}
