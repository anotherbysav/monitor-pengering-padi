<?php
/**
 * Penyimpanan & pembacaan data sensor.
 */

declare(strict_types=1);

namespace App;

final class Readings
{
    public static function insert(array $r, string $deviceCode): int
    {
        $recordedAt = $r['recorded_at'] ?? date('Y-m-d H:i:s');
        $temp    = self::clamp((float) ($r['temp_c'] ?? 0), -50, 300);
        $moist   = self::clamp((float) ($r['moisture_pct'] ?? 0), 0, 100);
        $ambT    = isset($r['ambient_temp_c']) ? self::clamp((float) $r['ambient_temp_c'], -50, 300) : null;
        $ambRh   = isset($r['ambient_rh_pct']) ? self::clamp((float) $r['ambient_rh_pct'], 0, 100) : null;

        $stmt = Db::pdo()->prepare(
            'INSERT INTO `readings`
               (device_code, recorded_at, temp_c, moisture_pct, ambient_temp_c, ambient_rh_pct,
                heater_on, heater_duty, fan_on, fan_duty, source, raw_payload)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                temp_c = VALUES(temp_c), moisture_pct = VALUES(moisture_pct),
                ambient_temp_c = VALUES(ambient_temp_c), ambient_rh_pct = VALUES(ambient_rh_pct),
                heater_on = VALUES(heater_on), heater_duty = VALUES(heater_duty),
                fan_on = VALUES(fan_on), fan_duty = VALUES(fan_duty),
                source = VALUES(source), raw_payload = VALUES(raw_payload)'
        );

        $stmt->execute([
            $deviceCode,
            $recordedAt,
            $temp,
            $moist,
            $ambT,
            $ambRh,
            !empty($r['heater_on']) ? 1 : 0,
            (int) ($r['heater_duty'] ?? 0),
            !empty($r['fan_on']) ? 1 : 0,
            (int) ($r['fan_duty'] ?? 0),
            substr((string) ($r['source'] ?? 'device'), 0, 30),
            isset($r['raw']) ? json_encode($r['raw'], JSON_UNESCAPED_UNICODE) : null,
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    public static function latest(string $deviceCode): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM `readings` WHERE device_code = ? ORDER BY recorded_at DESC, id DESC LIMIT 1');
        $stmt->execute([$deviceCode]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Riwayat dengan downsample otomatis supaya payload tidak terlalu besar.
     */
    public static function history(string $deviceCode, int $minutes, int $limit, string $interval = 'auto'): array
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $minutes) * 60);
        $limit = max(10, min(5000, $limit));
        $db    = Db::pdo();

        $countStmt = $db->prepare('SELECT COUNT(*) FROM `readings` WHERE device_code = ? AND recorded_at >= ?');
        $countStmt->execute([$deviceCode, $since]);
        $count = (int) $countStmt->fetchColumn();

        // tentukan bucket downsample
        $step = match ($interval) {
            'raw'  => 0,
            '1m'   => 60,
            '5m'   => 300,
            '15m'  => 900,
            '1h'   => 3600,
            default => self::autoStep($count, $limit),
        };

        if ($step <= 0) {
            $sql = 'SELECT * FROM `readings` WHERE device_code = ? AND recorded_at >= ? ORDER BY recorded_at ASC LIMIT ' . $limit;
            $stmt = $db->prepare($sql);
            $stmt->execute([$deviceCode, $since]);
            $rows = $stmt->fetchAll();
            return ['rows' => self::format($rows), 'step_seconds' => 0, 'total' => $count];
        }

        $sql = "SELECT MIN(id) AS id, MIN(recorded_at) AS recorded_at,
                       AVG(temp_c) AS temp_c, AVG(moisture_pct) AS moisture_pct,
                       MIN(temp_c) AS temp_min, MAX(temp_c) AS temp_max,
                       AVG(ambient_temp_c) AS ambient_temp_c, AVG(ambient_rh_pct) AS ambient_rh_pct,
                       MAX(heater_on) AS heater_on, MAX(fan_on) AS fan_on,
                       AVG(heater_duty) AS heater_duty, AVG(fan_duty) AS fan_duty
                FROM `readings`
                WHERE device_code = ? AND recorded_at >= ?
                GROUP BY FLOOR(UNIX_TIMESTAMP(recorded_at) / ?)
                ORDER BY recorded_at ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$deviceCode, $since, $step]);
        $rows = $stmt->fetchAll();

