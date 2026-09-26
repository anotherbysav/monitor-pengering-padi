<?php
/**
 * Alert berbasis ambang batas + resolve otomatis saat kembali normal.
 */

declare(strict_types=1);

namespace App;

final class Alerts
{
    public static function raise(string $deviceCode, string $key, string $severity, string $message, ?float $value = null, ?float $threshold = null): ?int
    {
        $existing = self::findActive($deviceCode, $key);

        if ($existing) {
            $stmt = Db::pdo()->prepare(
                'UPDATE `alerts` SET message = ?, value = ?, threshold = ?, triggered_at = NOW() WHERE id = ?'
            );
            $stmt->execute([mb_substr($message, 0, 255), $value, $threshold, $existing]);
            return (int) $existing;
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO `alerts` (device_code, alert_key, severity, message, value, threshold, is_active)
             VALUES (?,?,?,?,?,?,1)'
        );
        $stmt->execute([$deviceCode, $key, $severity, mb_substr($message, 0, 255), $value, $threshold]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function resolve(string $deviceCode, string $key): void
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE `alerts` SET is_active = 0, resolved_at = NOW()
             WHERE device_code = ? AND alert_key = ? AND is_active = 1'
        );
        $stmt->execute([$deviceCode, $key]);
    }

    public static function findActive(string $deviceCode, string $key): ?int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id FROM `alerts` WHERE device_code = ? AND alert_key = ? AND is_active = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$deviceCode, $key]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public static function active(string $deviceCode): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM `alerts` WHERE device_code = ? AND is_active = 1 ORDER BY
                FIELD(severity, "critical", "warning", "info"), triggered_at DESC'
        );
        $stmt->execute([$deviceCode]);
        return array_map([self::class, 'map'], $stmt->fetchAll());
    }

    public static function history(string $deviceCode, int $limit = 40): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM `alerts` WHERE device_code = ? ORDER BY triggered_at DESC, id DESC LIMIT ' . max(1, min(300, $limit))
        );
        $stmt->execute([$deviceCode]);
        return array_map([self::class, 'map'], $stmt->fetchAll());
    }

    public static function acknowledge(int $id, string $deviceCode): bool
    {
        $stmt = Db::pdo()->prepare('UPDATE `alerts` SET acknowledged = 1 WHERE id = ? AND device_code = ?');
        $stmt->execute([$id, $deviceCode]);
        return $stmt->rowCount() > 0;
    }

    public static function acknowledgeAll(string $deviceCode): int
    {
        $stmt = Db::pdo()->prepare('UPDATE `alerts` SET acknowledged = 1 WHERE device_code = ? AND is_active = 1');
        $stmt->execute([$deviceCode]);
        return $stmt->rowCount();
    }

    public static function clearResolved(string $deviceCode): int
    {
        $stmt = Db::pdo()->prepare('DELETE FROM `alerts` WHERE device_code = ? AND is_active = 0');
        $stmt->execute([$deviceCode]);
        return $stmt->rowCount();
    }

    private static function map(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'key'          => $r['alert_key'],
            'severity'     => $r['severity'],
            'message'      => $r['message'],
            'value'        => $r['value'] === null ? null : (float) $r['value'],
            'threshold'    => $r['threshold'] === null ? null : (float) $r['threshold'],
            'active'       => (bool) $r['is_active'],
            'acknowledged' => (bool) $r['acknowledged'],
            'triggered_at' => self::iso($r['triggered_at']),
            'resolved_at'  => self::iso($r['resolved_at']),
        ];
    }

    private static function iso(?string $dt): ?string
    {
        if (!$dt) {
            return null;
        }
        $ts = strtotime($dt);
        return $ts ? date('c', $ts) : null;
    }
}
