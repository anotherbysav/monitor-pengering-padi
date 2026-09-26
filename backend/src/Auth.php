<?php
/**
 * Autentikasi perangkat IoT via API key.
 *
 * Dashboard web tidak memakai login: seluruh route dashboard purposefully
 * terbuka agar bisa dibuka langsung tanpa sesi. Endpoint /api/sensor/*
 * tetap dilindungi API key karena diakses mikrokontroler, bukan browser.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class Auth
{
    /* ==================================================================
     *  PERANGKAT (API key)
     * ================================================================== */

    public static function deviceFromKey(?string $key): ?array

    {
        if ($key === null || trim($key) === '') {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM `api_keys` WHERE `key_hash` = ? AND `is_active` = 1 LIMIT 1'
        );
        $stmt->execute([self::hashKey(trim($key))]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        Db::pdo()->prepare('UPDATE `api_keys` SET `last_used_at` = NOW() WHERE id = ?')->execute([(int) $row['id']]);
        return $row;
    }

    /** API key dari header atau query string. */
    public static function keyFromRequest(): ?string
    {
        $header = $_SERVER['HTTP_X_API_KEY'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($auth) && stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        $q = $_GET['api_key'] ?? null;
        return is_string($q) && $q !== '' ? $q : null;
    }

    /** Wajib API key valid; mengembalikan row device_code. */
    public static function requireDevice(): string
    {
        $row = self::deviceFromKey(self::keyFromRequest());
        if ($row === null) {
            Http::fail('API key tidak valid atau sudah dinonaktifkan.', 401, 'invalid_api_key', [
                'hint' => 'Kirim header X-Api-Key: <kunci> atau ?api_key=<kunci>. '
                        . 'Kunci bisa dibuat/diatur lewat web pada halaman "Perangkat & API".',
            ]);
            exit;
        }
        return (string) $row['device_code'];
    }

    public static function generateKey(): string
    {
        return 'msk_' . bin2hex(random_bytes(16));
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function ensureDevice(string $deviceCode, ?string $firmware = null, ?string $ip = null): int
    {
        $db = Db::pdo();
        $stmt = $db->prepare('SELECT id FROM `devices` WHERE `device_code` = ? LIMIT 1');
        $stmt->execute([$deviceCode]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $profile = Settings::get('material_profile', 'padi');
            $ins = $db->prepare(
                'INSERT INTO `devices` (device_code, name, location, profile_code, is_active)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $ins->execute([$deviceCode, $deviceCode, '', $profile]);
            $id = (int) $db->lastInsertId();
        }

        $db->prepare(
            'UPDATE `devices` SET `last_seen_at` = NOW(), `last_ip` = ?, `firmware` = COALESCE(?, firmware) WHERE id = ?'
        )->execute([$ip, $firmware, $id]);

        return (int) $id;
    }
}
