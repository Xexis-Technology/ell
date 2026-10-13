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

/** Secure file upload for driver/vehicle documents and post covers. Returns stored path or null+error. */
function secure_upload(array $file, string $subdir, array $allowed = ['jpg','jpeg','png','pdf'], int $maxBytes = 5242880, array $allowedMime = ['image/jpeg','image/png','image/webp','application/pdf']): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'No file uploaded.'];
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        // UPLOAD_ERR_INI_SIZE / FORM_SIZE mean the file was too big for PHP.
        return [null, in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'That file is larger than the ' . round($maxBytes / 1048576, 1) . 'MB limit.'
            : 'Upload failed.'];
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        return [null, 'That file is ' . round(($file['size'] ?? 0) / 1048576, 1) . 'MB. The limit is ' . round($maxBytes / 1048576, 1) . 'MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return [null, 'File type not allowed.'];
    }
    return store_validated_upload($file, $subdir, $allowed, $allowedMime);
}

/**
 * Validation and storage shared by real uploads and camera captures.
 *
 * Camera captures never pass through $_FILES - the bytes come from a canvas in
 * the browser - but they must be judged by exactly the same rules as a file the
 * driver picked off their phone, or the camera route becomes the soft one.
 */
function store_validated_upload(array $file, string $subdir, array $allowed, array $allowedMime): array
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    // The extension is the author's claim; the bytes are the truth.
    if (!in_array($mime, $allowedMime, true)) {
        return [null, 'That file is not a ' . strtoupper(implode('/', $allowed)) . ' image.'];
    }
    $dir = APP_ROOT . '/storage/uploads/' . trim($subdir, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $dest = $dir . '/' . $name;
    // move_uploaded_file is the right primitive for a browser upload and refuses
    // anything else. A capture was written by us into a temp file we own, so it
    // moves with rename() - never with a path the client could influence.
    $moved = is_uploaded_file($file['tmp_name'])
        ? move_uploaded_file($file['tmp_name'], $dest)
        : rename($file['tmp_name'], $dest);
    if (!$moved) {
        return [null, 'Could not store file.'];
    }
    return ['storage/uploads/' . trim($subdir, '/') . '/' . $name, null];
}

/**
 * A photo taken with the in-page camera, posted as a data URL.
 *
 * decode + size cap + the same extension and finfo checks as secure_upload.
 * Returns the same [path, error] pair so callers do not branch on where the
 * image came from.
 */
function secure_capture(?string $dataUrl, string $subdir, array $allowed, int $maxBytes, array $allowedMime): array
{
    $raw = trim((string)$dataUrl);
    if ($raw === '') return [null, 'No photo was taken.'];
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#i', $raw, $m)) {
        return [null, 'That photo came through in an unexpected format.'];
    }
    $bin = base64_decode(substr($raw, strlen($m[0])), true);
    if ($bin === false || $bin === '') return [null, 'That photo could not be read.'];
    if (strlen($bin) > $maxBytes) {
        return [null, 'That photo is ' . round(strlen($bin) / 1048576, 1) . 'MB. The limit is ' . round($maxBytes / 1048576, 1) . 'MB.'];
    }

    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, $allowed, true)) return [null, 'File type not allowed.'];

    $tmp = tempnam(sys_get_temp_dir(), 'drvcap_');
    if ($tmp === false) return [null, 'Could not store file.'];
    if (file_put_contents($tmp, $bin) !== strlen($bin)) {
        @unlink($tmp);
        return [null, 'Could not store file.'];
    }
    $out = store_validated_upload(
        ['tmp_name' => $tmp, 'size' => strlen($bin), 'error' => UPLOAD_ERR_OK, 'name' => 'capture.' . $ext],
        $subdir,
        $allowed,
        $allowedMime
    );
    // Any failure leaves the temp file behind; clean it up rather than leaking it.
    if ($out[1] !== null && is_file($tmp)) @unlink($tmp);
    return $out;
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
    // Expiry is compared in PHP (same clock that issued the token), never MySQL NOW(),
    // so app/database timezone splits cannot silently invalidate tokens.
    $st = $pdo->prepare('SELECT * FROM password_resets WHERE role = ? AND token_hash = ? AND used_at IS NULL LIMIT 1');
    $st->execute([$role, $hash]);
    $row = $st->fetch();
    if (!$row) return null;
    if (strtotime((string)($row['expires_at'] ?? '')) <= time()) return null;
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    return $row;
}
