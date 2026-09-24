<?php
declare(strict_types=1);
// CLI: php scripts/create_admin.php "Name" "email@example.com"
// Password is prompted interactively (never in shell history / never invented).
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$name = $argv[1] ?? null;
$email = $argv[2] ?? null;
if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create_admin.php \"Name\" \"email@example.com\"\n");
    exit(1);
}
echo 'Password (min 8 chars): ';
if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
    $pw = trim(fgets(STDIN));
} else {
    shell_exec('stty -echo');
    $pw = trim(fgets(STDIN));
    shell_exec('stty echo');
    echo PHP_EOL;
}
if (strlen($pw) < 8) {
    fwrite(STDERR, "Password too short.\n");
    exit(1);
}
$st = $pdo->prepare('SELECT 1 FROM admins WHERE email = ? LIMIT 1');
$st->execute([$email]);
if ($st->fetch()) {
    fwrite(STDERR, "Admin with this email already exists.\n");
    exit(1);
}
$pdo->prepare('INSERT INTO admins (name, email, password_hash, status) VALUES (?,?,?,?)')->execute([$name, $email, hash_password($pw), 'active']);
echo "Admin created: $email\n";
