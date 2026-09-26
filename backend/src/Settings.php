<?php
/**
 * Repository setting: baca/tulis konfigurasi runtime + validasi tipe & rentang.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class Settings
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    private const FALLBACK = [
        'device_code'        => 'DRYER-01',
        'device_name'        => 'Pengering Padi Utama',
        'location'           => '',
        'material_profile'   => 'padi',
        'temp_min'           => 32.0,
        'temp_max'           => 45.0,
        'temp_optimal'       => 38.0,
        'temp_limit'         => 52.0,
        'temp_hysteresis'    => 0.8,
        'moisture_target'    => 14.0,
        'moisture_stop'      => 13.5,
        'moisture_initial'   => 26.0,
        'moisture_wet'       => 20.0,
        'moisture_dry'       => 12.0,
        'ambient_rh_default' => 65.0,
        'auto_mode'          => true,
        'heater_manual'      => false,
        'heater_duty'        => 80,
        'fan_manual'         => false,
        'fan_duty'           => 80,
        'safety_max_runtime' => 180,
        'safety_cooldown'    => 10,
        'safety_auto_off'    => true,
        'safety_heater_rate' => 1.2,
        'safety_fault_temp'  => 90.0,
        'poll_interval_ms'   => 3000,
        'offline_timeout'    => 60,
        'stale_reading_min'  => 10,
        'retention_days'     => 30,
        'layer_thickness'    => 1.5,
        'log_interval'       => true,
        'dashboard_title'    => 'Monitor Suhu & Kelembapan Pengering Padi',
    ];

    public static function all(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) {
            return self::$cache;
        }

        $rows = Db::pdo()->query('SELECT * FROM `settings`')->fetchAll();
        $out  = self::FALLBACK;

        foreach ($rows as $row) {
            $out[$row['setting_key']] = self::cast($row['setting_value'], $row['value_type']);
        }

        return self::$cache = $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : ($default ?? self::FALLBACK[$key] ?? null);
    }

    public static function float(string $key): float
    {
        return (float) self::get($key);
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function str(string $key): string
    {
        return (string) self::get($key);
    }

    public static function deviceCode(): string
    {
        $code = self::str('device_code');
        return $code !== '' ? $code : 'DRYER-01';
    }

    /** Metadata setting untuk form di frontend. */
    public static function metadata(): array
    {
        $rows = Db::pdo()->query(
            'SELECT setting_key, value_type, label, unit, group_name, min_value, max_value
             FROM `settings` ORDER BY group_name, setting_key'
        )->fetchAll();

        $values = self::all();
        $out = [];
        foreach ($rows as $row) {
            $row['value'] = $values[$row['setting_key']] ?? null;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Simpan banyak setting sekaligus dengan validasi.
     *
     * Urutan penting: normalisasi -> validasi silang -> baru tulis ke database.
     * Dengan begitu setting yang tidak valid tidak pernah tersimpan.
     *
     * @return array<int,array{key:string,ok:bool,error?:string}>
     */
    public static function save(array $payload): array
    {
        $db  = Db::pdo();
        $sel = $db->prepare('SELECT * FROM `settings` WHERE setting_key = ? LIMIT 1');

        $results = [];
        $prepared = [];
        $errors   = [];

        // 1. normalisasi + validasi rentang tiap key
        foreach ($payload as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || !preg_match('/^[a-z0-9_]{2,60}$/i', $key)) {
                $errors[] = ['key' => $key, 'ok' => false, 'error' => 'Nama setting tidak valid.'];
                continue;
            }

            $sel->execute([$key]);
            $meta = $sel->fetch();
            if (!$meta) {
                $errors[] = ['key' => $key, 'ok' => false, 'error' => 'Setting tidak dikenal.'];
                continue;
            }

            $cast = self::normalize($value, $meta);
            if (isset($cast['error'])) {
                $errors[] = ['key' => $key, 'ok' => false, 'error' => $cast['error']];
                continue;
            }

            $prepared[$key] = ['value' => $cast['value'], 'meta' => $meta];
        }

        // 2. validasi silang (gabungan nilai lama + baru)
        if ($prepared !== []) {
            $pending = self::all(true);
            foreach ($prepared as $key => $p) {
                $pending[$key] = self::cast((string) $p['value'], $p['meta']['value_type']);
            }
            self::validateRelations($pending);
        }

        // 3. tulis
        $ups = $db->prepare(
            'INSERT INTO `settings` (setting_key, setting_value, value_type, label, unit, group_name, min_value, max_value)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        $db->beginTransaction();
        try {
            foreach ($prepared as $key => $p) {
                $m = $p['meta'];
                $ups->execute([$key, $p['value'], $m['value_type'], $m['label'], $m['unit'], $m['group_name'], $m['min_value'], $m['max_value']]);
                $results[] = ['key' => $key, 'ok' => true];
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        self::$cache = null;
        return array_merge($results, $errors);
    }

    /** Validasi silang antar setting (mis. batas bawah < batas atas). */
    public static function validateRelations(?array $pending = null): void
    {
        $s = $pending === null ? self::all(true) : array_merge(self::all(), $pending);

        if ((float) $s['temp_min'] >= (float) $s['temp_max']) {
            throw new \DomainException('Batas bawah suhu harus lebih kecil dari batas atas suhu.');
        }
        if ((float) $s['temp_max'] > (float) $s['temp_limit']) {
            throw new \DomainException('Batas atas suhu tidak boleh melebihi batas keras (auto matikan).');
        }
        if ((float) $s['temp_optimal'] < (float) $s['temp_min'] || (float) $s['temp_optimal'] > (float) $s['temp_max']) {
            throw new \DomainException('Suhu optimal harus berada di antara batas bawah dan batas atas.');
        }
        if ((float) $s['moisture_stop'] > (float) $s['moisture_target']) {
            throw new \DomainException('Kelembapan berhenti harus lebih kecil atau sama dengan kelembapan target.');
        }
        if ((float) $s['moisture_initial'] < (float) $s['moisture_target']) {
            throw new \DomainException('Kelembapan awal tidak boleh lebih kecil dari kelembapan target.');
        }
    }

    private static function normalize(mixed $value, array $meta): array
    {
        $type  = $meta['value_type'];
        $key   = $meta['setting_key'];
        $label = (string) $meta['label'];

        if ($type === 'bool') {
            $b = in_array(strtolower(trim((string) (is_bool($value) ? ($value ? '1' : '0') : $value))),
                ['1', 'true', 'on', 'yes', 'aktif', 'nyala'], true);
            return ['value' => $b ? '1' : '0'];
        }

        if ($type === 'int' || $type === 'float') {
            if (!is_numeric($value)) {
                return ['error' => "$label harus berupa angka."];
            }
            $num = $type === 'int' ? (int) $value : round((float) $value, 3);
            if ($meta['min_value'] !== null && $num < (float) $meta['min_value']) {
                return ['error' => "$label minimal " . rtrim(rtrim((string) $meta['min_value'], '0'), '.') . " {$meta['unit']}"];
            }
            if ($meta['max_value'] !== null && $num > (float) $meta['max_value']) {
                return ['error' => "$label maksimal " . rtrim(rtrim((string) $meta['max_value'], '0'), '.') . " {$meta['unit']}"];
            }
            return ['value' => (string) $num];
        }

        if ($type === 'json') {
            if (!is_array($value)) {
                return ['error' => "$label harus berupa JSON object."];
            }
            return ['value' => json_encode($value, JSON_UNESCAPED_UNICODE)];
        }

        $str = trim((string) $value);
        if ($key === 'device_code' && !preg_match('/^[A-Za-z0-9_-]{3,40}$/', $str)) {
            return ['error' => 'Kode perangkat hanya boleh huruf, angka, tanda hubung, dan underscore (3-40 karakter).'];
        }
        return ['value' => mb_substr($str, 0, 200)];
    }

    private static function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'json'  => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