        return ['rows' => self::format($rows), 'step_seconds' => $step, 'total' => $count];
    }

    private static function autoStep(int $count, int $limit): int
    {
        if ($count <= $limit) {
            return 0;
        }
        foreach ([5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200] as $candidate) {
            if (ceil($count / $candidate) <= $limit) {
                return $candidate;
            }
        }
        return 7200;
    }

    /** Statistik ringkas untuk kartu ringkasan. */
    public static function stats(string $deviceCode, int $minutes): array
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $minutes) * 60);
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) AS total,
                    AVG(temp_c) AS avg_temp, MIN(temp_c) AS min_temp, MAX(temp_c) AS max_temp,
                    AVG(moisture_pct) AS avg_moist, MIN(moisture_pct) AS min_moist, MAX(moisture_pct) AS max_moist,
                    SUM(heater_on) AS heater_samples, SUM(fan_on) AS fan_samples,
                    AVG(ambient_rh_pct) AS avg_rh
             FROM `readings` WHERE device_code = ? AND recorded_at >= ?'
        );
        $stmt->execute([$deviceCode, $since]);
        $row = $stmt->fetch() ?: [];

        $total = (int) ($row['total'] ?? 0);

        // laju penurunan kelembapan riil (%/jam)
        $rate = null;
        if ($total >= 2) {
            $firstStmt = Db::pdo()->prepare('SELECT recorded_at, moisture_pct FROM `readings` WHERE device_code = ? AND recorded_at >= ? ORDER BY recorded_at ASC LIMIT 1');
            $firstStmt->execute([$deviceCode, $since]);
            $first = $firstStmt->fetch();

            if ($first) {
                $hours = (strtotime(self::lastTime($deviceCode, $since)) - strtotime((string) $first['recorded_at'])) / 3600;
                if ($hours > 0.01) {
                    $lastM = self::lastMoisture($deviceCode, $since);
                    $rate  = round(((float) $first['moisture_pct'] - $lastM) / $hours, 3);
                }
            }
        }

        return [
            'window_minutes' => $minutes,
            'samples'        => $total,
            'temp'  => ['avg' => self::r($row['avg_temp'] ?? null), 'min' => self::r($row['min_temp'] ?? null), 'max' => self::r($row['max_temp'] ?? null)],
            'moist' => ['avg' => self::r($row['avg_moist'] ?? null), 'min' => self::r($row['min_moist'] ?? null), 'max' => self::r($row['max_moist'] ?? null)],
            'ambient_rh_avg' => self::r($row['avg_rh'] ?? null),
            'heater_on_pct'  => $total ? round(((int) $row['heater_samples']) / $total * 100, 1) : 0.0,
            'fan_on_pct'     => $total ? round(((int) $row['fan_samples']) / $total * 100, 1) : 0.0,
            'observed_dry_rate' => $rate,
        ];
    }

    private static function lastTime(string $deviceCode, string $since): string
    {
        $stmt = Db::pdo()->prepare('SELECT recorded_at FROM `readings` WHERE device_code = ? AND recorded_at >= ? ORDER BY recorded_at DESC LIMIT 1');
        $stmt->execute([$deviceCode, $since]);
        return (string) ($stmt->fetchColumn() ?: $since);
    }

    private static function lastMoisture(string $deviceCode, string $since): float
    {
        $stmt = Db::pdo()->prepare('SELECT moisture_pct FROM `readings` WHERE device_code = ? AND recorded_at >= ? ORDER BY recorded_at DESC LIMIT 1');
        $stmt->execute([$deviceCode, $since]);
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    /** Laju penurunan moisture dari blok data terakhir (untuk displayed trend). */
    public static function trend(string $deviceCode, int $minutes = 30): array
    {
        $since = date('Y-m-d H:i:s', time() - max(2, $minutes) * 60);
        $stmt = Db::pdo()->prepare(
            'SELECT recorded_at, temp_c, moisture_pct FROM `readings`
             WHERE device_code = ? AND recorded_at >= ? ORDER BY recorded_at ASC'
        );
        $stmt->execute([$deviceCode, $since]);
        $rows = $stmt->fetchAll();

        if (count($rows) < 2) {
            return ['temp_per_min' => 0.0, 'moist_per_hour' => null, 'span_minutes' => 0];
        }

        $first = $rows[0];
        $last  = $rows[count($rows) - 1];
        $mins  = max(0.1, (strtotime((string) $last['recorded_at']) - strtotime((string) $first['recorded_at'])) / 60);

        return [
            'temp_per_min'   => round(((float) $last['temp_c'] - (float) $first['temp_c']) / $mins, 3),
            'moist_per_hour' => round(((float) $first['moisture_pct'] - (float) $last['moisture_pct']) / $mins * 60, 3),
            'span_minutes'   => round($mins, 1),
        ];
    }

    public static function purgeOlderThan(int $days): int
    {
        $stmt = Db::pdo()->prepare('DELETE FROM `readings` WHERE recorded_at < ?');
        $stmt->execute([date('Y-m-d H:i:s', time() - max(1, $days) * 86400)]);
        $deleted = $stmt->rowCount();

        foreach (['actuator_logs', 'drying_logs'] as $table) {
            $s = Db::pdo()->prepare("DELETE FROM `$table` WHERE created_at < ?");
            $s->execute([date('Y-m-d H:i:s', time() - max(1, $days) * 86400)]);
            $deleted += $s->rowCount();
        }
        $s = Db::pdo()->prepare('DELETE FROM `alerts` WHERE resolved_at IS NOT NULL AND resolved_at < ?');
        $s->execute([date('Y-m-d H:i:s', time() - max(1, $days) * 86400)]);

        return $deleted;
    }

    private static function format(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                't'    => self::iso($r['recorded_at'] ?? null),
                'temp' => self::r($r['temp_c'] ?? null),
                'moist'=> self::r($r['moisture_pct'] ?? null),
                'lo'   => isset($r['temp_min']) ? self::r($r['temp_min']) : null,
                'hi'   => isset($r['temp_max']) ? self::r($r['temp_max']) : null,
                'rh'   => isset($r['ambient_rh_pct']) ? self::r($r['ambient_rh_pct']) : null,
                'amb'  => isset($r['ambient_temp_c']) ? self::r($r['ambient_temp_c']) : null,
                'heater' => (bool) ($r['heater_on'] ?? false),
                'fan'    => (bool) ($r['fan_on'] ?? false),
                'heater_duty' => isset($r['heater_duty']) ? self::r($r['heater_duty']) : null,
                'fan_duty'    => isset($r['fan_duty']) ? self::r($r['fan_duty']) : null,
                'source'      => $r['source'] ?? null,
            ];
        }
        return $out;
    }

    private static function iso(?string $mysqlDatetime): ?string
    {
        if (!$mysqlDatetime) {
            return null;
        }
        $ts = strtotime($mysqlDatetime);
        return $ts ? date('c', $ts) : null;
    }

    private static function r(mixed $v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }
}
