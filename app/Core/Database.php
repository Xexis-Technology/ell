<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $cfg = require APP_ROOT . '/config/database.php';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
        self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Align MySQL session time with the app timezone so NOW()/CURDATE()
        // writes stay consistent with PHP-generated datetimes (offset form
        // always works, even without loaded timezone tables).
        try {
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (Throwable) {
        }
        return self::$pdo;
    }

    /** For tests: allow injecting a PDO. */
    public static function setPdo(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
