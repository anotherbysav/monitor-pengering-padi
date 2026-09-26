<?php
/**
 * Class inti: koneksi database dan akses konfigurasi.
 */

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__) . '/config.php';
        }
        return $config;
    }

    public static function app(string $key, mixed $default = null): mixed
    {
        $config = self::config()['app'] ?? [];
        return $config[$key] ?? $default;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = self::config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Koneksi database gagal: ' . $e->getMessage()
                . ' | Cek config.php (host/port/user/password) dan pastikan service MySQL/MariaDB berjalan.',
                0,
                $e
            );
        }

        /*
         * Samakan zona waktu sesi MariaDB dengan zona waktu aplikasi.
         * Tanpa ini NOW()/CURRENT_TIMESTAMP bisa berbeda beberapa jam dari
         * date() di PHP sehingga data yang baru masuk terlihat lebih tua.
         */
        $tz      = (string) self::app('timezone', 'Asia/Jakarta');
        $offset  = (new \DateTimeZone($tz))->getOffset(new \DateTime('now'));
        $sign    = $offset < 0 ? '-' : '+';
        $abs     = abs($offset);
        self::$pdo->exec(sprintf(
            "SET time_zone = '%s%02d:%02d'",
            $sign,
            intdiv($abs, 3600),
            intdiv($abs % 3600, 60)
        ));

        return self::$pdo;
    }

    /**
     * Koneksi tanpa memilih database (untuk setup / membuat DB).
     */
    public static function serverPdo(): PDO
    {
        $cfg = self::config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int) $cfg['port'], $cfg['charset']);
        return new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
